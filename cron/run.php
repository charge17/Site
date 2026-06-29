<?php
$config = require __DIR__ . '/../config.php';
if (PHP_SAPI !== 'cli' && ($_GET['token'] ?? '') !== $config['cron_token']) {
    http_response_code(403); exit('Forbidden');
}
require __DIR__ . '/../src/Storage.php';
require __DIR__ . '/../src/OpenRouterClient.php';
require __DIR__ . '/../src/SeoArticleBuilder.php';
$storage = new Storage($config['storage_path']);
$builder = new SeoArticleBuilder(new OpenRouterClient($config['openrouter'], $storage), $storage, $config);
foreach ($storage->listJsonWithKeys('queue') as $jobId => $job) {
    if (($job['status'] ?? 'pending') !== 'pending') { continue; }
    $niche = $job['niche'] ?? ['name' => $job['question'] ?? 'عام'];
    $job['status'] = 'processing';
    $job['started_at'] = gmdate(DATE_ATOM);
    $storage->writeJson('queue/' . $jobId . '.json', $job);
    try {
        $article = $builder->createFromNiche($niche);
        $job['status'] = 'done';
        $job['article_slug'] = $article['slug'] ?? '';
        $job['finished_at'] = gmdate(DATE_ATOM);
        $storage->writeJson('queue/' . $jobId . '.json', $job);
        echo "processed\n";
    } catch (Throwable $e) {
        $job['status'] = 'failed';
        $job['error'] = $e->getMessage();
        $job['finished_at'] = gmdate(DATE_ATOM);
        $storage->writeJson('queue/' . $jobId . '.json', $job);
        throw $e;
    }
    break;
}
