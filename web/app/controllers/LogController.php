<?php

namespace App\Controllers;

use App\Core\Response;

class LogController
{
    private $logFile;

    public function __construct()
    {
        $this->logFile = dirname(__DIR__, 2) . '/factorio-current.log';
    }

    public function tail(array $params): void
    {
        $lines = (int)($params['lines'] ?? 100);
        $filter = $params['filter'] ?? '';
        $search = $params['search'] ?? '';

        if (!file_exists($this->logFile)) {
            Response::success([
                'lines' => [],
                'total' => 0,
                'message' => '日志文件不存在'
            ]);
        }

        $content = file_get_contents($this->logFile);
        $allLines = explode("\n", $content);

        $result = [];
        $count = 0;

        $allLines = array_reverse($allLines);

        foreach ($allLines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            if (!empty($filter)) {
                $filterLower = strtolower($filter);
                $lineLower = strtolower($line);

                $match = false;
                switch ($filterLower) {
                    case 'chat':
                        $match = strpos($lineLower, '[chat]') !== false;
                        break;
                    case 'join':
                    case 'leave':
                        $match = strpos($lineLower, 'joined the game') !== false ||
                                 strpos($lineLower, 'left the game') !== false;
                        break;
                    case 'save':
                        $match = strpos($lineLower, 'saving game') !== false ||
                                 strpos($lineLower, 'saved') !== false;
                        break;
                    case 'error':
                        $match = strpos($lineLower, 'error') !== false;
                        break;
                    case 'warning':
                        $match = strpos($lineLower, 'warning') !== false;
                        break;
                    default:
                        $match = true;
                }

                if (!$match) {
                    continue;
                }
            }

            if (!empty($search)) {
                if (stripos($line, $search) === false) {
                    continue;
                }
            }

            $result[] = $line;
            $count++;

            if ($count >= $lines) {
                break;
            }
        }

        Response::success([
            'lines' => $result,
            'total' => $count
        ]);
    }

    public function stream(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $lastSize = 0;
        $lastModTime = 0;

        if (file_exists($this->logFile)) {
            $lastSize = filesize($this->logFile);
            $lastModTime = filemtime($this->logFile);
        }

        $timeout = 0;
        $maxTimeout = 300;

        while ($timeout < $maxTimeout) {
            if (connection_aborted()) {
                break;
            }

            if (file_exists($this->logFile)) {
                clearstatcache();
                $currentSize = filesize($this->logFile);
                $currentModTime = filemtime($this->logFile);

                if ($currentSize > $lastSize || $currentModTime > $lastModTime) {
                    $fh = fopen($this->logFile, 'r');
                    if ($fh) {
                        fseek($fh, $lastSize);
                        $newContent = fread($fh, $currentSize - $lastSize);
                        fclose($fh);

                        if (!empty($newContent)) {
                            $lines = explode("\n", $newContent);
                            foreach ($lines as $line) {
                                if (!empty(trim($line))) {
                                    echo "data: " . json_encode(['line' => $line]) . "\n\n";
                                    flush();
                                }
                            }
                        }
                    }

                    $lastSize = $currentSize;
                    $lastModTime = $currentModTime;
                }
            }

            sleep(1);
            $timeout++;
        }

        echo "data: " . json_encode(['event' => 'timeout']) . "\n\n";
        flush();
    }

    public function stats(): void
    {
        if (!file_exists($this->logFile)) {
            Response::success([
                'total_lines' => 0,
                'chat_count' => 0,
                'join_count' => 0,
                'leave_count' => 0,
                'error_count' => 0,
                'warning_count' => 0,
                'file_size' => 0
            ]);
        }

        $content = file_get_contents($this->logFile);
        $lines = explode("\n", $content);

        $stats = [
            'total_lines' => count($lines) - 1,
            'chat_count' => 0,
            'join_count' => 0,
            'leave_count' => 0,
            'error_count' => 0,
            'warning_count' => 0,
            'file_size' => filesize($this->logFile)
        ];

        foreach ($lines as $line) {
            $lineLower = strtolower($line);

            if (strpos($lineLower, '[chat]') !== false) {
                $stats['chat_count']++;
            }
            if (strpos($lineLower, 'joined the game') !== false) {
                $stats['join_count']++;
            }
            if (strpos($lineLower, 'left the game') !== false) {
                $stats['leave_count']++;
            }
            if (strpos($lineLower, 'error') !== false) {
                $stats['error_count']++;
            }
            if (strpos($lineLower, 'warning') !== false) {
                $stats['warning_count']++;
            }
        }

        Response::success($stats);
    }
}
