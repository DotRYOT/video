<?php
/**
 * Thumbnail diagnostics for TempVid.
 *
 * Open thumbnail-check.php in your browser (or run it from the CLI) to verify
 * that PHP can find and execute ffmpeg/ffprobe on your Ubuntu server, and that
 * thumbnails can actually be generated.
 *
 * NOTE: This exposes environment details. Delete it or restrict access after
 * troubleshooting (e.g., add "RewriteRule ^thumbnail-check\.php$ - [F,L]" to
 * .htaccess once everything works).
 */

require_once __DIR__ . '/thumbnail-common.php';

header('Content-Type: text/plain; charset=utf-8');

$out = [];
$out[] = '=== TempVid thumbnail diagnostics ===';
$out[] = 'PHP version       : ' . PHP_VERSION;
$out[] = 'PHP SAPI          : ' . php_sapi_name();
$out[] = 'whoami            : ' . trim((string) @shell_exec('id 2>/dev/null'));
$out[] = 'PATH              : ' . (getenv('PATH') ?: '(empty)');

foreach (['ffmpeg', 'ffprobe'] as $bin) {
  $path = findBinary($bin);
  $out[] = sprintf('%-16s: %s', $bin, $path ?? 'NOT FOUND -> install with: sudo apt-get install ffmpeg');
  if ($path !== null) {
    $res = runCommand(escapeshellarg($path) . ' -version 2>&1');
    $first = $res['stdout'] !== '' ? strtok($res['stdout'], "\n") : '(no output)';
    $out[] = '  version check   : ' . ($res['code'] === 0 ? 'OK' : 'FAILED (exit ' . var_export($res['code'], true) . ')');
    $out[] = '  ' . $first;
  }
}

// Disabled functions often break exec/shell_exec/proc_open under Apache on Ubuntu
$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
$blocked = array_values(array_intersect(['exec', 'shell_exec', 'proc_open', 'passthru'], $disabled));
$out[] = 'Blocked funcs     : ' . ($blocked ? implode(', ', $blocked) . '  <-- fix php.ini!' : 'none');

// Try generating a thumbnail for the first non-expired video we find
$metaDir = __DIR__ . '/meta/';
$uploadDir = __DIR__ . '/uploads/';
$thumbDir = __DIR__ . '/thumbs/';
$tested = false;
foreach (glob($metaDir . '*.json') ?: [] as $mf) {
  $meta = json_decode((string) file_get_contents($mf), true);
  if (!is_array($meta) || empty($meta['stored_name'])) continue;
  if ((int) ($meta['expires_at'] ?? 0) < time()) continue;
  $videoPath = $uploadDir . basename($meta['stored_name']);
  if (!is_file($videoPath)) continue;

  $id = $meta['id'] ?? basename($mf, '.json');
  $tmpOut = $thumbDir . 'check-' . $id . '.jpg';
  $dur = getVideoDuration($videoPath);
  $ok = generateThumbnail($videoPath, $tmpOut, $dur > 0 ? $dur : null);
  $size = is_file($tmpOut) ? filesize($tmpOut) : 0;
  $out[] = '';
  $out[] = 'Test video        : ' . $id . ' (' . basename($videoPath) . ')';
  $out[] = 'Probed duration   : ' . ($dur > 0 ? $dur . 's' : 'unknown');
  $out[] = 'Generate result   : ' . ($ok && $size > 0 ? "OK ({$size} bytes)" : 'FAILED');
  if ($size > 0) @unlink($tmpOut);
  $tested = true;
  break;
}
if (!$tested) {
  $out[] = '';
  $out[] = 'No active videos found to test generation against (upload one and re-run).';
}

echo implode("\n", $out) . "\n";
