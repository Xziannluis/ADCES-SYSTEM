<?php
/**
 * Auto-start the AI service if it's not already running.
 * Include this file early in any entry-point page (index.php, login.php).
 * Uses a lock file to prevent duplicate start attempts within 30 seconds.
 */
(function () {
    $healthUrl = 'http://127.0.0.1:8001/health';
    $lockFile  = dirname(__DIR__) . '/ai_service/.ai_starting.lock';

    // Quick health check (50ms timeout)
    $ctx = stream_context_create(['http' => ['timeout' => 0.05, 'method' => 'GET']]);
    $resp = @file_get_contents($healthUrl, false, $ctx);
    if ($resp !== false) {
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
        // Write a temp bat file to avoid quoting issues with popen
        $bat = $aiDir . DIRECTORY_SEPARATOR . '_autostart.bat';
        $batContent = '@echo off' . "\r\n"
                    . 'cd /d "' . $aiDir . '"' . "\r\n"
                    . '"' . $pythonExe . '" -m uvicorn app:app --host 127.0.0.1 --port 8001' . "\r\n";
        @file_put_contents($bat, $batContent);
        pclose(popen('start "" /B "' . $bat . '"', 'r'));
    } else {
        $cmd = '"' . $pythonExe . '" -m uvicorn app:app --host 127.0.0.1 --port 8001';
        exec('cd ' . escapeshellarg($aiDir) . ' && ' . $cmd . ' > /dev/null 2>&1 &');
    }
})();
