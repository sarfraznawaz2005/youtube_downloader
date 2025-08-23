<?php
session_start();

// Configuration
$ytdlp_path = __DIR__ . '/yt-dlp.exe';
define('DS', DIRECTORY_SEPARATOR);
$default_downloads_path = 'D:' . DS . 'SystemFolders' . DS . 'Downloads' . DS;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Turn off error reporting to prevent HTML errors in JSON response
    error_reporting(0);
    ini_set('display_errors', 0);
    
    header('Content-Type: application/json');
    
    try {
    
    switch ($_POST['action']) {
        case 'get_info':
            $url = trim($_POST['url'] ?? '');
            if (empty($url)) {
                echo json_encode(['error' => 'URL is required']);
                exit;
            }
            
            $command = escapeshellcmd($ytdlp_path) . ' --dump-json --no-playlist ' . escapeshellarg($url) . ' 2>&1';
            $output = shell_exec($command);
            file_put_contents('ytdlp_output.log', $output);

            // Kill any remaining yt-dlp processes
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                shell_exec('taskkill /F /IM yt-dlp.exe 2>nul');
            }

            if ($output) {
                $info = json_decode($output, true);
                if ($info) {
                    $formats = [];
                    $audio_formats = [];

                    foreach ($info['formats'] as $format) {
                        if ($format['vcodec'] === 'none' && $format['acodec'] !== 'none') {
                            $audio_formats[] = $format;
                        }
                    }

                    foreach ($info['formats'] as $format) {
                        if (isset($format['height']) && $format['vcodec'] !== 'none') {
                            // Find the best corresponding audio format
                            $best_audio = null;
                            foreach ($audio_formats as $audio_format) {
                                if ($audio_format['ext'] === $format['ext'] || $format['ext'] === 'mp4') {
                                    if ($best_audio === null || $audio_format['filesize'] > $best_audio['filesize']) {
                                        $best_audio = $audio_format;
                                    }
                                }
                            }

                            $formats[] = [
                                'format_id' => $format['format_id'],
                                'height' => $format['height'],
                                'ext' => $format['ext'],
                                'filesize' => $format['filesize'] ?? $format['filesize_approx'] ?? 0,
                                'audio_format_id' => $best_audio['format_id'] ?? null,
                                'audio_filesize' => $best_audio['filesize'] ?? $best_audio['filesize_approx'] ?? 0
                            ];
                        }
                    }

                    // Sort by height
                    usort($formats, function($a, $b) {
                        return $b['height'] - $a['height'];
                    });

                    echo json_encode([
                        'title' => $info['title'] ?? 'Unknown',
                        'duration' => $info['duration'] ?? 0,
                        'formats' => $formats
                    ]);
                } else {
                    echo json_encode(['error' => 'Failed to parse video info. Raw output: ' . $output]);
                }
            } else {
                echo json_encode(['error' => 'Failed to get video info. No output from yt-dlp.']);
            }
            exit;
            
        case 'download':
            $url = trim($_POST['url'] ?? '');
            $quality = $_POST['quality'] ?? '';
            $download_path = trim($_POST['download_path'] ?? $default_downloads_path);
            $is_playlist = $_POST['is_playlist'] ?? 'false';

            if (empty($url)) {
                echo json_encode(['error' => 'URL is required']);
                exit;
            }

            if (!is_dir($download_path)) {
                echo json_encode(['error' => 'Download path does not exist']);
                exit;
            }

            $session_id = session_id();
            $progress_file = __DIR__ . '/progress_' . $session_id . '.log';
            $_SESSION['progress_file'] = $progress_file;

            // Build command using array format to avoid shell escaping issues
            $format_selector = $quality;
            if (strpos($quality, ',') !== false) {
                $parts = explode(',', $quality);
                $format_selector = $parts[0] . '+' . $parts[1];
            }
            
            $playlist_flag = $is_playlist === 'true' ? '' : '--no-playlist';
            $ffmpeg_path = __DIR__ . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'ffmpeg.exe';

            // Properly construct the command without over-escaping the template
            $safe_download_path = rtrim($download_path, '/\\');

            // Build command array - this avoids shell escaping issues
            $o = $safe_download_path . DIRECTORY_SEPARATOR . '%(title)s.%(ext)s';
            $cmd_array = [
                $ytdlp_path,
                '-f', $format_selector,
                '-o', $o,
                '--ffmpeg-location', $ffmpeg_path,
                '--newline',
                '--progress',
                '--force-overwrites'  // Always force to avoid "already downloaded" issues
            ];

            if ($playlist_flag) {
                $cmd_array[] = $playlist_flag;
            }

            $cmd_array[] = $url;

            $command = escapeshellarg($ytdlp_path) .
                ' -f ' . escapeshellarg($format_selector) .
                ' -o "' . $o . '"' . // Manually quote the output template
                ' --ffmpeg-location ' . escapeshellarg($ffmpeg_path) .
                ' --newline' .
                ' --progress' .
                ' --force-overwrites';

            if ($playlist_flag) {
                $command .= ' ' . $playlist_flag;
            }

            $command .= ' ' . escapeshellarg($url);

            file_put_contents('ytdlp_command.log', $command);

            $descriptorspec = array(
                0 => array("pipe", "r"),  // stdin
                1 => array("pipe", "w"),  // stdout
                2 => array("pipe", "w")   // stderr
            );

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $process = proc_open($command . ' > "' . $progress_file . '" 2>&1', $descriptorspec, $pipes);
            } else {
                $process = proc_open($command . ' > "' . $progress_file . '" 2>&1 &', $descriptorspec, $pipes);
            }

            if (is_resource($process)) {
                $status = proc_get_status($process);
                $_SESSION['pid'] = $status['pid'];
                // Close pipes to allow the process to run in the background
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
            }

            echo json_encode(['success' => true, 'message' => 'Download started']);
            exit;
            
        case 'progress':
            if (isset($_SESSION['progress_file'])) {
                $progress_file = $_SESSION['progress_file'];
                if (file_exists($progress_file)) {
                    $output = file_get_contents($progress_file);
                    echo json_encode(['success' => true, 'output' => $output]);
                } else {
                    echo json_encode(['success' => true, 'output' => 'Starting download...']);
                }
            } else {
                echo json_encode(['error' => 'No active download']);
            }
            exit;

        case 'delete_logs':
            $session_id = session_id();
            $progress_file = __DIR__ . '/progress_' . $session_id . '.log';
            if (file_exists($progress_file)) {
                unlink($progress_file);
            }
            if (file_exists('ytdlp_output.log')) {
                unlink('ytdlp_output.log');
            }
            if (file_exists('ytdlp_command.log')) {
                unlink('ytdlp_command.log');
            }
            echo json_encode(['success' => true, 'message' => 'Log files deleted.']);
            exit;
    }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        exit;
    } catch (Error $e) {
        echo json_encode(['error' => 'PHP error: ' . $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Video Downloader</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 600px;
            transition: all 0.3s ease;
        }

        .container:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }

        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 2em;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
            font-size: 0.95em;
        }

        input[type="text"], input[type="url"], select, textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }

        input[type="text"]:focus, input[type="url"]:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        textarea {
            resize: vertical;
            min-height: 150px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 14px;
            background: #f8f9fa;
            color: #333;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .loading-content {
            background: white;
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }

        .progress-circle {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: conic-gradient(#667eea 0deg, #e0e0e0 0deg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            position: relative;
        }

        .progress-circle::before {
            content: "";
            position: absolute;
            width: 130px;
            height: 130px;
            background: white;
            border-radius: 50%;
        }

        .progress-circle-text {
            position: relative;
            font-size: 2em;
            font-weight: 600;
            color: #555;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 12px;
            margin: 15px 0;
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 12px;
            margin: 15px 0;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            animation: slideIn 0.3s ease;
            overflow: hidden;
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            position: relative;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 600;
        }

        .close {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-50%) rotate(90deg);
        }

        .modal-body {
            padding: 30px;
        }

        .video-info {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border: 1px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .video-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 1.1em;
            line-height: 1.4;
        }

        .video-duration {
            color: #666;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .video-duration::before {
            content: "⏱️";
            font-size: 16px;
        }

        .quality-group {
            margin-bottom: 25px;
        }

        .quality-group label {
            font-weight: 600;
            color: #555;
            margin-bottom: 10px;
            display: block;
        }

        .quality-group select {
            background: rgba(102, 126, 234, 0.05);
            border: 2px solid rgba(102, 126, 234, 0.2);
        }

        .quality-group select:focus {
            border-color: #667eea;
            background: white;
        }

        .progress-container {
            margin-bottom: 25px;
        }

        .progress-bar-container {
            width: 100%;
            background-color: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar {
            width: 0%;
            height: 20px;
            background-color: #667eea;
            border-radius: 10px;
            transition: width 0.2s ease-in-out;
        }

        .progress-text {
            text-align: center;
            margin-top: 5px;
            font-weight: 600;
            color: #555;
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        .btn-modal {
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
            min-width: 100px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        @media (max-width: 480px) {
            .container {
                padding: 20px;
                margin: 10px;
            }

            h1 {
                font-size: 2em;
            }

            .modal-content {
                width: 95%;
                margin: 10% auto;
            }

            .modal-header {
                padding: 20px;
            }

            .modal-body {
                padding: 20px;
            }

            .modal-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎬 YouTube Downloader</h1>
        
        <form id="downloadForm">
            <div class="form-group">
                <label for="url">YouTube URL:</label>
                <input type="url" id="url" name="url" placeholder="https://www.youtube.com/watch?v=..." value="<?php echo isset($_GET['url']) ? htmlspecialchars($_GET['url']) : ''; ?>" required>
            </div>

            <div class="form-group">
                <label for="downloadPath">Download Path:</label>
                <input type="text" id="downloadPath" name="downloadPath" value="<?php echo htmlspecialchars($default_downloads_path); ?>" required>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="isPlaylist" name="isPlaylist">
                <label for="isPlaylist">Download as playlist (if URL contains playlist)</label>
            </div>

            <button type="submit" class="btn" id="downloadBtn">
                🔥 Download Video
            </button>
        </form>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="progress-circle" id="progressCircle">
                <div class="progress-circle-text" id="progressCircleText">0%</div>
            </div>
            <div class="spinner" id="loadingSpinner"></div>
            <div id="loadingMessage">Getting video information...</div>
        </div>
    </div>

    <!-- Video Info Modal -->
    <div id="videoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📹 Video Information</h2>
                <span class="close" id="closeModal">&times;</span>
            </div>
            <div class="modal-body">
                <div class="video-info">
                    <div class="video-title" id="modalVideoTitle"></div>
                    <div class="video-duration" id="modalVideoDuration"></div>
                </div>
                
                <div class="quality-group">
                    <label for="modalQuality">Select Quality:</label>
                    <select id="modalQuality" name="modalQuality">
                    </select>
                </div>

                <div class="progress-container" style="display: none;">
                    <div class="progress-bar-container">
                        <div class="progress-bar"></div>
                    </div>
                    <div class="progress-text"></div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-modal btn-secondary" id="cancelDownload">Cancel</button>
                    <button type="button" class="btn-modal btn-primary" id="startDownload">🔥 Download Now</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const form = document.getElementById('downloadForm');
        const urlInput = document.getElementById('url');
        const downloadPathInput = document.getElementById('downloadPath');
        const isPlaylistCheckbox = document.getElementById('isPlaylist');
        const downloadBtn = document.getElementById('downloadBtn');
        
        // Loading overlay
        const loadingOverlay = document.getElementById('loadingOverlay');
        
        // Modal elements
        const videoModal = document.getElementById('videoModal');
        const closeModal = document.getElementById('closeModal');
        const modalVideoTitle = document.getElementById('modalVideoTitle');
        const modalVideoDuration = document.getElementById('modalVideoDuration');
        const modalQuality = document.getElementById('modalQuality');
        const cancelDownload = document.getElementById('cancelDownload');
        const startDownload = document.getElementById('startDownload');

        let progressInterval = null;
        let downloadInProgress = false;
        let currentVideoData = null;

        // Handle form submission
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Remove existing messages
            const existingMessages = document.querySelectorAll('.error, .success');
            existingMessages.forEach(msg => msg.remove());

            if (downloadInProgress) {
                showMessage('Download already in progress', 'error');
                return;
            }

            const url = urlInput.value.trim();
            if (!url) {
                showMessage('Please enter a YouTube URL', 'error');
                return;
            }

            // Show loading overlay
            showLoadingOverlay(true);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_info&url=${encodeURIComponent(url)}`
                });

                const data = await response.json();
                
                if (data.error) {
                    showMessage(data.error, 'error');
                } else {
                    currentVideoData = data;
                    showVideoModal(data);
                }
            } catch (error) {
                showMessage('Failed to get video info: ' + error.message, 'error');
            } finally {
                showLoadingOverlay(false);
            }
        });

        // Start actual download
        startDownload.addEventListener('click', async () => {
            if (!currentVideoData) return;

            const selectedQuality = modalQuality.value;
            if (!selectedQuality) {
                showMessage('Please select a quality', 'error', modalQuality);
                return;
            }

            videoModal.style.display = 'none';
            
            downloadInProgress = true;
            downloadBtn.disabled = true;
            urlInput.disabled = true;
            downloadPathInput.disabled = true;
            isPlaylistCheckbox.disabled = true;

            showLoadingOverlay(true, 'Starting download...');

            const formData = new FormData();
            formData.append('action', 'download');
            formData.append('url', urlInput.value);
            formData.append('quality', selectedQuality);
            formData.append('download_path', downloadPathInput.value);
            formData.append('is_playlist', isPlaylistCheckbox.checked ? 'true' : 'false');

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                
                if (data.error) {
                    showMessage(data.error, 'error');
                    downloadInProgress = false;
                    downloadBtn.disabled = false;
                    urlInput.disabled = false;
                    downloadPathInput.disabled = false;
                    isPlaylistCheckbox.disabled = false;
                    showLoadingOverlay(false);
                } else {
                    // Start checking progress
                    progressInterval = setInterval(checkProgress, 1000);
                }
            } catch (error) {
                showMessage('Failed to start download: ' + error.message, 'error');
                downloadInProgress = false;
                downloadBtn.disabled = false;
                urlInput.disabled = false;
                downloadPathInput.disabled = false;
                isPlaylistCheckbox.disabled = false;
                showLoadingOverlay(false);
            }
        });

        function showVideoModal(data) {
            modalVideoTitle.textContent = data.title;
            
            const minutes = Math.floor(data.duration / 60);
            const seconds = data.duration % 60;
            modalVideoDuration.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            
            // Update quality options
            updateModalQualityOptions(data.formats);
            
            videoModal.style.display = 'block';
        }

        function updateModalQualityOptions(formats) {
            // Clear existing options
            modalQuality.innerHTML = '';

            // Add a "Choose Quality" option
            const chooseOption = document.createElement('option');
            chooseOption.value = '';
            chooseOption.textContent = '-- Choose Quality --';
            chooseOption.selected = true;
            chooseOption.disabled = true;
            modalQuality.appendChild(chooseOption);

            // Add available qualities
            formats.forEach(format => {
                const option = document.createElement('option');
                option.value = `${format.format_id},${format.audio_format_id}`;
                const total_filesize = (format.filesize + format.audio_filesize) / 1024 / 1024;
                const filesize_text = total_filesize > 0 ? `(${(total_filesize).toFixed(2)} MB)` : '';
                option.textContent = `${format.height}p - ${format.ext} ${filesize_text}`;
                modalQuality.appendChild(option);
            });
        }

        async function checkProgress() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=progress'
                });

                const data = await response.json();
                
                if (data.error) {
                    showMessage(data.error, 'error');
                    clearInterval(progressInterval);
                    downloadInProgress = false;
                    downloadBtn.disabled = false;
                    urlInput.disabled = false;
                    downloadPathInput.disabled = false;
                    isPlaylistCheckbox.disabled = false;
                    showLoadingOverlay(false);
                    return;
                }

                if (data.success) {
                    const output = data.output;
                    let message = 'Processing...';
                    let finished = false;
                    let percentage = null;

                    const lines = output.split('\n');
                    const downloadLines = lines.filter(line => line.includes('[download]'));
                    const mergerLines = lines.filter(line => line.includes('[Merger]'));
                    const deleteLines = lines.filter(line => line.includes('Deleting original file'));

                    if (mergerLines.length > 0) {
                        message = 'Merging files...';
                    } else if (downloadLines.length > 0) {
                        const lastDownloadLine = downloadLines[downloadLines.length - 1];
                        const progressMatch = lastDownloadLine.match(/\s+([\d\.]+)\%/);
                        if (progressMatch) {
                            percentage = parseFloat(progressMatch[1]);
                            
                            const destinationLines = downloadLines.filter(line => line.includes('Destination'));
                            const lastDestinationLine = destinationLines[destinationLines.length - 1] || '';
                            const filenameMatch = lastDestinationLine.match(/Destination: (.*)/);
                            let filename = '';
                            if (filenameMatch) {
                                // Extract the filename and remove the path
                                const fullPath = filenameMatch[1];
                                filename = fullPath.split(/[\\/]/).pop();
                            }
                            
                            message = `Downloading ${filename}`;
                        } else {
                            message = lastDownloadLine.replace('[download]', '').trim();
                        }
                    } else if (lines.length > 0) {
                        message = lines[lines.length - 2] || 'Starting...';
                    }

                    // Check for completion
                    if (output.includes('has already been downloaded')) {
                        finished = true;
                        message = 'Download completed successfully!';
                    } else if (deleteLines.length > 0) {
                        // If we are merging, we need to wait for the original files to be deleted.
                        if (mergerLines.length > 0) {
                            // We expect to see two "Deleting original file" messages when merging
                            if (deleteLines.length >= 2) {
                                finished = true;
                                message = 'Download completed successfully!';
                            }
                        } else {
                            // If not merging, one "Deleting original file" is enough
                            finished = true;
                            message = 'Download completed successfully!';
                        }
                    } else if (downloadLines.length > 0 && !mergerLines.length > 0) {
                        // Handle the case where there is no merging, and the download is 100%
                        const lastDownloadLine = downloadLines[downloadLines.length - 1];
                        if (lastDownloadLine.includes('100%')) {
                           // We need to make sure this is not the first file of a merge
                            const downloadCount = output.match(/\\[download\\] Destination/g)?.length || 0;
                            if (downloadCount === 1) {
                                if (modalQuality.value === 'best') {
                                    finished = true;
                                    message = 'Download completed successfully!';
                                }
                            }
                        }
                    }


                    showLoadingOverlay(true, message, percentage);

                    if (finished) {
                        showMessage('Download completed successfully!', 'success');
                        clearInterval(progressInterval);
                        downloadInProgress = false;
                        downloadBtn.disabled = false;
                        urlInput.disabled = false;
                        downloadPathInput.disabled = false;
                        isPlaylistCheckbox.disabled = false;
                        showLoadingOverlay(false);

                        // Delete log files
                        fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=delete_logs'
                        });
                    }
                }
            } catch (error) {
                showMessage('Failed to check progress: ' + error.message, 'error');
                clearInterval(progressInterval);
                downloadInProgress = false;
                downloadBtn.disabled = false;
                urlInput.disabled = false;
                downloadPathInput.disabled = false;
                isPlaylistCheckbox.disabled = false;
                showLoadingOverlay(false);
            }
        }

        function showLoadingOverlay(show, message = 'Getting video information...', percentage = null) {
            const loadingMessage = document.getElementById('loadingMessage');
            const progressCircle = document.getElementById('progressCircle');
            const progressCircleText = document.getElementById('progressCircleText');
            const loadingSpinner = document.getElementById('loadingSpinner');

            loadingMessage.textContent = message;

            if (percentage !== null) {
                progressCircle.style.display = 'flex';
                loadingSpinner.style.display = 'none';
                const angle = percentage * 3.6;
                progressCircle.style.background = `conic-gradient(#667eea ${angle}deg, #e0e0e0 0deg)`;
                progressCircleText.textContent = `${Math.round(percentage)}%`;
            } else {
                progressCircle.style.display = 'none';
                loadingSpinner.style.display = 'block';
            }

            loadingOverlay.style.display = show ? 'flex' : 'none';
        }

        function showMessage(message, type, targetElement = null) {
            // Remove existing messages
            const existingMessages = document.querySelectorAll('.error, .success');
            existingMessages.forEach(msg => msg.remove());
            
            const messageDiv = document.createElement('div');
            messageDiv.className = type;
            messageDiv.textContent = message;
            
            if (targetElement) {
                targetElement.parentNode.insertBefore(messageDiv, targetElement);
            } else {
                form.insertBefore(messageDiv, form.firstChild);
            }
        }

        // Modal event listeners
        closeModal.addEventListener('click', () => {
            videoModal.style.display = 'none';
        });

        cancelDownload.addEventListener('click', () => {
            videoModal.style.display = 'none';
        });

        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            if (event.target === videoModal) {
                videoModal.style.display = 'none';
            }
        });

        // Auto-detect playlist URLs
        urlInput.addEventListener('input', () => {
            const url = urlInput.value;
            if (url.includes('playlist') || url.includes('&list=')) {
                isPlaylistCheckbox.checked = true;
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && videoModal.style.display === 'block') {
                videoModal.style.display = 'none';
            }
        });

        // Auto-click download if URL is present in query string
        window.addEventListener('DOMContentLoaded', () => {
            if (urlInput.value.trim()) {
                downloadBtn.click();
            }
        });
    </script>
</body>
</html>