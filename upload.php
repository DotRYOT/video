<?php
session_start();
header('Content-Type: application/json');

require_once 'config.php';
require_once 'cleanup.php';

function phpBytesToInt(string $value): int {
  $value = trim($value);
  if ($value === '') {
    return 0;
  }

  $unit = strtolower(substr($value, -1));
  $number = (int) substr($value, 0, -1);

  switch ($unit) {
    case 'k': return $number * 1024;
    case 'm': return $number * 1024 * 1024;
    case 'g': return $number * 1024 * 1024 * 1024;
    default: return (int) $value;
  }
}

function getPhpUploadConfig(): array {
  return [
    'loaded_ini' => php_ini_loaded_file() ?: 'none',
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
    'file_uploads' => ini_get('file_uploads'),
    'max_file_uploads' => ini_get('max_file_uploads'),
    'upload_max_filesize_bytes' => phpBytesToInt(ini_get('upload_max_filesize')),
    'post_max_size_bytes' => phpBytesToInt(ini_get('post_max_size')),
  ];
}

function logUploadFailure(string $stage, string $message, array $context = []): void {
  $entry = [
    'timestamp' => gmdate('c'),
    'stage' => $stage,
    'message' => $message,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    'php_config' => getPhpUploadConfig(),
    'files' => $_FILES,
    'post' => $_POST,
    'context' => $context,
  ];

  $logLine = json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  error_log('Video upload failure [' . $stage . ']: ' . $message);
  error_log($logLine);

  $logPath = __DIR__ . '/meta/upload-errors.log';
  @file_put_contents($logPath, $logLine, FILE_APPEND | LOCK_EX);
}

cleanExpiredFiles();

// Auth check - must be logged in via session
if (empty($_SESSION['authenticated'])) {
  logUploadFailure('auth', 'Unauthorized upload attempt.', ['session' => $_SESSION ?? []]);
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Unauthorized. Please enter your access key first.']);
  exit;
}

$uploadDir = __DIR__ . '/uploads/';
$metaDir = __DIR__ . '/meta/';

// Ensure directories exist
if (!is_dir($uploadDir))
  mkdir($uploadDir, 0755, true);
if (!is_dir($metaDir))
  mkdir($metaDir, 0755, true);

// Validate request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  logUploadFailure('request_validation', 'Rejected non-POST upload request.', ['method' => $_SERVER['REQUEST_METHOD'] ?? null]);
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
  $errorMessages = [
    UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
    UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
    UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error.',
    UPLOAD_ERR_CANT_WRITE => 'Failed to write file.',
    UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
  ];
  $code = isset($_FILES['video']) ? $_FILES['video']['error'] : UPLOAD_ERR_NO_FILE;
  $msg = $errorMessages[$code] ?? 'Upload failed.';
  $requestSize = null;
  if (isset($_SERVER['CONTENT_LENGTH']) && is_numeric($_SERVER['CONTENT_LENGTH'])) {
    $requestSize = (int) $_SERVER['CONTENT_LENGTH'];
  }

  $details = [
    'upload_error_code' => $code,
    'file' => $_FILES['video'] ?? null,
    'request_content_length' => $requestSize,
    'php_limits' => getPhpUploadConfig(),
    'php_upload_err' => error_get_last(),
  ];

  if ($code === UPLOAD_ERR_INI_SIZE) {
    $details['hint'] = 'Increase upload_max_filesize and post_max_size in php.ini or the web server PHP config.';
  }

  logUploadFailure('upload_error', $msg, $details);
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $msg]);
  exit;
}

$file = $_FILES['video'];

if ($file['size'] > MAX_FILE_SIZE) {
  logUploadFailure('file_size', 'Upload rejected because the file exceeds the configured maximum size.', [
    'size' => $file['size'] ?? 0,
    'max_size' => MAX_FILE_SIZE,
    'file_name' => $file['name'] ?? null,
    'php_limits' => getPhpUploadConfig(),
  ]);
  http_response_code(413);
  echo json_encode(['success' => false, 'error' => 'File is too large. Maximum size is 500MB.']);
  exit;
}

// Validate file type
$allowedMimes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];
$allowedExts = ['mp4', 'webm', 'mov', 'avi', 'mkv'];

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
if ($finfo) {
  finfo_close($finfo);
}

if (!in_array($ext, $allowedExts)) {
  logUploadFailure('file_type', 'Upload rejected because the file extension is not allowed.', ['file_name' => $file['name'] ?? null, 'extension' => $ext, 'mime_type' => $mimeType, 'allowed_exts' => $allowedExts]);
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: MP4, WEBM, MOV, AVI, MKV.']);
  exit;
}

if ($mimeType === false || !in_array($mimeType, $allowedMimes, true)) {
  logUploadFailure('mime_type', 'Upload rejected because the detected MIME type is not allowed.', ['file_name' => $file['name'] ?? null, 'extension' => $ext, 'mime_type' => $mimeType, 'allowed_mimes' => $allowedMimes]);
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: MP4, WEBM, MOV, AVI, MKV.']);
  exit;
}

// Resolve expiry tier
$tiers = EXPIRY_TIERS;
$tierKey = isset($_POST['expiry']) ? trim($_POST['expiry']) : '24h';
if (!array_key_exists($tierKey, $tiers)) {
  logUploadFailure('expiry_validation', 'Upload rejected because the expiry option is invalid.', ['expiry' => $_POST['expiry'] ?? null, 'allowed_tiers' => array_keys($tiers)]);
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Invalid expiry option.']);
  exit;
}
$tier = $tiers[$tierKey];

// Enforce per-tier video limit
if ($tier['limit'] !== null) {
  $now = time();
  $activeCount = 0;
  $metaFiles = glob($metaDir . '*.json') ?: [];
  foreach ($metaFiles as $mf) {
    $md = json_decode(file_get_contents($mf), true);
    if (!$md) continue;
    $exp = isset($md['expires_at']) ? (int) $md['expires_at'] : 0;
    if ($exp > $now && isset($md['expiry_tier']) && $md['expiry_tier'] === $tierKey) {
      $activeCount++;
    }
  }
  if ($activeCount >= $tier['limit']) {
    logUploadFailure('tier_limit', 'Upload rejected because the expiry tier has reached its upload limit.', ['tier_key' => $tierKey, 'tier_label' => $tier['label'] ?? null, 'limit' => $tier['limit'], 'active_count' => $activeCount]);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'The "' . $tier['label'] . '" tier is full (' . $tier['limit'] . ' videos max). Choose a shorter expiry.']);
    exit;
  }
}

// Generate unique ID
$id = bin2hex(random_bytes(16));
$safeOrigName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
$storedName = $id . '.' . $ext;
$destPath = $uploadDir . $storedName;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
  logUploadFailure('move_upload', 'move_uploaded_file() failed while saving the uploaded video.', ['tmp_name' => $file['tmp_name'] ?? null, 'dest_path' => $destPath, 'is_uploaded_file' => isset($file['tmp_name']) ? is_uploaded_file($file['tmp_name']) : false, 'php_last_error' => error_get_last()]);
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to save the video. Please try again.']);
  exit;
}

// Save metadata
$expiresAtTs = time() + $tier['seconds'];
$meta = [
  'id' => $id,
  'original_name' => $safeOrigName,
  'stored_name' => $storedName,
  'mime_type' => $mimeType,
  'extension' => $ext,
  'size' => $file['size'],
  'uploaded_at' => time(),
  'expires_at' => $expiresAtTs,
  'expiry_tier' => $tierKey,
];

$metaPath = $metaDir . $id . '.json';
$metaJson = json_encode($meta, JSON_PRETTY_PRINT);
if ($metaJson === false || file_put_contents($metaPath, $metaJson) === false) {
  logUploadFailure('metadata_write', 'Failed to write the upload metadata JSON file.', ['meta_path' => $metaPath, 'metadata' => $meta, 'json_error' => json_last_error_msg(), 'php_last_error' => error_get_last()]);
  @unlink($destPath);
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Failed to save the upload metadata. Please try again.']);
  exit;
}

echo json_encode([
  'success' => true,
  'id' => $id,
  'expires_at' => $expiresAtTs,
  'expiry_tier' => $tierKey,
]);
