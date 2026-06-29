<?php
/**
 * Logger for tracking events, errors, and performance metrics
 */
final class Logger
{
    private string $logDir;
    private static Logger|null $instance = null;

    public function __construct(string $logDir)
    {
        $this->logDir = $logDir;
        $this->ensureLogDir();
    }

    public static function getInstance(string $logDir = ''): self
    {
        if (self::$instance === null) {
            self::$instance = new self($logDir);
        }
        return self::$instance;
    }

    private function ensureLogDir(): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Log an error event
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * Log a warning event
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /**
     * Log an info event
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * Log a debug event
     */
    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    /**
     * Log an API call
     */
    public function apiCall(string $method, string $endpoint, int $statusCode, float $duration, array $context = []): void
    {
        $this->write('api', "$method $endpoint [$statusCode] in {$duration}ms", $context);
    }

    /**
     * Log article generation
     */
    public function articleGenerated(string $slug, string $niche, string $model, float $duration): void
    {
        $this->write('article', "Generated: $slug from $niche using $model in {$duration}s", ['slug' => $slug, 'niche' => $niche, 'model' => $model]);
    }

    /**
     * Log failed model
     */
    public function modelFailure(string $model, string $error): void
    {
        $this->write('ai-failover', "Model $model failed: $error", ['model' => $model]);
    }

    /**
     * Internal write method
     */
    private function write(string $level, string $message, array $context = []): void
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $user = $_SESSION['user_id'] ?? 'anon';
        
        $logEntry = [
            'timestamp' => $timestamp,
            'level' => strtoupper($level),
            'ip' => $ip,
            'user' => $user,
            'message' => $message,
        ];

        if (!empty($context)) {
            $logEntry['context'] = $context;
        }

        $logFile = $this->logDir . '/' . $level . '.log';
        $line = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n";
        
        error_log($line, 3, $logFile);
    }

    /**
     * Get recent logs
     */
    public function getRecent(string $level, int $lines = 50): array
    {
        $logFile = $this->logDir . '/' . $level . '.log';
        
        if (!is_file($logFile)) {
            return [];
        }

        $logs = [];
        $handle = fopen($logFile, 'r');
        
        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $data = json_decode(trim($line), true);
            if (is_array($data)) {
                $logs[] = $data;
            }
        }

        fclose($handle);
        
        // Return last N entries
        return array_slice($logs, -$lines);
    }

    /**
     * Archive old logs
     */
    public function archiveOldLogs(int $daysOld = 30): int
    {
        $archived = 0;
        $cutoff = time() - ($daysOld * 86400);
        
        foreach (glob($this->logDir . '/*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                $archiveDir = $this->logDir . '/archive';
                if (!is_dir($archiveDir)) {
                    mkdir($archiveDir, 0755, true);
                }
                
                $newName = $archiveDir . '/' . basename($file, '.log') . '-' . gmdate('Y-m-d', filemtime($file)) . '.log.gz';
                
                // Compress and move
                if (gzwrite(gzopen($newName, 'wb'), file_get_contents($file)) !== false) {
                    unlink($file);
                    $archived++;
                }
            }
        }
        
        return $archived;
    }

    /**
     * Get log statistics
     */
    public function getStats(): array
    {
        $stats = [];
        
        foreach (['error', 'warning', 'info', 'debug', 'api', 'article', 'ai-failover'] as $level) {
            $logFile = $this->logDir . '/' . $level . '.log';
            
            if (is_file($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES);
                $stats[$level] = count($lines);
            } else {
                $stats[$level] = 0;
            }
        }
        
        return $stats;
    }
}
