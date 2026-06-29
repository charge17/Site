<?php
/**
 * Cache layer for performance optimization using file-based storage
 */
final class Cache
{
    private string $cacheDir;
    private int $defaultTtl = 3600; // 1 hour

    public function __construct(string $cacheDir, int $ttl = 3600)
    {
        $this->cacheDir = $cacheDir;
        $this->defaultTtl = $ttl;
        $this->ensureCacheDir();
    }

    private function ensureCacheDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get a cached value
     */
    public function get(string $key): mixed
    {
        $file = $this->getCachePath($key);
        
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($file), true);
        
        if (!is_array($data)) {
            return null;
        }

        // Check if expired
        if (isset($data['expires_at']) && time() > $data['expires_at']) {
            unlink($file);
            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Set a cached value
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $file = $this->getCachePath($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            'key' => $key,
            'value' => $value,
            'created_at' => gmdate(DATE_ATOM),
            'expires_at' => time() + ($ttl > 0 ? $ttl : $this->defaultTtl),
        ];

        return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
    }

    /**
     * Check if a key exists in cache
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Delete a cached value
     */
    public function delete(string $key): bool
    {
        $file = $this->getCachePath($key);
        
        if (!is_file($file)) {
            return false;
        }

        return unlink($file);
    }

    /**
     * Clear all cache
     */
    public function clear(): int
    {
        $deleted = 0;
        
        foreach (glob($this->cacheDir . '/**/*.json', GLOB_RECURSIVE) ?: [] as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Clear expired cache entries
     */
    public function clearExpired(): int
    {
        $deleted = 0;
        
        foreach (glob($this->cacheDir . '/**/*.json', GLOB_RECURSIVE) ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            
            if (is_array($data) && isset($data['expires_at']) && time() > $data['expires_at']) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Remember a value - get from cache or compute and store
     */
    public function remember(string $key, callable $callback, int $ttl = 0): mixed
    {
        $cached = $this->get($key);
        
        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    /**
     * Cache statistics
     */
    public function getStats(): array
    {
        $files = glob($this->cacheDir . '/**/*.json', GLOB_RECURSIVE) ?: [];
        $totalSize = 0;
        $expiredCount = 0;

        foreach ($files as $file) {
            $totalSize += filesize($file);
            $data = json_decode((string)file_get_contents($file), true);
            
            if (is_array($data) && isset($data['expires_at']) && time() > $data['expires_at']) {
                $expiredCount++;
            }
        }

        return [
            'total_entries' => count($files),
            'expired_entries' => $expiredCount,
            'total_size_bytes' => $totalSize,
            'cache_dir' => $this->cacheDir,
        ];
    }

    /**
     * Get cache file path
     */
    private function getCachePath(string $key): string
    {
        // Create subdirectories for better organization
        $hash = substr(md5($key), 0, 2);
        return $this->cacheDir . '/' . $hash . '/' . md5($key) . '.json';
    }

    /**
     * Warm cache - precompute commonly accessed data
     */
    public function warm(string $prefix, callable $generator): int
    {
        $count = 0;
        $data = $generator();
        
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $cacheKey = $prefix . ':' . $key;
                if ($this->set($cacheKey, $value)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
