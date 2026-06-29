<?php
/**
 * API Entry Point - RESTful API for AI SEO Site
 * Routes: /api/articles, /api/niches, /api/queue, /api/generate, /api/stats
 */

$config = require __DIR__ . '/config.php';
require __DIR__ . '/src/Storage.php';
require __DIR__ . '/src/Security.php';
require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/OpenRouterClient.php';
require __DIR__ . '/src/SeoArticleBuilder.php';
require __DIR__ . '/src/ApiController.php';

$storage = new Storage($config['storage_path']);
$logger = new Logger($config['storage_path'] . '/logs');

// Generate API token if not set
$apiTokenFile = $config['storage_path'] . '/settings/api_token.json';
if (!is_file($apiTokenFile)) {
    $tokenData = ['token' => bin2hex(random_bytes(32)), 'created_at' => gmdate(DATE_ATOM)];
    $dir = dirname($apiTokenFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($apiTokenFile, json_encode($tokenData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

$tokenData = json_decode((string)file_get_contents($apiTokenFile), true);
$apiToken = $tokenData['token'] ?? '';

// Initialize API controller
$ai = new OpenRouterClient($config['openrouter'], $storage);
$builder = new SeoArticleBuilder($ai, $storage, $config);

$api = new ApiController($storage, $ai, $builder, $logger, $config, $apiToken);
$api->handle();
