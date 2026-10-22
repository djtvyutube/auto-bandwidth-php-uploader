<?php
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');

$baseDir = __DIR__;
$selfScript = basename(__FILE__);
$logFile = $baseDir . '/log.json';

// --- ADVANCED JSON LOGGING SYSTEM (WITH CONCURRENT FILE LOCKING) ---
function writeLog($action, $data = array()) {
    global $logFile;
    $entry = array(
        'timestamp' => date('Y-m-d H:i:s'),
        'ip'        => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'CLI',
        'ua'        => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
        'action'    => $action,
        'data'      => $data
    );

    $jsonOptions = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $fp = @fopen($logFile, 'c+');
    if ($fp) {
        if (@flock($fp, LOCK_EX)) {
            $content = stream_get_contents($fp);
            $current = array();
            if ($content) {
                $parsed = @json_decode($content, true);
                if (is_array($parsed)) {
                    $current = $parsed;
                }
            }
            $current[] = $entry;
            if (count($current) > 200) {
                $current = array_slice($current, -200);
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($current, $jsonOptions));
            fflush($fp);
            @flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

// --- LOG VIEWER / CLEAR API ---
if (isset($_GET['get_log'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo file_exists($logFile) ? @file_get_contents($logFile) : json_encode(array());
    exit;
}

if (isset($_GET['clear_log'])) {
    @file_put_contents($logFile, json_encode(array()));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => true));
    exit;
}

// --- CHUNK UPLOAD ENGINE ---
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'chunk_upload') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $isZeroByte = (isset($_POST['is_zero_byte']) && $_POST['is_zero_byte'] === '1');
    $rawPath = isset($_POST['relative_path']) ? trim($_POST['relative_path']) : '';
    $fileName = isset($_POST['file_name']) ? basename($_POST['file_name']) : 'unnamed.dat';
    $chunkIndex = isset($_POST['chunk_index']) ? (int)$_POST['chunk_index'] : 0;
    $totalChunks = isset($_POST['total_chunks']) ? (int)$_POST['total_chunks'] : 1;

    writeLog('CHUNK_RECEIVED', array(
        'file' => $fileName,
        'path' => $rawPath,
        'chunk' => $chunkIndex,
        'total_chunks' => $totalChunks,
        'is_zero_byte' => $isZeroByte
    ));

    if (!$isZeroByte) {
        $hasChunk = isset($_FILES['chunk']);
        $chunkOk = ($hasChunk && $_FILES['chunk']['error'] === UPLOAD_ERR_OK);
        if (!$chunkOk) {
            $errCode = $hasChunk ? $_FILES['chunk']['error'] : 'NO_FILE';
            $errDetails = array(
                UPLOAD_ERR_INI_SIZE   => 'Server file chunk size exceeded (upload_max_filesize). If permission issue, set chmod 777.',
                UPLOAD_ERR_FORM_SIZE  => 'Form size limit exceeded.',
                UPLOAD_ERR_PARTIAL    => 'File chunk upload was incomplete.',
                UPLOAD_ERR_NO_FILE    => 'No chunk file found to upload.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder (tmp) on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write chunk to disk (Permission denied or disk full. Please set chmod 777 on target folder).',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
            );
            $errMsg = isset($errDetails[$errCode]) ? $errDetails[$errCode] : ('Error Code: ' . $errCode);

            writeLog('ERROR_UPLOAD_ERR', array('error_code' => $errCode, 'detail' => $errMsg, 'file' => $fileName));
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => ('Chunk upload error: ' . $errMsg . ' (If permission error, run chmod 777)')));
            exit;
        }
    }

    if (!empty($rawPath)) {
        $normalized = str_replace(array('\\', '/'), '/', $rawPath);
        $normalized = ltrim($normalized, '/');
        $rawParts = explode('/', $normalized);
        $safeParts = array();

        foreach ($rawParts as $p) {
            $p = trim($p);
            if ($p !== '' && $p !== '.' && $p !== '..') {
                $safeParts[] = $p;
            }
        }

        if (count($safeParts) > 0) {
            $targetFile = $baseDir . '/' . implode('/', $safeParts);
            $targetDir = dirname($targetFile);
        } else {
            $targetDir = $baseDir;
            $targetFile = $baseDir . '/' . $fileName;
        }
    } else {
        $targetDir = $baseDir;
        $targetFile = $baseDir . '/' . $fileName;
    }

    if (!is_dir($targetDir)) {
        $oldUmask = umask(0);
        $created = @mkdir($targetDir, 0775, true);
        umask($oldUmask);

        if (!$created && !is_dir($targetDir)) {
            writeLog('ERROR_DIR_PERMISSION', array('dir' => $targetDir));
            http_response_code(500);
            echo json_encode(array('success' => false, 'error' => 'Failed to create directory. Permission denied. Please run "chmod 777" or "chown -R www-data:www-data".'));
            exit;
        }
    }

    if (!is_writable($targetDir)) {
        writeLog('ERROR_DIR_NOT_WRITABLE', array('dir' => $targetDir));
        http_response_code(500);
        echo json_encode(array('success' => false, 'error' => 'Destination directory is not writable. Please set directory permissions to 777 (chmod 777).'));
        exit;
    }

    if ($isZeroByte) {
        if (@file_put_contents($targetFile, '') !== false) {
            writeLog('COMPLETED_ZERO_BYTE', array('target' => $targetFile));
            echo json_encode(array('success' => true, 'completed' => true, 'file' => $fileName));
        } else {
            writeLog('ERROR_ZERO_BYTE_WRITE', array('target' => $targetFile));
            http_response_code(500);
            echo json_encode(array('success' => false, 'error' => 'Could not write 0-byte file. Check folder permissions (chmod 777).'));
        }
        exit;
    }

    $partFile = $targetFile . '.upload_tmp';

    if ($chunkIndex === 0 && file_exists($partFile)) {
        @unlink($partFile);
    }

    $in = @fopen($_FILES['chunk']['tmp_name'], 'rb');
    $out = @fopen($partFile, 'ab');

    if (!($in && $out)) {
        if ($in) fclose($in);
        if ($out) fclose($out);
        writeLog('ERROR_STREAM_OPEN', array('part' => $partFile));
        http_response_code(500);
        echo json_encode(array('success' => false, 'error' => 'Could not open temporary file stream (Permission denied. Please set permissions to chmod 777).'));
        exit;
    }

    while (!feof($in)) {
        $buf = fread($in, 1048576);
        if ($buf !== false && strlen($buf) > 0) {
            fwrite($out, $buf);
        }
    }
    fclose($in);
    fclose($out);
    @unlink($_FILES['chunk']['tmp_name']);

    if ($chunkIndex + 1 >= $totalChunks) {
        if (file_exists($targetFile)) {
            @unlink($targetFile);
        }
        if (@rename($partFile, $targetFile)) {
            @chmod($targetFile, 0664);
            writeLog('COMPLETED_FILE', array('target' => $targetFile, 'size' => @filesize($targetFile)));
            echo json_encode(array('success' => true, 'completed' => true, 'file' => $fileName));
        } else {
            writeLog('ERROR_RENAME_FAILED', array('from' => $partFile, 'to' => $targetFile));
            http_response_code(500);
            echo json_encode(array('success' => false, 'error' => 'Could not assemble temporary file chunks. Check directory write permissions (chmod 777).'));
        }
    } else {
        echo json_encode(array('success' => true, 'completed' => false, 'chunk' => $chunkIndex));
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Universal Bandwidth Uploader + log.json</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace; }
        body { background: #090d16; color: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .box { width: 100%; max-width: 850px; background: #111827; border: 1px solid #1f2937; border-radius: 12px; padding: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.6); }
        h2 { font-size: 1.25rem; margin-bottom: 20px; color: #38bdf8; text-align: center; font-weight: 600; }

        .dropzone { border: 2px dashed #374151; border-radius: 8px; padding: 26px; text-align: center; margin-bottom: 20px; transition: 0.2s; cursor: pointer; }
        .dropzone.dragover { border-color: #38bdf8; background: rgba(56, 189, 248, 0.05); }
        .dropzone p { color: #9ca3af; font-size: 0.95rem; margin-bottom: 14px; }

        .btn-row { display: flex; gap: 10px; justify-content: center; align-items: center; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 18px; background: #2563eb; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.85rem; user-select: none; }
        .btn:hover { background: #1d4ed8; }
        .btn-folder { background: #0891b2; }
        .btn-folder:hover { background: #0e7490; }
        .btn-stop { background: #e11d48; display: none; }

        .concurrency-wrapper { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 14px; font-size: 0.8rem; color: #94a3b8; }
        .concurrency-select { background: #1f2937; border: 1px solid #374151; color: #38bdf8; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; outline: none; cursor: pointer; font-weight: 600; }

        .status-row { display: flex; justify-content: space-between; font-size: 0.85rem; margin-top: 18px; color: #94a3b8; }
        .progress-bg { width: 100%; height: 12px; background: #1f2937; border-radius: 6px; overflow: hidden; margin: 8px 0; }
        .progress-bar { width: 0%; height: 100%; background: linear-gradient(90deg, #0284c7, #38bdf8); transition: width 0.1s ease-out; }
        .metric-row { display: flex; justify-content: space-between; font-size: 0.8rem; margin-top: 4px; color: #38bdf8; }

        .log-header { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; margin-bottom: 6px; }
        .log-title { font-size: 0.8rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .log-actions { display: flex; gap: 10px; }
        .log-btn { background: none; border: none; color: #38bdf8; cursor: pointer; font-size: 0.75rem; text-decoration: underline; }
        .log { height: 220px; overflow-y: auto; background: #030712; border: 1px solid #1f2937; padding: 12px; font-size: 11px; border-radius: 6px; font-family: monospace; }
        .ok { color: #4ade80; }
        .warn { color: #facc15; }
        .err { color: #f87171; font-weight: bold; }

        .file-input-hidden {
            position: fixed;
            top: -9999px;
            left: -9999px;
            opacity: 0;
            width: 1px;
            height: 1px;
            pointer-events: none;
        }
    </style>
</head>
<body>

<div class="box">
    <h2>Ultra Multi-Format Uploader &amp; Automatic log.json</h2>

    <div class="dropzone" id="dropArea">
        <p>Drag and drop files or folders here</p>
        <div class="btn-row">
            <label for="fileInput" class="btn" id="btnSelectFile">Select Files</label>
            <label for="folderInput" class="btn btn-folder" id="btnSelectFolder">Select Folder</label>
            <button class="btn btn-stop" type="button" id="stopBtn" onclick="stopUpload()">Stop</button>
        </div>

        <div class="concurrency-wrapper">
            <span>Concurrent Uploads:</span>
            <select id="concurrencySelect" class="concurrency-select" onchange="handleConcurrencyChange(this)">
                <option value="auto" selected>Automatic (Smart / Dynamic Auto-Bandwidth)</option>
                <option value="1">1 File (Sequential)</option>
                <option value="4">4 Files Concurrently</option>
                <option value="8">8 Files Concurrently</option>
                <option value="16">16 Files Concurrently</option>
                <option value="32">32 Files Concurrently</option>
                <option value="64">64 Files Concurrently (Ultra Fast)</option>
                <option value="128">128 Files Concurrently (Extreme)</option>
                <option value="custom">Enter Custom Value...</option>
            </select>
        </div>
    </div>

    <input type="file" id="fileInput" class="file-input-hidden" multiple>
    <input type="file" id="folderInput" class="file-input-hidden" webkitdirectory mozdirectory directory multiple>

    <div class="status-row">
        <span id="counter">Idle</span>
        <span id="percent">0%</span>
    </div>
    <div class="progress-bg"><div class="progress-bar" id="pbar"></div></div>
    <div class="metric-row">
        <span id="speedMetric">Speed: --</span>
        <span id="etaMetric">Time Remaining: --</span>
        <span id="sizeMetric">0 B / 0 B</span>
    </div>

    <div class="log-header">
        <span class="log-title">Process Log</span>
        <div class="log-actions">
            <button class="log-btn" type="button" onclick="showServerLog()">Read Server log.json</button>
            <button class="log-btn" type="button" onclick="clearServerLog()">Clear log.json</button>
            <button class="log-btn" type="button" onclick="document.getElementById('logBox').innerHTML = ''">Clear Screen</button>
        </div>
    </div>
    <div class="log" id="logBox"></div>
</div>

<script>
const TARGET_URL = window.location.pathname;
const CHUNK_SIZE = 1.5 * 1024 * 1024;
const MAX_RETRIES = 3;

let queue = [];
let totalCount = 0;
let processedCount = 0;
let isRunning = false;

let totalBytes = 0;
let uploadedBytesCommitted = 0;
let startTime = null;

let activeWorkers = 0;
const activeFilesMap = new Map();
const activeXhrsSet = new Set();

// Automatic Dynamic Speed Algorithm
let isAutoMode = true;
let dynamicConcurrency = 6;
let customConcurrencyValue = 20;
let successStreak = 0;
const MIN_AUTO_CONCURRENCY = 2;

const dropArea = document.getElementById('dropArea');
const fileInput = document.getElementById('fileInput');
const folderInput = document.getElementById('folderInput');
const concurrencySelect = document.getElementById('concurrencySelect');
const logBox = document.getElementById('logBox');
const pbar = document.getElementById('pbar');
const percent = document.getElementById('percent');
const counter = document.getElementById('counter');
const stopBtn = document.getElementById('stopBtn');
const speedMetric = document.getElementById('speedMetric');
const etaMetric = document.getElementById('etaMetric');
const sizeMetric = document.getElementById('sizeMetric');

function log(msg, type = '') {
    const el = document.createElement('div');
    if (type) el.className = type;
    el.textContent = `[${new Date().toLocaleTimeString()}] ` + msg;
    logBox.prepend(el);
}

function formatBytes(bytes) {
    if (!bytes || bytes <= 0 || isNaN(bytes)) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(sizes.length - 1, Math.floor(Math.log(bytes) / Math.log(k)));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function formatTime(seconds) {
    if (!isFinite(seconds) || seconds <= 0) return 'Calculating...';
    if (seconds < 60) return Math.round(seconds) + ' s';
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    if (m >= 60) {
        const h = Math.floor(m / 60);
        return `${h} h ${m % 60} m`;
    }
    return `${m} m ${s} s`;
}

function handleConcurrencyChange(select) {
    if (select.value === 'custom') {
        const userInput = prompt('Enter the number of concurrent files to upload in parallel (e.g., 25, 50, 150):', '30');
        const parsed = parseInt(userInput, 10);
        if (parsed && parsed > 0) {
            customConcurrencyValue = parsed;
            let customOption = select.querySelector('option[data-custom="true"]');
            if (!customOption) {
                customOption = document.createElement('option');
                customOption.setAttribute('data-custom', 'true');
                select.insertBefore(customOption, select.lastElementChild);
            }
            customOption.value = 'custom_' + parsed;
            customOption.textContent = `${parsed} Files Concurrently (Custom)`;
            customOption.selected = true;
            isAutoMode = false;
        } else {
            select.value = 'auto';
            isAutoMode = true;
        }
    } else if (select.value === 'auto') {
        isAutoMode = true;
    } else {
        isAutoMode = false;
    }
    if (isRunning) spawnWorkers();
}

function getCurrentConcurrencyTarget() {
    if (isAutoMode) {
        return dynamicConcurrency;
    }
    const val = concurrencySelect.value;
    if (val.startsWith('custom_')) {
        return customConcurrencyValue;
    }
    return parseInt(val, 10) || 6;
}

// Dynamic Speed Auto-adjustment
function recordChunkSuccess() {
    if (!isAutoMode) return;
    successStreak++;
    // Increase concurrency by 1 for every 4 consecutive successful chunk uploads
    if (successStreak >= 4) {
        successStreak = 0;
        dynamicConcurrency++;
        spawnWorkers();
    }
}

function recordChunkError() {
    if (!isAutoMode) return;
    successStreak = 0;
    // On failure or congestion, reduce concurrency by 30% safely
    dynamicConcurrency = Math.max(MIN_AUTO_CONCURRENCY, Math.floor(dynamicConcurrency * 0.7));
}

fileInput.addEventListener('change', (e) => {
    if (e.target.files && e.target.files.length > 0) {
        log(`File selector triggered: ${e.target.files.length} file(s).`);
        handleFileList(e.target.files);
    }
});

folderInput.addEventListener('change', (e) => {
    if (e.target.files && e.target.files.length > 0) {
        log(`Folder selector triggered: ${e.target.files.length} file(s).`);
        handleFileList(e.target.files);
    }
});

function handleFileList(files) {
    if (!files || !files.length) {
        log('Selected file list is empty!', 'warn');
        return;
    }
    const fileList = [];
    for (let i = 0; i < files.length; i++) {
        const f = files[i];
        fileList.push({
            id: 'f_' + i + '_' + Date.now(),
            file: f,
            path: (f.webkitRelativePath ? f.webkitRelativePath : f.name)
        });
    }
    startQueue(fileList);
}

function startQueue(items) {
    totalBytes = 0;
    queue = items;
    items.forEach(it => totalBytes += it.file.size);

    totalCount = queue.length;
    processedCount = 0;
    uploadedBytesCommitted = 0;
    activeFilesMap.clear();
    activeXhrsSet.clear();
    activeWorkers = 0;

    dynamicConcurrency = 6;
    successStreak = 0;
    isAutoMode = (concurrencySelect.value === 'auto');

    isRunning = true;
    startTime = Date.now();
    stopBtn.style.display = 'inline-block';

    const modeText = isAutoMode ? 'Automatic (Dynamic Auto-Bandwidth)' : `${getCurrentConcurrencyTarget()} channels fixed`;
    log(`Queue prepared: ${totalCount} file(s) (${formatBytes(totalBytes)}). Mode: ${modeText}.`);

    updateProgress();
    spawnWorkers();
}

function spawnWorkers() {
    if (!isRunning) return;
    const targetConcurrency = getCurrentConcurrencyTarget();

    while (isRunning && activeWorkers < targetConcurrency && queue.length > 0) {
        activeWorkers++;
        const item = queue.shift();
        uploadSingleFile(item, () => {
            activeWorkers--;
            processedCount++;
            updateProgress();

            if (queue.length > 0) {
                spawnWorkers();
            } else if (activeWorkers === 0) {
                finishQueue();
            }
        });
    }

    if (activeWorkers === 0 && queue.length === 0 && totalCount > 0) {
        finishQueue();
    }
}

function finishQueue() {
    isRunning = false;
    stopBtn.style.display = 'none';
    pbar.style.width = '100%';
    percent.textContent = '100%';
    counter.textContent = 'All Uploads Completed!';
    speedMetric.textContent = 'Speed: Completed';
    etaMetric.textContent = 'Time Remaining: 0 s';
    sizeMetric.textContent = `${formatBytes(totalBytes)} / ${formatBytes(totalBytes)}`;
    log('All files in the queue have been uploaded successfully.', 'ok');
}

function stopUpload() {
    isRunning = false;
    queue = [];
    activeXhrsSet.forEach(xhr => xhr.abort());
    activeXhrsSet.clear();
    activeFilesMap.clear();
    activeWorkers = 0;

    stopBtn.style.display = 'none';
    counter.textContent = 'Stopped';
    speedMetric.textContent = 'Speed: --';
    etaMetric.textContent = 'Time Remaining: --';
    log('Upload aborted by user.', 'warn');
}

function updateProgress() {
    let activeBytes = 0;
    activeFilesMap.forEach(val => activeBytes += val);

    const currentTotal = Math.min(totalBytes, uploadedBytesCommitted + activeBytes);
    const pct = totalBytes > 0 ? Math.min(99, Math.round((currentTotal / totalBytes) * 100)) : 0;

    pbar.style.width = pct + '%';
    percent.textContent = pct + '%';
    sizeMetric.textContent = `${formatBytes(currentTotal)} / ${formatBytes(totalBytes)}`;

    if (isRunning) {
        const autoInfo = isAutoMode ? ` | Auto: ${dynamicConcurrency} channels` : '';
        counter.textContent = `[${processedCount}/${totalCount}] Uploading (${activeWorkers} active${autoInfo})`;
    }

    const elapsedSec = (Date.now() - startTime) / 1000;
    if (elapsedSec > 0.5 && isRunning) {
        const speedBps = currentTotal / elapsedSec;
        speedMetric.textContent = `Speed: ${formatBytes(speedBps)}/s`;
        const remainingBytes = Math.max(0, totalBytes - currentTotal);
        etaMetric.textContent = `Remaining: ~${formatTime(remainingBytes / speedBps)}`;
    }
}

function uploadSingleFile(item, onComplete) {
    const file = item.file;
    const isZeroByte = file.size === 0;
    const totalChunks = isZeroByte ? 1 : Math.ceil(file.size / CHUNK_SIZE);

    activeFilesMap.set(item.id, 0);

    function uploadChunk(chunkIndex, retryCount) {
        if (!isRunning) {
            activeFilesMap.delete(item.id);
            onComplete();
            return;
        }

        const start = chunkIndex * CHUNK_SIZE;
        const end = Math.min(file.size, start + CHUNK_SIZE);
        const chunkBlob = isZeroByte ? new Blob([]) : file.slice(start, end);

        const fd = new FormData();
        fd.append('action', 'chunk_upload');
        fd.append('relative_path', item.path);
        fd.append('file_name', file.name);
        fd.append('chunk_index', chunkIndex);
        fd.append('total_chunks', totalChunks);
        fd.append('is_zero_byte', isZeroByte ? '1' : '0');

        if (!isZeroByte) {
            fd.append('chunk', chunkBlob, file.name);
        }

        const xhr = new XMLHttpRequest();
        activeXhrsSet.add(xhr);

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                const fileLoaded = (chunkIndex * CHUNK_SIZE) + e.loaded;
                activeFilesMap.set(item.id, fileLoaded);
                updateProgress();
            }
        };

        xhr.onload = () => {
            activeXhrsSet.delete(xhr);
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        recordChunkSuccess();
                        if (chunkIndex + 1 < totalChunks) {
                            uploadChunk(chunkIndex + 1, 0);
                        } else {
                            uploadedBytesCommitted += file.size;
                            activeFilesMap.delete(item.id);
                            log(`[SUCCESS] ${item.path} (${formatBytes(file.size)})`, 'ok');
                            onComplete();
                        }
                    } else {
                        recordChunkError();
                        handleError(res.error ? res.error : 'Unknown API error');
                    }
                } catch (err) {
                    recordChunkError();
                    handleError('Server returned an invalid JSON response.');
                }
            } else {
                recordChunkError();
                handleError(`HTTP ${xhr.status} error: ` + xhr.statusText);
            }
        };

        xhr.onerror = () => {
            activeXhrsSet.delete(xhr);
            recordChunkError();
            handleError('Network error or connection refused. If permission issue, check chmod 777.');
        };

        function handleError(errorMsg) {
            if (retryCount < MAX_RETRIES) {
                const nextRetry = retryCount + 1;
                log(`[RETRYING ${nextRetry}/${MAX_RETRIES}] ${item.path} -> ${errorMsg}`, 'warn');
                setTimeout(() => uploadChunk(chunkIndex, nextRetry), 1200);
            } else {
                log(`[FAILED] ${item.path} -> ${errorMsg}`, 'err');
                uploadedBytesCommitted += file.size;
                activeFilesMap.delete(item.id);
                onComplete();
            }
        }

        xhr.open('POST', TARGET_URL, true);
        xhr.send(fd);
    }

    uploadChunk(0, 0);
}

// Drag & Drop
['dragenter', 'dragover'].forEach(name => {
    dropArea.addEventListener(name, (e) => { e.preventDefault(); e.stopPropagation(); dropArea.classList.add('dragover'); }, false);
});
['dragleave', 'drop'].forEach(name => {
    dropArea.addEventListener(name, (e) => { e.preventDefault(); e.stopPropagation(); dropArea.classList.remove('dragover'); }, false);
});

dropArea.addEventListener('drop', async (e) => {
    const dt = e.dataTransfer;
    if (!dt) return;

    if (dt.items && dt.items.length > 0) {
        const hasWebkit = Array.from(dt.items).some(item => typeof item.webkitGetAsEntry === 'function' && item.webkitGetAsEntry() !== null);
        if (hasWebkit) {
            await parseDroppedItems(dt.items);
            return;
        }
    }

    if (dt.files && dt.files.length > 0) {
        handleFileList(dt.files);
    }
});

async function parseDroppedItems(items) {
    const files = [];

    async function readAllEntries(dirReader) {
        let allEntries = [];
        let batch;
        do {
            batch = await new Promise(res => dirReader.readEntries(res, () => res([])));
            if (batch && batch.length > 0) {
                allEntries = allEntries.concat(batch);
            }
        } while (batch && batch.length > 0);
        return allEntries;
    }

    async function traverse(item, path = '') {
        if (!item) return;
        if (item.isFile) {
            const f = await new Promise(res => item.file(res, () => res(null)));
            if (f) files.push({ file: f, path: path + f.name });
        } else if (item.isDirectory) {
            const dirReader = item.createReader();
            const entries = await readAllEntries(dirReader);
            for (const entry of entries) {
                await traverse(entry, path + item.name + '/');
            }
        }
    }

    for (let i = 0; i < items.length; i++) {
        const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
        if (entry) await traverse(entry);
    }

    if (files.length > 0) {
        startQueue(files.map((it, idx) => ({ id: 'f_' + idx + '_' + Date.now(), file: it.file, path: it.path })));
    }
}

// Server log.json Viewer & Clearer
function showServerLog() {
    fetch(TARGET_URL + '?get_log=1')
        .then(r => r.json())
        .then(data => {
            logBox.innerHTML = '';
            if (!data.length) {
                log('[LOG] log.json is currently empty.', 'warn');
                return;
            }
            data.slice().reverse().forEach(entry => {
                log(`[SERVER LOG ${entry.timestamp}] ${entry.action} -> ` + JSON.stringify(entry.data));
            });
        })
        .catch(err => log('Could not read log.json: ' + err, 'err'));
}

function clearServerLog() {
    fetch(TARGET_URL + '?clear_log=1')
        .then(() => {
            log('Server log.json cleared.', 'ok');
        });
}
</script>
</body>
</html>
