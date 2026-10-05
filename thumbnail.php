<?php
/**
 * Thumbnail service for TempVid.
 *
 * Serves a JPEG frame grabbed from an uploaded video, used as the page's
 * og:image / twitter:image so link embeds (Discord, etc.) show a preview.
 *
 * The frame is taken at a percentage of the video's total play time
 * (default 20%) so short videos still get a meaningful thumbnail instead
 * of a black first frame. Frames are cached on disk and cleaned up together
 * with expired videos by cleanup.php.
 *
 * Usage: thumbnail.php?id=<32-hex-video-id>[&t=20]
 */

$metaDir = __DIR__ . '/meta/';
$uploadDir = __DIR__ . '/uploads/';
$thumbDir = __DIR__ . '/thumbs/';

// Percentage of the video duration at which to grab the frame
const THUMB_PERCENT = 20;
const THUMB_WIDTH = 1280;

function thumb_fail(): void
{
  http_response_code(404);
  header('Content-Type: image/svg+xml');
  // Minimal placeholder so embedders never receive a broken image URL
  echo '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720"><rect width="100%" height="100%" fill="#0a0a0f"/></svg>';
  exit;
}

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
  thumb_fail();
}

// Allow overriding the percentage per request (?t=15), clamped to sane values
$percent = isset($_GET['t']) && is_numeric($_GET['t']) ? (float) $_GET['t'] : THUMB_PERCENT;
$percent = max(1, min(95, $percent));

$metaFile = $metaDir . $id . '.json';
if (!is_file($metaFile)) {
  thumb_fail();
}

$meta = json_decode((string) file_get_contents($metaFile), true);
if (!is_array($meta) || empty($meta['stored_name'])) {
  thumb_fail();
}

// Respect expiry
$expiresAt = is_numeric($meta['expires_at'] ?? null) ? (int) $meta['expires_at'] : strtotime((string) ($meta['expires_at'] ?? 0));
if ($expiresAt < time()) {
  thumb_fail();
}

$videoPath = $uploadDir . basename($meta['stored_name']);
if (!is_file($videoPath)) {
  thumb_fail();
}

if (!is_dir($thumbDir)) {
  @mkdir($thumbDir, 0755, true);
}

$thumbPath = $thumbDir . $id . '.jpg';

// Reuse cached thumbnail if it exists and is newer than the video
if (!is_file($thumbPath) || filemtime($thumbPath) < filemtime($videoPath)) {
  generateThumbnail($videoPath, $thumbPath, $percent);
  // On failure we fall through: a stale cached thumb is served if present,
  // otherwise the placeholder below keeps embedders from seeing a broken URL.
}

if (is_file($thumbPath)) {
  header('Content-Type: image/jpeg');
  header('Content-Length: ' . filesize($thumbPath));
  header('Cache-Control: public, max-age=86400');
  header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($thumbPath)) . ' GMT');
  readfile($thumbPath);
  exit;
}

thumb_fail();

/**
 * Grab a single frame from the video at $percent of its total duration.
 */
function generateThumbnail(string $videoPath, string $thumbPath, float $percent): bool
{
  $duration = getVideoDuration($videoPath);
  // For very short (or unknown-duration) videos, fall back to 1 second in
  $timestamp = $duration > 0 ? ($duration * ($percent / 100)) : 1.0;
  if ($duration > 0) {
    $timestamp = max(0.1, min($timestamp, max(0.1, $duration - 0.1)));
  }

  $ffmpeg = trim((string) @shell_exec('which ffmpeg 2>/dev/null'));
  if ($ffmpeg === '') {
    $ffmpeg = '/usr/bin/ffmpeg';
  }

  $tmpPath = $thumbPath . '.' . getmypid() . '.tmp.jpg';
  $cmd = sprintf(
    '%s -hide_banner -loglevel error -ss %.3f -i %s -frames:v 1 -update 1 -vf "scale=%d:-2" -q:v 3 -y %s 2>/dev/null',
    escapeshellcmd($ffmpeg),
    $timestamp,
    escapeshellarg($videoPath),
    THUMB_WIDTH,
    escapeshellarg($tmpPath)
  );

  @exec($cmd, $out, $retCode);

  if ($retCode === 0 && is_file($tmpPath) && filesize($tmpPath) > 0) {
    if (@rename($tmpPath, $thumbPath)) {
      @chmod($thumbPath, 0644);
      return true;
    }
    @unlink($tmpPath);
  }
  @unlink($tmpPath);
  return false;
}

/**
 * Get video duration in seconds using ffprobe (falls back to parsing
 * ffmpeg output if ffprobe is unavailable). Returns 0 on failure.
 */
function getVideoDuration(string $videoPath): float
{
  $ffprobe = trim((string) @shell_exec('which ffprobe 2>/dev/null'));
  if ($ffprobe !== '') {
    $cmd = sprintf(
      '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
      escapeshellcmd($ffprobe),
      escapeshellarg($videoPath)
    );
    $out = @shell_exec($cmd);
    if ($out !== null && is_numeric(trim($out))) {
      return max(0.0, (float) trim($out));
    }
  }

  // Fallback: parse Duration from `ffmpeg -i` stderr
  $cmd = sprintf('%s -i %s 2>&1', escapeshellcmd('/usr/bin/ffmpeg'), escapeshellarg($videoPath));
  $out = @shell_exec($cmd);
  if ($out && preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $out, $m)) {
    return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (float) $m[3];
  }

  return 0.0;
}
