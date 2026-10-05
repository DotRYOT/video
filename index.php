<?php
session_start();
require_once 'config.php';
require_once 'cleanup.php';
cleanExpiredFiles();

// Handle login/logout
if (isset($_POST['access_key'])) {
  if (hash_equals(ACCESS_KEY, $_POST['access_key'])) {
    $_SESSION['authenticated'] = true;
  } else {
    $loginError = 'Invalid access key. Please try again.';
  }
}
if (isset($_GET['logout'])) {
  session_destroy();
  header('Location: ./');
  exit;
}

$isAuthed = !empty($_SESSION['authenticated']);

// Compute active video counts per tier
$tierCounts = [];
if ($isAuthed) {
  $metaDir = __DIR__ . '/meta/';
  $metaFiles = glob($metaDir . '*.json') ?: [];
  $now = time();
  foreach ($metaFiles as $mf) {
    $md = json_decode(file_get_contents($mf), true);
    if (!$md) continue;
    $exp = isset($md['expires_at']) ? (int)$md['expires_at'] : 0;
    if ($exp > $now && isset($md['expiry_tier'])) {
      $k = $md['expiry_tier'];
      $tierCounts[$k] = ($tierCounts[$k] ?? 0) + 1;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TempVid - Temporary Video Sharing</title>
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
      overflow-x: hidden;
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
      max-width: 720px;
      margin: 0 auto;
      padding: 40px 20px;
      position: relative;
      z-index: 1;
    }

    .header {
      text-align: center;
      margin-bottom: 48px;
    }

    .header .logo {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
    }

    .header .logo .material-icons {
      font-size: 40px;
      background: linear-gradient(135deg, #7c3aed, #ec4899);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .header h1 {
      font-size: 32px;
      font-weight: 700;
      background: linear-gradient(135deg, #ffffff, #a0a0b0);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .header p {
      color: #6b7280;
      font-size: 15px;
      margin-top: 8px;
    }

    .header .badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(124, 58, 237, 0.12);
      border: 1px solid rgba(124, 58, 237, 0.25);
      color: #a78bfa;
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 500;
      margin-top: 16px;
    }

    .badge .material-icons {
      font-size: 16px;
    }

    .upload-card {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 20px;
      padding: 48px;
      backdrop-filter: blur(20px);
      transition: all 0.3s ease;
    }

    .drop-zone {
      border: 2px dashed rgba(124, 58, 237, 0.3);
      border-radius: 16px;
      padding: 60px 40px;
      text-align: center;
      cursor: pointer;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .drop-zone::before {
      content: '';
      position: absolute;
      inset: 0;
      background: rgba(124, 58, 237, 0.03);
      transition: all 0.3s ease;
    }

    .drop-zone:hover,
    .drop-zone.dragover {
      border-color: rgba(124, 58, 237, 0.6);
      transform: translateY(-2px);
    }

    .drop-zone:hover::before,
    .drop-zone.dragover::before {
      background: rgba(124, 58, 237, 0.06);
    }

    .drop-zone .material-icons.upload-icon {
      font-size: 56px;
      color: #7c3aed;
      margin-bottom: 16px;
      position: relative;
    }

    .drop-zone h3 {
      font-size: 18px;
      font-weight: 600;
      color: #e0e0e0;
      margin-bottom: 8px;
      position: relative;
    }

    .drop-zone p {
      color: #6b7280;
      font-size: 14px;
      position: relative;
    }

    .drop-zone .formats {
      margin-top: 16px;
      font-size: 12px;
      color: #4b5563;
      position: relative;
    }

    #fileInput {
      display: none;
    }

    .file-info {
      display: none;
      margin-top: 24px;
      padding: 16px 20px;
      background: rgba(124, 58, 237, 0.08);
      border: 1px solid rgba(124, 58, 237, 0.15);
      border-radius: 12px;
    }

    .file-info .file-details {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .file-info .material-icons {
      font-size: 24px;
      color: #7c3aed;
    }

    .file-info .file-name {
      font-weight: 500;
      font-size: 14px;
      color: #e0e0e0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 350px;
    }

    .file-info .file-size {
      font-size: 12px;
      color: #6b7280;
      margin-top: 2px;
    }

    .file-info .remove-btn {
      margin-left: auto;
      background: none;
      border: none;
      color: #6b7280;
      cursor: pointer;
      padding: 4px;
      border-radius: 6px;
      transition: all 0.2s;
      display: flex;
    }

    .file-info .remove-btn:hover {
      color: #ef4444;
      background: rgba(239, 68, 68, 0.1);
    }

    .progress-wrapper {
      display: none;
      margin-top: 24px;
    }

    .progress-bar-bg {
      height: 6px;
      background: rgba(255, 255, 255, 0.06);
      border-radius: 3px;
      overflow: hidden;
    }

    .progress-bar-fill {
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, #7c3aed, #ec4899);
      border-radius: 3px;
      transition: width 0.3s ease;
    }

    .progress-text {
      display: flex;
      justify-content: space-between;
      margin-top: 8px;
      font-size: 12px;
      color: #6b7280;
    }

    .upload-btn {
      display: none;
      width: 100%;
      margin-top: 24px;
      padding: 16px;
      background: linear-gradient(135deg, #7c3aed, #6d28d9);
      border: none;
      border-radius: 12px;
      color: #fff;
      font-family: 'Inter', sans-serif;
      font-size: 15px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: none;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .upload-btn:hover {
      background: linear-gradient(135deg, #6d28d9, #5b21b6);
      transform: translateY(-1px);
      box-shadow: 0 8px 30px rgba(124, 58, 237, 0.25);
    }

    .upload-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    .upload-btn .material-icons {
      font-size: 20px;
    }

    .result-card {
      display: none;
      margin-top: 32px;
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 20px;
      padding: 32px;
      backdrop-filter: blur(20px);
      text-align: center;
    }

    .result-card .success-icon {
      width: 64px;
      height: 64px;
      background: rgba(34, 197, 94, 0.1);
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 16px;
    }

    .result-card .success-icon .material-icons {
      font-size: 32px;
      color: #22c55e;
    }

    .result-card h3 {
      font-size: 18px;
      font-weight: 600;
      color: #e0e0e0;
      margin-bottom: 8px;
    }

    .result-card .expiry {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #f59e0b;
      font-size: 13px;
      margin-bottom: 20px;
    }

    .result-card .expiry .material-icons {
      font-size: 16px;
    }

    .url-box {
      display: flex;
      align-items: center;
      background: rgba(0, 0, 0, 0.3);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 10px;
      padding: 4px;
      margin-top: 8px;
    }

    .url-box input {
      flex: 1;
      background: none;
      border: none;
      color: #a78bfa;
      font-family: 'Inter', monospace;
      font-size: 13px;
      padding: 10px 14px;
      outline: none;
      min-width: 0;
    }

    .url-box .copy-btn {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 10px 18px;
      background: rgba(124, 58, 237, 0.15);
      border: 1px solid rgba(124, 58, 237, 0.3);
      border-radius: 8px;
      color: #a78bfa;
      font-family: 'Inter', sans-serif;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s;
      white-space: nowrap;
    }

    .url-box .copy-btn:hover {
      background: rgba(124, 58, 237, 0.25);
    }

    .copy-btn .material-icons {
      font-size: 18px;
    }

    .upload-another {
      margin-top: 20px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #6b7280;
      font-size: 14px;
      cursor: pointer;
      background: none;
      border: none;
      font-family: 'Inter', sans-serif;
      transition: color 0.2s;
    }

    .upload-another:hover {
      color: #a78bfa;
    }

    .upload-another .material-icons {
      font-size: 18px;
    }

    .error-msg {
      display: none;
      margin-top: 16px;
      padding: 14px 18px;
      background: rgba(239, 68, 68, 0.08);
      border: 1px solid rgba(239, 68, 68, 0.2);
      border-radius: 10px;
      color: #f87171;
      font-size: 14px;
      text-align: center;
    }

    .footer {
      text-align: center;
      margin-top: 48px;
      color: #374151;
      font-size: 13px;
    }

    /* Auth / Login styles */
    .auth-card {
      background: rgba(255, 255, 255, 0.03);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 20px;
      padding: 48px;
      backdrop-filter: blur(20px);
      text-align: center;
      max-width: 420px;
      margin: 0 auto;
    }

    .auth-card .lock-icon {
      width: 64px;
      height: 64px;
      background: rgba(124, 58, 237, 0.1);
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
    }

    .auth-card .lock-icon .material-icons {
      font-size: 32px;
      color: #7c3aed;
    }

    .auth-card h2 {
      font-size: 20px;
      font-weight: 600;
      color: #e0e0e0;
      margin-bottom: 8px;
    }

    .auth-card p {
      color: #6b7280;
      font-size: 14px;
      margin-bottom: 24px;
    }

    .auth-card .key-input-group {
      display: flex;
      gap: 8px;
    }

    .auth-card input[type="password"] {
      flex: 1;
      padding: 14px 16px;
      background: rgba(0, 0, 0, 0.3);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 10px;
      color: #e0e0e0;
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      outline: none;
      transition: border-color 0.2s;
    }

    .auth-card input[type="password"]:focus {
      border-color: rgba(124, 58, 237, 0.5);
    }

    .auth-card input[type="password"]::placeholder {
      color: #4b5563;
    }

    .auth-card .auth-btn {
      padding: 14px 24px;
      background: linear-gradient(135deg, #7c3aed, #6d28d9);
      border: none;
      border-radius: 10px;
      color: #fff;
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .auth-card .auth-btn:hover {
      background: linear-gradient(135deg, #6d28d9, #5b21b6);
      transform: translateY(-1px);
      box-shadow: 0 8px 30px rgba(124, 58, 237, 0.25);
    }

    .auth-card .auth-btn .material-icons {
      font-size: 20px;
    }

    .auth-error {
      margin-top: 16px;
      padding: 12px 16px;
      background: rgba(239, 68, 68, 0.08);
      border: 1px solid rgba(239, 68, 68, 0.2);
      border-radius: 10px;
      color: #f87171;
      font-size: 13px;
    }

    .logout-btn {
      position: absolute;
      top: 0;
      right: 0;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 8px 14px;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.06);
      border-radius: 8px;
      color: #6b7280;
      font-family: 'Inter', sans-serif;
      font-size: 12px;
      text-decoration: none;
      transition: all 0.2s;
    }

    .logout-btn:hover {
      color: #f87171;
      background: rgba(239, 68, 68, 0.06);
      border-color: rgba(239, 68, 68, 0.15);
    }

    .logout-btn .material-icons {
      font-size: 16px;
    }

    .header {
      position: relative;
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

    @keyframes pulse {

      0%,
      100% {
        opacity: 1;
      }

      50% {
        opacity: 0.5;
      }
    }

    .uploading-pulse {
      animation: pulse 1.5s ease-in-out infinite;
    }

    .expiry-selector {
      display: none;
      margin-top: 24px;
    }

    .expiry-selector label {
      display: block;
      font-size: 13px;
      font-weight: 500;
      color: #9ca3af;
      margin-bottom: 10px;
    }

    .expiry-options {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
    }

    .expiry-option input[type="radio"] {
      display: none;
    }

    .expiry-option label {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      padding: 12px 8px;
      background: rgba(0,0,0,0.25);
      border: 1px solid rgba(255,255,255,0.07);
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.2s;
      text-align: center;
      margin-bottom: 0;
    }

    .expiry-option label .expiry-time {
      font-size: 14px;
      font-weight: 600;
      color: #e0e0e0;
    }

    .expiry-option label .expiry-limit {
      font-size: 10px;
      color: #6b7280;
      line-height: 1.3;
    }

    .expiry-option input[type="radio"]:checked + label {
      background: rgba(124, 58, 237, 0.12);
      border-color: rgba(124, 58, 237, 0.45);
    }

    .expiry-option input[type="radio"]:checked + label .expiry-time {
      color: #a78bfa;
    }

    .expiry-option label:hover {
      border-color: rgba(124, 58, 237, 0.3);
      background: rgba(124, 58, 237, 0.06);
    }

    .expiry-full label,
    .expiry-option input[type="radio"]:disabled + label {
      opacity: 0.38;
      cursor: not-allowed;
      pointer-events: none;
    }

    .expiry-option label .expiry-limit.full-badge {
      color: #f87171;
    }

    @media (max-width: 640px) {
      .container {
        padding: 24px 16px;
      }

      .upload-card {
        padding: 24px;
      }

      .drop-zone {
        padding: 40px 20px;
      }

      .header h1 {
        font-size: 24px;
      }
      .expiry-options {
        grid-template-columns: repeat(2, 1fr);
      }    }
  </style>
</head>

<body>
  <div class="bg-gradient"></div>

  <div class="container">
    <div class="header">
      <?php if ($isAuthed): ?>
        <a href="?logout=1" class="logout-btn">
          <span class="material-icons">logout</span>
          Logout
        </a>
      <?php endif; ?>
      <div class="logo">
        <span class="material-icons">slow_motion_video</span>
        <h1>TempVid</h1>
      </div>
      <p>Upload a video and get a shareable link instantly</p>
      <div class="badge">
        <span class="material-icons">schedule</span>
        Links expire automatically — choose your duration
      </div>
    </div>

    <?php if (!$isAuthed): ?>
      <div class="auth-card fade-in">
        <div class="lock-icon">
          <span class="material-icons">vpn_key</span>
        </div>
        <h2>Enter Access Key</h2>
        <p>You need an access key to upload videos</p>
        <form method="POST" action="">
          <div class="key-input-group">
            <input type="password" name="access_key" placeholder="Paste your access key" required autofocus>
            <button type="submit" class="auth-btn">
              <span class="material-icons">arrow_forward</span>
            </button>
          </div>
          <?php if (!empty($loginError)): ?>
            <div class="auth-error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>
        </form>
      </div>
    <?php else: ?>
      <div class="upload-card" id="uploadCard">
        <div class="drop-zone" id="dropZone">
          <span class="material-icons upload-icon">cloud_upload</span>
          <h3>Drop your video here</h3>
          <p>or click to browse files</p>
          <div class="formats">MP4 &bull; WEBM &bull; MOV &bull; AVI &bull; MKV &mdash; Max 500MB</div>
        </div>

        <input type="file" id="fileInput"
          accept="video/mp4,video/webm,video/quicktime,video/x-msvideo,video/x-matroska,.mp4,.webm,.mov,.avi,.mkv">

        <div class="file-info" id="fileInfo">
          <div class="file-details">
            <span class="material-icons">movie</span>
            <div>
              <div class="file-name" id="fileName"></div>
              <div class="file-size" id="fileSize"></div>
            </div>
            <button class="remove-btn" id="removeFile" title="Remove file">
              <span class="material-icons">close</span>
            </button>
          </div>
        </div>

        <div class="progress-wrapper" id="progressWrapper">
          <div class="progress-bar-bg">
            <div class="progress-bar-fill" id="progressBar"></div>
          </div>
          <div class="progress-text">
            <span id="progressPercent">0%</span>
            <span id="progressStatus">Uploading...</span>
          </div>
        </div>

        <div class="expiry-selector" id="expirySelector">
          <label>Link expiry duration</label>
          <div class="expiry-options">
            <?php
            $firstEnabled = true;
            foreach (EXPIRY_TIERS as $key => $tier):
              $count  = $tierCounts[$key] ?? 0;
              $limit  = $tier['limit'];
              $full   = $limit !== null && $count >= $limit;
              if ($limit === null) {
                $limitText  = 'Unlimited';
                $limitClass = '';
              } else {
                $limitText  = $count . ' / ' . $limit . ' used';
                $limitClass = $full ? ' full-badge' : '';
              }
              $isFirst  = $firstEnabled && !$full;
              if ($isFirst) $firstEnabled = false;
            ?>
            <div class="expiry-option<?php echo $full ? ' expiry-full' : ''; ?>">
              <input type="radio" name="expiry" id="exp-<?php echo $key; ?>" value="<?php echo $key; ?>"
                <?php echo $isFirst ? 'checked' : ''; ?>
                <?php echo $full  ? 'disabled' : ''; ?>>
              <label for="exp-<?php echo $key; ?>">
                <span class="expiry-time"><?php echo htmlspecialchars($tier['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="expiry-limit<?php echo $limitClass; ?>"><?php echo htmlspecialchars($limitText, ENT_QUOTES, 'UTF-8'); ?></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="error-msg" id="errorMsg"></div>

        <button class="upload-btn" id="uploadBtn">
          <span class="material-icons">cloud_upload</span>
          Upload Video
        </button>
      </div>

      <div class="result-card" id="resultCard">
        <div class="success-icon">
          <span class="material-icons">check_circle</span>
        </div>
        <h3>Video Uploaded Successfully!</h3>
        <div class="expiry">
          <span class="material-icons">schedule</span>
          <span id="expiryText">Expires in 24 hours</span>
        </div>
        <div class="url-box">
          <input type="text" id="videoUrl" readonly>
          <button class="copy-btn" id="copyBtn">
            <span class="material-icons">content_copy</span>
            Copy
          </button>
        </div>
        <button class="upload-another" id="uploadAnother">
          <span class="material-icons">add_circle_outline</span>
          Upload another video
        </button>
      </div>
    <?php endif; ?>

    <div class="footer">
      <p>Videos are automatically deleted after their expiry time &bull; No account required</p>
    </div>
  </div>

  <?php if ($isAuthed): ?>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
      $(function () {
        const $dropZone = $('#dropZone');
        const $fileInput = $('#fileInput');
        const $fileInfo = $('#fileInfo');
        const $uploadBtn = $('#uploadBtn');
        const $progressWrapper = $('#progressWrapper');
        const $progressBar = $('#progressBar');
        const $errorMsg = $('#errorMsg');
        const $resultCard = $('#resultCard');
        const $uploadCard = $('#uploadCard');
        const maxSize = 500 * 1024 * 1024; // 500MB
        let selectedFile = null;

        // Drop zone events
        $dropZone.on('click', () => $fileInput.trigger('click'));

        $dropZone.on('dragover dragenter', function (e) {
          e.preventDefault();
          e.stopPropagation();
          $(this).addClass('dragover');
        });

        $dropZone.on('dragleave drop', function (e) {
          e.preventDefault();
          e.stopPropagation();
          $(this).removeClass('dragover');
        });

        $dropZone.on('drop', function (e) {
          const files = e.originalEvent.dataTransfer.files;
          if (files.length) handleFile(files[0]);
        });

        $fileInput.on('change', function () {
          if (this.files.length) handleFile(this.files[0]);
        });

        function handleFile(file) {
          const allowedTypes = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];
          const allowedExts = ['.mp4', '.webm', '.mov', '.avi', '.mkv'];
          const ext = '.' + file.name.split('.').pop().toLowerCase();

          if (!allowedTypes.includes(file.type) && !allowedExts.includes(ext)) {
            showError('Please select a valid video file (MP4, WEBM, MOV, AVI, MKV)');
            return;
          }

          if (file.size > maxSize) {
            showError('File is too large. Maximum size is 500MB.');
            return;
          }

          selectedFile = file;
          $('#fileName').text(file.name);
          $('#fileSize').text(formatSize(file.size));
          $fileInfo.css('display', 'block').addClass('fade-in');
          $('#expirySelector').css('display', 'block').addClass('fade-in');
          $uploadBtn.css('display', 'flex').addClass('fade-in');
          $errorMsg.hide();
        }

        $('#removeFile').on('click', function () {
          selectedFile = null;
          $fileInput.val('');
          $fileInfo.hide();
          $('#expirySelector').hide();
          $uploadBtn.hide();
          $errorMsg.hide();
        });

        $uploadBtn.on('click', function () {
          if (!selectedFile) return;
          uploadFile(selectedFile);
        });

        const expiryLabels = {
          '24h': '24 hours',
          '1w':  '1 week',
          '2w':  '2 weeks',
          '1mo': '1 month'
        };

        function uploadFile(file) {
          const formData = new FormData();
          formData.append('video', file);
          const selectedExpiry = $('input[name="expiry"]:checked').val() || '24h';
          formData.append('expiry', selectedExpiry);

          $uploadBtn.prop('disabled', true).html('<span class="material-icons uploading-pulse">cloud_upload</span> Uploading...');
          $progressWrapper.css('display', 'block').addClass('fade-in');
          $errorMsg.hide();

          $.ajax({
            url: 'upload.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function () {
              const xhr = new XMLHttpRequest();
              xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                  const pct = Math.round((e.loaded / e.total) * 100);
                  $progressBar.css('width', pct + '%');
                  $('#progressPercent').text(pct + '%');
                  if (pct === 100) {
                    $('#progressStatus').text('Processing...');
                  }
                }
              });
              return xhr;
            },
            success: function (res) {
              if (res.success) {
                const url = window.location.origin + window.location.pathname.replace('index.php', '') + 'view.php?id=' + res.id;
                $('#videoUrl').val(url);
                const expiry = new Date(res.expires_at * 1000);
                const tierLabel = expiryLabels[res.expiry_tier] || '24 hours';
                $('#expiryText').text('Expires in ' + tierLabel + ' (' + expiry.toLocaleString() + ')');
                $uploadCard.hide();
                $resultCard.css('display', 'block').addClass('fade-in');
              } else {
                showError(res.error || 'Upload failed. Please try again.');
                resetUploadBtn();
              }
            },
            error: function (xhr) {
              let msg = 'Upload failed. Please try again.';
              if (xhr.responseJSON && xhr.responseJSON.error) {
                msg = xhr.responseJSON.error;
              }
              showError(msg);
              resetUploadBtn();
            }
          });
        }

        function resetUploadBtn() {
          $uploadBtn.prop('disabled', false).html('<span class="material-icons">cloud_upload</span> Upload Video');
          $progressWrapper.hide();
          $progressBar.css('width', '0%');
          $('#progressPercent').text('0%');
          $('#progressStatus').text('Uploading...');
        }

        $('#copyBtn').on('click', function () {
          const $url = $('#videoUrl');
          $url.select();
          navigator.clipboard.writeText($url.val()).then(() => {
            const $btn = $(this);
            $btn.html('<span class="material-icons">check</span> Copied!');
            setTimeout(() => {
              $btn.html('<span class="material-icons">content_copy</span> Copy');
            }, 2000);
          });
        });

        $('#uploadAnother').on('click', function () {
          selectedFile = null;
          $fileInput.val('');
          $fileInfo.hide();
          $('#expirySelector').hide();
          $('input[name="expiry"][value="24h"]').prop('checked', true);
          $uploadBtn.hide().css('display', 'none');
          $progressWrapper.hide();
          $errorMsg.hide();
          $resultCard.hide();
          $uploadCard.css('display', 'block').addClass('fade-in');
          resetUploadBtn();
        });

        function showError(msg) {
          $errorMsg.text(msg).css('display', 'block').addClass('fade-in');
        }

        function formatSize(bytes) {
          if (bytes < 1024) return bytes + ' B';
          if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
          if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
          return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
        }
      });
    </script>
  <?php endif; ?>
</body>

</html>