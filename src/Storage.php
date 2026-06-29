<?php
final class Storage
{
    public function __construct(private string $root) {}

    public function readJson(string $path, mixed $default = []): mixed
    {
        $file = $this->safePath($path);
        if (!is_file($file)) {
            return $default;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    }

    public function writeJson(string $path, mixed $payload): void
    {
        $file = $this->safePath($path);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    public function listJson(string $dir): array
    {
        $base = $this->safePath($dir);
        if (!is_dir($base)) {
            return [];
        }
        $items = [];
        foreach (glob($base . '/*.json') ?: [] as $file) {
            $items[] = $this->readJson($dir . '/' . basename($file), []);
        }
        return $items;
    }


    public function listJsonWithKeys(string $dir): array
    {
        $base = $this->safePath($dir);
        if (!is_dir($base)) {
            return [];
        }
        $items = [];
        foreach (glob($base . '/*.json') ?: [] as $file) {
            $key = basename($file, '.json');
            $items[$key] = $this->readJson($dir . '/' . basename($file), []);
        }
        return $items;
    }

    public function appendLog(string $channel, array $event): void
    {
        $file = $this->safePath('logs/' . preg_replace('/[^a-z0-9_-]/i', '-', $channel) . '.log');
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        $event['time'] = gmdate(DATE_ATOM);
        file_put_contents($file, json_encode($event, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function safePath(string $path): string
    {
        $path = trim(str_replace(['..', '\\'], ['', '/'], $path), '/');
        return $this->root . '/' . $path;
    }
}
