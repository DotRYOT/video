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
  <link rel="stylesheet" href="./css/index.min.css">
</head>

<body>
  <div class="container">
    <div class="header">
      <div class="mainHeaderContent">
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
      </div>
      <div class="sideContent">
        <p>--/ Upload a video and get a shareable link instantly</p>
        <div class="badge">
          <span class="material-icons">schedule</span>
          --/ Links expire automatically — choose your duration
        </div>
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
      $(function() {
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

        $dropZone.on('dragover dragenter', function(e) {
          e.preventDefault();
          e.stopPropagation();
          $(this).addClass('dragover');
        });

        $dropZone.on('dragleave drop', function(e) {
          e.preventDefault();
          e.stopPropagation();
          $(this).removeClass('dragover');
        });

        $dropZone.on('drop', function(e) {
          const files = e.originalEvent.dataTransfer.files;
          if (files.length) handleFile(files[0]);
        });

        $fileInput.on('change', function() {
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

        $('#removeFile').on('click', function() {
          selectedFile = null;
          $fileInput.val('');
          $fileInfo.hide();
          $('#expirySelector').hide();
          $uploadBtn.hide();
          $errorMsg.hide();
        });

        $uploadBtn.on('click', function() {
          if (!selectedFile) return;
          uploadFile(selectedFile);
        });

        const expiryLabels = {
          '24h': '24 hours',
          '1w': '1 week',
          '2w': '2 weeks',
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
            xhr: function() {
              const xhr = new XMLHttpRequest();
              xhr.upload.addEventListener('progress', function(e) {
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
            success: function(res) {
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
            error: function(xhr) {
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

        $('#copyBtn').on('click', function() {
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

        $('#uploadAnother').on('click', function() {
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