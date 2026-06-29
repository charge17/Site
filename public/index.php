<?php
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/Storage.php';
$storage = new Storage($config['storage_path']);
$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($path === 'robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nAllow: /\nSitemap: " . rtrim($config['base_url'], '/') . "/sitemap.xml\n";
    exit;
}
if ($path === 'sitemap.xml') {
    header('Content-Type: application/xml');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">";
    foreach ($storage->listJson('articles') as $article) {
        if (($article['status'] ?? 'draft') === 'published') {
            echo '<url><loc>' . htmlspecialchars(rtrim($config['base_url'], '/') . '/article/' . ($article['slug'] ?? '')) . '</loc></url>';
        }
    }
    echo '</urlset>';
    exit;
}
if (str_starts_with($path, 'article/')) {
    $slug = basename($path);
    $article = $storage->readJson('articles/' . $slug . '.json', null);
    if (!$article) { http_response_code(404); echo 'Not found'; exit; }
    include __DIR__ . '/../src/views/article.php';
    exit;
}
$articles = array_filter($storage->listJson('articles'), fn ($a) => ($a['status'] ?? 'draft') === 'published');
include __DIR__ . '/../src/views/home.php';
