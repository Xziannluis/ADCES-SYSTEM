<?php
/**
 * Auto-start the AI service if it's not already running.
 * Include this file early in any entry-point page (index.php, login.php)
 * and in the AI proxy controller for on-demand startup.
 * Uses a lock file to prevent duplicate start attempts within 30 seconds.
 */
(function () {
    $healthUrl = 'http://127.0.0.1:8001/health';
    $lockFile  = dirname(__DIR__) . '/ai_service/.ai_starting.lock';

    // Quick health check (200ms connect timeout)
    $ch = curl_init($healthUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 200,
        CURLOPT_TIMEOUT_MS => 500,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);

    if ($resp !== false && $err === 0) {
        @unlink($lockFile);
        return;
    }

    // Prevent multiple simultaneous start attempts (lock for 30s)
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 30) {
        return;
    }
    @file_put_contents($lockFile, date('c'));

    $root      = dirname(__DIR__);
    $pythonExe = $root . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
    $aiDir     = $root . DIRECTORY_SEPARATOR . 'ai_service';

    if (!file_exists($pythonExe)) {
        @unlink($lockFile);
        return;
    }

    // Start the AI service in the background (fire-and-forget)
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Use wmic to launch the process with correct working directory
        $cmd = 'wmic process call create "cmd /c cd /d \"' . $aiDir . '\" && \"' . $pythonExe . '\" -m uvicorn app:app --host 127.0.0.1 --port 8001"';
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = '"' . $pythonExe . '" -m uvicorn app:app --host 127.0.0.1 --port 8001';
        exec('cd ' . escapeshellarg($aiDir) . ' && ' . $cmd . ' > /dev/null 2>&1 &');
    }
})();
