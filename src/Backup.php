<?php
/**
 * Backup and restore functionality for data protection
 */
final class Backup
{
    private string $backupDir;
    private string $dataDir;

    public function __construct(string $dataDir, string $backupDir = '')
    {
        $this->dataDir = $dataDir;
        $this->backupDir = $backupDir ?: dirname($dataDir) . '/backups';
        $this->ensureBackupDir();
    }

    private function ensureBackupDir(): void
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Create a backup of all data
     */
    public function create(string $name = ''): string|false
    {
        $timestamp = gmdate('YmdHis');
        $backupName = $name ?: 'backup-' . $timestamp;
        $backupFile = $this->backupDir . '/' . $backupName . '.tar.gz';

        // Use tar command if available
        if (shell_exec('which tar 2>/dev/null')) {
            $cmd = sprintf(
                'cd %s && tar --exclude="backups" --exclude="logs/archive" -czf %s data/ 2>/dev/null',
                escapeshellarg(dirname($this->dataDir)),
                escapeshellarg($backupFile)
            );
            
            if (shell_exec($cmd) !== null || file_exists($backupFile)) {
                return $backupFile;
            }
        }

        // Fallback: Create ZIP file using PHP
        return $this->createZipBackup($backupName);
    }

    /**
     * Create ZIP backup (fallback for shared hosting)
     */
    private function createZipBackup(string $backupName): string|false
    {
        if (!extension_loaded('zip')) {
            return false;
        }

        $backupFile = $this->backupDir . '/' . $backupName . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $this->addDirToZip($zip, $this->dataDir, 'data/');
        $zip->close();

        return file_exists($backupFile) ? $backupFile : false;
    }

    /**
     * Recursively add directory to ZIP
     */
    private function addDirToZip(ZipArchive $zip, string $dir, string $prefix): void
    {
        $files = scandir($dir);
        
        foreach ($files ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (in_array($file, ['backups', 'archive'], true)) {
                continue;
            }

            $filePath = $dir . '/' . $file;
            $zipPath = $prefix . $file;

            if (is_dir($filePath)) {
                $zip->addEmptyDir($zipPath);
                $this->addDirToZip($zip, $filePath, $zipPath . '/');
            } else {
                $zip->addFile($filePath, $zipPath);
            }
        }
    }

    /**
     * List available backups
     */
    public function listBackups(): array
    {
        $backups = [];
        
        foreach (glob($this->backupDir . '/*.*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $backups[] = [
                'name' => basename($file),
                'size' => filesize($file),
                'created' => filemtime($file),
                'path' => $file,
            ];
        }

        // Sort by creation time, newest first
        usort($backups, fn($a, $b) => $b['created'] - $a['created']);
        
        return $backups;
    }

    /**
     * Restore from backup (ZIP only for safety)
     */
    public function restore(string $backupName): bool
    {
        $backupFile = $this->backupDir . '/' . $backupName;

        if (!file_exists($backupFile)) {
            return false;
        }

        // Only allow ZIP restoration for safety
        if (!str_ends_with($backupFile, '.zip')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($backupFile) !== true) {
            return false;
        }

        // Create temporary extraction directory
        $tempDir = $this->backupDir . '/.restore-' . uniqid();
        
        if (!mkdir($tempDir)) {
            return false;
        }

        if (!$zip->extractTo($tempDir)) {
            $this->removeDir($tempDir);
            return false;
        }

        $zip->close();

        // Backup current data first
        $currentBackup = $this->create('pre-restore-' . gmdate('YmdHis'));
        
        if (!$currentBackup) {
            $this->removeDir($tempDir);
            return false;
        }

        // Move extracted data
        $extractedDataDir = $tempDir . '/data';
        
        if (!is_dir($extractedDataDir)) {
            $this->removeDir($tempDir);
            return false;
        }

        // Replace data directory
        $this->removeDir($this->dataDir);
        rename($extractedDataDir, $this->dataDir);
        $this->removeDir($tempDir);

        return true;
    }

    /**
     * Download backup file
     */
    public function download(string $backupName): bool
    {
        $backupFile = $this->backupDir . '/' . $backupName;

        if (!file_exists($backupFile) || !is_file($backupFile)) {
            return false;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($backupFile) . '"');
        header('Content-Length: ' . filesize($backupFile));
        header('Cache-Control: no-cache, no-store, must-revalidate');

        readfile($backupFile);
        return true;
    }

    /**
     * Delete a backup
     */
    public function delete(string $backupName): bool
    {
        $backupFile = $this->backupDir . '/' . $backupName;

        if (!file_exists($backupFile)) {
            return false;
        }

        return unlink($backupFile);
    }

    /**
     * Get backup info
     */
    public function getInfo(string $backupName): array
    {
        $backupFile = $this->backupDir . '/' . $backupName;

        if (!file_exists($backupFile)) {
            return [];
        }

        return [
            'name' => basename($backupFile),
            'size' => filesize($backupFile),
            'created' => gmdate('Y-m-d H:i:s', filemtime($backupFile)),
            'type' => pathinfo($backupFile, PATHINFO_EXTENSION),
        ];
    }

    /**
     * Auto-cleanup old backups
     */
    public function cleanupOldBackups(int $keepCount = 5): int
    {
        $backups = $this->listBackups();
        $deleted = 0;

        if (count($backups) > $keepCount) {
            $toDelete = array_slice($backups, $keepCount);
            
            foreach ($toDelete as $backup) {
                if ($this->delete($backup['name'])) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Get backup statistics
     */
    public function getStats(): array
    {
        $backups = $this->listBackups();
        $totalSize = array_sum(array_column($backups, 'size'));

        return [
            'backup_count' => count($backups),
            'total_size_bytes' => $totalSize,
            'latest_backup' => $backups[0] ?? null,
            'backup_dir' => $this->backupDir,
        ];
    }

    /**
     * Recursively remove directory
     */
    private function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = scandir($dir);
        
        foreach ($files ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }
}
