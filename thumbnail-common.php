<?php
/**
 * Shared thumbnail helpers for TempVid.
 *
 * Included by both upload.php (eager generation right after upload) and
 * thumbnail.php (lazy generation on request). Centralizes the ffmpeg/ffprobe
 * path resolution and frame-grabbing logic so the two entry points behave
 * identically on Ubuntu and other Linux distributions.
 */

// Percentage of total play time at which to grab the thumbnail frame.
// Using a percentage (instead of a fixed timestamp) means short videos
// still get a representative frame rather than a black/blank opening.
if (!defined('THUMB_PERCENT')) {
  define('THUMB_PERCENT', 20);
}

// Max width of generated thumbnails in pixels (height scales proportionally)
if (!defined('THUMB_WIDTH')) {
  define('THUMB_WIDTH', 1280);
}

/**
 * Locate an executable binary.
 *
 * Checks the usual Ubuntu install locations first, then PATH via `which`,
 * then common alternative locations. This makes the feature work even when
 * PHP's inherited PATH is minimal (a frequent problem under systemd/Apache
 * on Ubuntu, where /usr/bin may be missing from the environment).
 *
 * Returns the absolute path on success, or null if not found/not runnable.
 */
function findBinary(string $name): ?string
{
  static $cache = [];
  $key = strtolower($name);
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  // Strip any directory component so callers can pass '/usr/bin/ffmpeg' too
  $base = basename($name);
  $candidates = [
    '/usr/bin/' . $base,          // standard Ubuntu apt location
    '/bin/' . $base,              // usrmerge-disabled systems
    '/usr/local/bin/' . $base,    // compiled-from-source / snap-style installs
    '/snap/bin/' . $base,         // snap-installed ffmpeg builds
  ];

  foreach ($candidates as $path) {
    if (@is_executable($path)) {
      return $cache[$key] = $path;
    }
  }

  // Fall back to PATH lookup (`which` may itself need an absolute path)
  $out = @shell_exec('/usr/bin/which ' . escapeshellarg($base) . ' 2>/dev/null');
  if ($out === null) {
    $out = @shell_exec('which ' . escapeshellarg($base) . ' 2>/dev/null');
  }
  $found = trim((string) $out);
  if ($found !== '' && @is_executable($found)) {
    return $cache[$key] = $found;
  }

  return $cache[$key] = null;
}

/**
 * Run a command, capturing stdout AND stderr separately.
 *
 * shell_exec() merges everything into one string, which corrupts structured
 * output like ffprobe's JSON. proc_open() keeps the two streams apart and
 * does not rely on exec()/shell_exec() being enabled in php.ini.
 *
 * @return array{stdout:string, stderr:string, code:int|null} code is null if
 *                                                             the process could not be started.
 */
function runCommand(string $cmd): array
{
  $descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];

  $proc = @proc_open($cmd, $descriptors, $pipes);
  if (!is_resource($proc)) {
    return ['stdout' => '', 'stderr' => '', 'code' => null];
  }

  fclose($pipes[0]);
  $stdout = (string) stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  $stderr = (string) stream_get_contents($pipes[2]);
  fclose($pipes[2]);
  $code = @proc_close($proc);

  return ['stdout' => $stdout, 'stderr' => $stderr, 'code' => $code];
}

/**
 * Get video duration in seconds using ffprobe (falls back to parsing
 * `ffmpeg -i` stderr if ffprobe is unavailable). Returns 0.0 on failure.
 */
function getVideoDuration(string $videoPath): float
{
  $ffprobe = findBinary('ffprobe');
  if ($ffprobe !== null) {
    $cmd = sprintf(
      '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
      escapeshellarg($ffprobe),
      escapeshellarg($videoPath)
    );
    $res = runCommand($cmd);
    $val = trim($res['stdout']);
    if ($res['code'] === 0 && $val !== '' && is_numeric($val)) {
      return max(0.0, (float) $val);
    }
  }

  // Fallback: parse "Duration: HH:MM:SS.xx" from ffmpeg's stderr banner
  $ffmpeg = findBinary('ffmpeg');
  if ($ffmpeg === null) {
    return 0.0;
  }
  $cmd = sprintf('%s -i %s 2>&1', escapeshellarg($ffmpeg), escapeshellarg($videoPath));
  $res = runCommand($cmd);
  if ($res['stdout'] !== '' && preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $res['stdout'], $m)) {
    return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (float) $m[3];
  }

  return 0.0;
}

/**
 * Grab a single JPEG frame from the video at $percent of its total duration
 * and write it atomically to $thumbPath. Returns true on success.
 *
 * Writes to a temp file first and renames on success, so concurrent readers
 * never see a half-written image. Uses output-side seeking semantics that
 * work across all supported containers (mp4/webm/mov/avi/mkv).
 */
function generateThumbnail(string $videoPath, string $thumbPath, ?float $duration = null, float $percent = THUMB_PERCENT): bool
{
  $ffmpeg = findBinary('ffmpeg');
  if ($ffmpeg === null) {
    error_log('TempVid thumbnail: ffmpeg not found. Install it on Ubuntu with: sudo apt-get install ffmpeg');
    return false;
  }

  if ($duration === null || $duration <= 0) {
    $duration = getVideoDuration($videoPath);
  }

  // Frame time = percentage of play time; clamp away from the very ends.
  // Unknown/very short videos fall back to 1 second in.
  if ($duration > 0) {
    $timestamp = max(0.1, min($duration * ($percent / 100.0), max(0.1, $duration - 0.1)));
  } else {
    $timestamp = 1.0;
  }

  $dir = dirname($thumbPath);
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }

  $tmpPath = $dir . '/' . bin2hex(random_bytes(6)) . '.tmp.jpg';

  // Note: `-ss` before `-i` seeks fast (keyframe-based) which is fine for a
  // thumbnail; `-frames:v 1` grabs exactly one frame; mjpeg quality q:v 3.
  $cmd = sprintf(
    '%s -hide_banner -loglevel error -ss %.3f -i %s -frames:v 1 -update 1 -vf %s -q:v 3 -y %s',
    escapeshellarg($ffmpeg),
    $timestamp,
    escapeshellarg($videoPath),
    escapeshellarg(sprintf('scale=%d:-2', THUMB_WIDTH)),
    escapeshellarg($tmpPath)
  );

  $res = runCommand($cmd);

  if ($res['code'] !== 0 || !is_file($tmpPath) || filesize($tmpPath) === 0) {
    if ($res['code'] !== 0) {
      error_log('TempVid thumbnail: ffmpeg failed (exit ' . var_export($res['code'], true) . '): ' . trim($res['stderr']));
    }
    @unlink($tmpPath);
    return false;
  }

  if (!@rename($tmpPath, $thumbPath)) {
    @unlink($tmpPath);
    error_log('TempVid thumbnail: could not move generated thumbnail into place: ' . $thumbPath);
    return false;
  }
  @chmod($thumbPath, 0644);
  return true;
}
