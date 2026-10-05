<?php
/**
 * TempVid Configuration
 * 
 * Change the ACCESS_KEY below to whatever secret key you want.
 * Share this key with people you want to grant upload access.
 * Viewers do NOT need the key — only uploaders do.
 */

define('ACCESS_KEY', 'PASSWORD');

// Max file size in bytes (500MB)
define('MAX_FILE_SIZE', 500 * 1024 * 1024);

// Expiry tiers loaded from tiers.json — edit that file to change limits/labels
$_tiersFile = __DIR__ . '/tiers.json';
$_tiersData = json_decode(file_get_contents($_tiersFile), true);
if (!is_array($_tiersData)) {
  die('tiers.json is missing or invalid.');
}
define('EXPIRY_TIERS', $_tiersData);
