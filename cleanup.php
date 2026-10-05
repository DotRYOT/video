<?php
/**
 * Cleanup expired video files and metadata.
 * Called automatically on page loads to purge files older than 24 hours.
 */
function cleanExpiredFiles()
{
  $metaDir = __DIR__ . '/meta/';
  $uploadDir = __DIR__ . '/uploads/';

  // Ensure directories exist
  if (!is_dir($uploadDir))
    mkdir($uploadDir, 0755, true);
  if (!is_dir($metaDir))
    mkdir($metaDir, 0755, true);

  // Scan meta files
  $metaFiles = glob($metaDir . '*.json');
  if (!$metaFiles)
    return;

  $now = time();

  foreach ($metaFiles as $metaFile) {
    $data = json_decode(file_get_contents($metaFile), true);
    if (!$data || !isset($data['expires_at'])) {
      // Invalid metadata, remove it
      @unlink($metaFile);
      continue;
    }

    $expiryTs = is_numeric($data['expires_at']) ? (int) $data['expires_at'] : strtotime($data['expires_at']);
    if ($expiryTs < $now) {
      // Expired - delete video file and metadata
      $videoPath = $uploadDir . ($data['stored_name'] ?? '');
      if (!empty($data['stored_name']) && file_exists($videoPath)) {
        @unlink($videoPath);
      }
      @unlink($metaFile);
    }
  }
}
