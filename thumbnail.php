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

require_once __DIR__ . '/thumbnail-common.php';

$metaDir = __DIR__ . '/meta/';
$uploadDir = __DIR__ . '/uploads/';
$thumbDir = __DIR__ . '/thumbs/';

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

// Reuse cached thumbnail if it exists and is newer than the video.
// Also honor a per-request percentage override (?t=) by regenerating when
// the requested timestamp differs from the one used for the cached frame.
$needGenerate = !is_file($thumbPath) || filemtime($thumbPath) < filemtime($videoPath);
if (!$needGenerate) {
  $cachedPercent = isset($meta['thumb_percent']) ? (float) $meta['thumb_percent'] : THUMB_PERCENT;
  if (isset($_GET['t']) && is_numeric($_GET['t']) && abs($cachedPercent - $percent) > 0.001) {
    $needGenerate = true;
  }
}

if ($needGenerate) {
  $duration = isset($meta['duration']) && is_numeric($meta['duration']) && (float) $meta['duration'] > 0
    ? (float) $meta['duration']
    : null; // null => generateThumbnail probes with ffprobe itself
  generateThumbnail($videoPath, $thumbPath, $duration, $percent);
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
