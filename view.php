<?php
require_once 'cleanup.php';
cleanExpiredFiles();

$metaDir = __DIR__ . '/meta/';
$uploadDir = __DIR__ . '/uploads/';

$id = $_GET['id'] ?? '';

// Validate ID format (hex only)
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
  http_response_code(404);
  $error = 'Invalid video link.';
}

if (!isset($error)) {
  $metaFile = $metaDir . $id . '.json';
  if (!file_exists($metaFile)) {
    $error = 'This video has expired or does not exist.';
  }
}

if (!isset($error)) {
  $meta = json_decode(file_get_contents($metaFile), true);
  if (!$meta) {
    $error = 'Unable to load video metadata.';
  }
}

if (!isset($error)) {
  // Check expiry - support both unix timestamp and date string (legacy)
  $expiryTs = is_numeric($meta['expires_at']) ? (int) $meta['expires_at'] : strtotime($meta['expires_at']);
  if ($expiryTs < time()) {
    // Clean up expired file
    $videoPath = $uploadDir . $meta['stored_name'];
    if (file_exists($videoPath))
      unlink($videoPath);
    if (file_exists($metaFile))
      unlink($metaFile);
    $error = 'This video has expired and been deleted.';
  }
}

if (!isset($error)) {
  $videoUrl = 'uploads/' . $meta['stored_name'];
  $originalName = htmlspecialchars($meta['original_name'], ENT_QUOTES, 'UTF-8');
  $fileSize = $meta['size'];
  $uploadedAt = is_numeric($meta['uploaded_at']) ? (int) $meta['uploaded_at'] : strtotime($meta['uploaded_at']);
  $expiresAt = is_numeric($meta['expires_at']) ? (int) $meta['expires_at'] : strtotime($meta['expires_at']);
  $ext = $meta['extension'];

  // Determine MIME for video tag
  $mimeMap = [
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/mp4',
    'avi' => 'video/x-msvideo',
    'mkv' => 'video/x-matroska',
  ];
  $videoMime = $mimeMap[$ext] ?? 'video/mp4';
}

function formatBytes($bytes)
{
  if ($bytes < 1024)
    return $bytes . ' B';
  if ($bytes < 1048576)
    return round($bytes / 1024, 1) . ' KB';
  if ($bytes < 1073741824)
    return round($bytes / 1048576, 1) . ' MB';
  return round($bytes / 1073741824, 2) . ' GB';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo isset($error) ? 'Video Not Found' : $originalName . ' - TempVid'; ?></title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: #0a0a0f;
      color: #e0e0e0;
      min-height: 100vh;
    }

    .bg-gradient {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background:
        radial-gradient(ellipse at 20% 50%, rgba(120, 50, 255, 0.08) 0%, transparent 50%),
        radial-gradient(ellipse at 80% 20%, rgba(255, 50, 120, 0.06) 0%, transparent 50%),
        radial-gradient(ellipse at 50% 80%, rgba(50, 120, 255, 0.06) 0%, transparent 50%);
      z-index: 0;
    }

    .container {
      max-width: 900px;
      margin: 0 auto;
      padding: 40px 20px;
      position: relative;
      z-index: 1;
    }

    .header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 32px;
    }

    .header a {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      color: #a78bfa;
      font-size: 14px;
      font-weight: 500;
      transition: color 0.2s;
    }

    .header a:hover {
      color: #c4b5fd;
    }

    .header a .material-icons {
      font-size: 20px;
    }

    .header .logo-text {
      font-size: 18px;
      font-weight: 700;
      background: linear-gradient(135deg, #ffffff, #a0a0b0);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    /* Error state */
    .error-container {
      text-align: center;
      padding: 80px 20px;
    }

    .error-container .material-icons {
      font-size: 80px;
      color: #374151;
      margin-bottom: 20px;
    }

    .error-container h2 {
      font-size: 22px;
      font-weight: 600;
      color: #9ca3af;
      margin-bottom: 12px;
    }

    .error-container p {
      color: #6b7280;
      font-size: 15px;
      margin-bottom: 32px;
    }

    .error-container a.btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 24px;
      background: linear-gradient(135deg, #7c3aed, #6d28d9);
      color: #fff;
      text-decoration: none;
      border-radius: 10px;
      font-weight: 500;
      font-size: 14px;
      transition: all 0.3s;
    }

    .error-container a.btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 30px rgba(124, 58, 237, 0.25);
    }

    /* Video player */
    .player-card {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 20px;
      overflow: hidden;
      backdrop-filter: blur(20px);
    }

    .video-wrapper {
      position: relative;
      background: #000;
      width: 100%;
    }

    .video-wrapper video {
      width: 100%;
      max-height: 70vh;
      display: block;
      outline: none;
    }

    .video-info {
      padding: 24px 28px;
    }

    .video-title {
      font-size: 17px;
      font-weight: 600;
      color: #e0e0e0;
      margin-bottom: 16px;
      word-break: break-word;
      display: flex;
      align-items: flex-start;
      gap: 10px;
    }

    .video-title .material-icons {
      font-size: 22px;
      color: #7c3aed;
      margin-top: 1px;
    }

    .meta-row {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
      margin-bottom: 20px;
    }

    .meta-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: #6b7280;
    }

    .meta-item .material-icons {
      font-size: 18px;
      color: #4b5563;
    }

    .meta-item.expiry {
      color: #f59e0b;
    }

    .meta-item.expiry .material-icons {
      color: #f59e0b;
    }

    .countdown {
      font-weight: 600;
      color: #f59e0b;
    }

    .action-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .action-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 10px 18px;
      border-radius: 10px;
      font-family: 'Inter', sans-serif;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s;
      text-decoration: none;
      border: none;
    }

    .action-btn .material-icons {
      font-size: 18px;
    }

    .action-btn.primary {
      background: rgba(124, 58, 237, 0.15);
      border: 1px solid rgba(124, 58, 237, 0.3);
      color: #a78bfa;
    }

    .action-btn.primary:hover {
      background: rgba(124, 58, 237, 0.25);
    }

    .action-btn.secondary {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.08);
      color: #9ca3af;
    }

    .action-btn.secondary:hover {
      background: rgba(255, 255, 255, 0.08);
      color: #e0e0e0;
    }

    .footer {
      text-align: center;
      margin-top: 40px;
      color: #374151;
      font-size: 13px;
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(10px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .fade-in {
      animation: fadeIn 0.4s ease forwards;
    }

    @media (max-width: 640px) {
      .container {
        padding: 20px 12px;
      }

      .video-info {
        padding: 16px;
      }

      .meta-row {
        gap: 12px;
      }

      .action-row {
        flex-direction: column;
      }

      .action-btn {
        justify-content: center;
      }
    }
  </style>
</head>

<body>
  <div class="bg-gradient"></div>

  <div class="container">
    <div class="header">
      <a href="./">
        <span class="material-icons">slow_motion_video</span>
        <span class="logo-text">TempVid</span>
      </a>
    </div>

    <?php if (isset($error)): ?>
      <div class="error-container fade-in">
        <span class="material-icons">videocam_off</span>
        <h2>Video Unavailable</h2>
        <p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <a href="./" class="btn">
          <span class="material-icons">cloud_upload</span>
          Upload a new video
        </a>
      </div>
    <?php else: ?>
      <div class="player-card fade-in">
        <div class="video-wrapper">
          <video id="videoPlayer" controls preload="metadata" playsinline>
            <source src="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8'); ?>"
              type="<?php echo $videoMime; ?>">
            Your browser does not support the video tag.
          </video>
        </div>

        <div class="video-info">
          <div class="video-title">
            <span class="material-icons">movie</span>
            <?php echo $originalName; ?>
          </div>

          <div class="meta-row">
            <div class="meta-item">
              <span class="material-icons">straighten</span>
              <?php echo formatBytes($fileSize); ?>
            </div>
            <div class="meta-item">
              <span class="material-icons">calendar_today</span>
              Uploaded <?php echo date('M j, Y g:i A', (int) $uploadedAt); ?>
            </div>
            <div class="meta-item expiry">
              <span class="material-icons">schedule</span>
              <span>Expires in <span class="countdown" id="countdown"></span></span>
            </div>
          </div>

          <div class="action-row">
            <button class="action-btn primary" id="copyLinkBtn">
              <span class="material-icons">content_copy</span>
              Copy Link
            </button>
            <a class="action-btn secondary" href="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8'); ?>"
              download="<?php echo $originalName; ?>">
              <span class="material-icons">download</span>
              Download
            </a>
            <a class="action-btn secondary" href="./">
              <span class="material-icons">add_circle_outline</span>
              Upload Another
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="footer">
      <p>Videos are automatically deleted after 24 hours &bull; No account required</p>
    </div>
  </div>

  <?php if (!isset($error)): ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
      $(function () {
        // Countdown timer
        const expiresAt = <?php echo $expiresAt; ?> * 1000;

        function updateCountdown() {
          const now = Date.now();
          const diff = expiresAt - now;

          if (diff <= 0) {
            $('#countdown').text('Expired');
            return;
          }

          const hours = Math.floor(diff / 3600000);
          const minutes = Math.floor((diff % 3600000) / 60000);
          const seconds = Math.floor((diff % 60000) / 1000);

          let text = '';
          if (hours > 0) text += hours + 'h ';
          text += minutes + 'm ' + seconds + 's';
          $('#countdown').text(text);
        }

        updateCountdown();
        setInterval(updateCountdown, 1000);

        // Copy link
        $('#copyLinkBtn').on('click', function () {
          navigator.clipboard.writeText(window.location.href).then(() => {
            const $btn = $(this);
            $btn.html('<span class="material-icons">check</span> Copied!');
            setTimeout(() => {
              $btn.html('<span class="material-icons">content_copy</span> Copy Link');
            }, 2000);
          });
        });
      });
    </script>
  <?php endif; ?>
</body>

</html>