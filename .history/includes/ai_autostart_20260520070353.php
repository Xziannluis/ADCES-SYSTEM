<?php
/**
 * Auto-start the AI service if it's not already running.
 * Include this file early in any entry-point page (index.php, login.php)
 * and in the AI proxy controller for on-demand startup.
 * Uses a lock file and process check to prevent duplicate start attempts.
 */
(function () {
    $healthUrl = 'http://127.0.0.1:8001/health';
    $lockFile  = dirname(__DIR__) . '/ai_service/.ai_starting.lock';
    $lockTtlSeconds = 45;
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

    $psQuote = static function (string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    };

    // WMI-based process checks are unreliable in restricted Windows environments.
    // We rely on health + lock to avoid duplicate starts.
    $isAiServiceProcessRunning = static function (): bool {
        return false;
    };

    $isHealthy = static function () use ($healthUrl): bool {
        if (!function_exists('curl_init')) return false;
        $ch = curl_init($healthUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 250,
            CURLOPT_TIMEOUT_MS => 700,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);
        return ($resp !== false && $err === 0);
    };

    // Quick health check
    $respOk = $isHealthy();

    if ($respOk) {
        @unlink($lockFile);
        return;
    }

    // Uvicorn can take a while to finish loading models. If it is already
    // starting, do not open another command window from index/login reloads.
    if ($isAiServiceProcessRunning()) {
        @touch($lockFile);
        return;
    }

    // Prevent repeated start attempts while the first service is still booting.
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < $lockTtlSeconds) {
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
    if ($isWindows) {
        $bat = $aiDir . DIRECTORY_SEPARATOR . '_autostart.bat';
        if (file_exists($bat)) {
            // Use start /b to run completely hidden in background without new window
            @pclose(@popen('cmd /c start /b /min "" "' . $bat . '"', 'r'));
        } else {
            $outLog = $aiDir . DIRECTORY_SEPARATOR . 'autostart.out.log';
            $errLog = $aiDir . DIRECTORY_SEPARATOR . 'autostart.err.log';
            $ps = 'Start-Process -FilePath ' . $psQuote($pythonExe) .
                ' -ArgumentList "-m uvicorn app:app --host 127.0.0.1 --port 8001"' .
                ' -WorkingDirectory ' . $psQuote($aiDir) .
                ' -RedirectStandardOutput ' . $psQuote($outLog) .
                ' -RedirectStandardError ' . $psQuote($errLog) .
                ' -WindowStyle Hidden -CreateNoWindow';
            $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command ' . escapeshellarg($ps);
            @pclose(@popen($cmd, 'r'));
        }
    } else {
        $cmd = '"' . $pythonExe . '" -m uvicorn app:app --host 127.0.0.1 --port 8001';
        exec('cd ' . escapeshellarg($aiDir) . ' && ' . $cmd . ' > /dev/null 2>&1 &');
    }
})();
