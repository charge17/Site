<?php
/**
 * REST API for programmatic access and automation
 */
final class ApiController
{
    private Storage $storage;
    private OpenRouterClient $ai;
    private SeoArticleBuilder $builder;
    private Logger $logger;
    private Security $security;
    private array $config;
    private string $apiToken;

    public function __construct(
        Storage $storage,
        OpenRouterClient $ai,
        SeoArticleBuilder $builder,
        Logger $logger,
        array $config,
        string $apiToken
    ) {
        $this->storage = $storage;
        $this->ai = $ai;
        $this->builder = $builder;
        $this->logger = $logger;
        $this->config = $config;
        $this->apiToken = $apiToken;
    }

    /**
     * Handle API request
     */
    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            // Verify API token
            if (!$this->verifyAuth()) {
                $this->respondError('Unauthorized', 401);
            }

            // Rate limiting
            $clientId = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if (Security::checkRateLimit('api:' . $clientId, 100, 60)) {
                $this->respondError('Rate limit exceeded', 429);
            }

            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
            
            // Route API requests
            $this->route($method, $path);
            
        } catch (Throwable $e) {
            $this->logger->error('API Error: ' . $e->getMessage());
            $this->respondError($e->getMessage(), 500);
        }
    }

    /**
     * Route API requests
     */
    private function route(string $method, string $path): void
    {
        $segments = array_filter(explode('/', $path));
        
        if (empty($segments) || $segments[0] !== 'api') {
            $this->respondError('Not found', 404);
        }

        $action = $segments[1] ?? '';

        match ($action) {
            'articles' => $this->handleArticles($method, $segments),
            'niches' => $this->handleNiches($method, $segments),
            'queue' => $this->handleQueue($method, $segments),
            'generate' => $this->handleGenerate($method),
            'stats' => $this->handleStats($method),
            'health' => $this->respondSuccess(['status' => 'ok']),
            default => $this->respondError('Endpoint not found', 404),
        };
    }

    /**
     * Handle articles endpoint
     */
    private function handleArticles(string $method, array $segments): void
    {
        if ($method === 'GET') {
            $articles = array_filter(
                $this->storage->listJson('articles'),
                fn($a) => ($a['status'] ?? 'draft') === 'published'
            );
            $this->respondSuccess($articles);
        }

        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['slug'])) {
                $this->respondError('slug is required', 400);
            }

            $article = $this->storage->readJson('articles/' . basename($input['slug']) . '.json');
            
            if (!$article) {
                $this->respondError('Article not found', 404);
            }

            $this->respondSuccess($article);
        }
    }

    /**
     * Handle niches endpoint
     */
    private function handleNiches(string $method, array $segments): void
    {
        if ($method === 'GET') {
            $niches = $this->storage->listJson('niches');
            $this->respondSuccess($niches);
        }

        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['name'])) {
                $this->respondError('name is required', 400);
            }

            $slug = Security::validateSlug($input['slug'] ?? $input['name']);
            
            $niche = [
                'slug' => $slug,
                'name' => $input['name'],
                'keywords' => array_filter((array)($input['keywords'] ?? [])),
                'audience' => $input['audience'] ?? '',
                'tone' => $input['tone'] ?? 'خبير وعملي',
                'created_at' => gmdate(DATE_ATOM),
            ];

            $this->storage->writeJson('niches/' . $slug . '.json', $niche);
            $this->respondSuccess($niche, 201);
        }
    }

    /**
     * Handle queue endpoint
     */
    private function handleQueue(string $method, array $segments): void
    {
        if ($method === 'GET') {
            $queue = $this->storage->listJson('queue');
            
            // Filter by status if provided
            $status = $_GET['status'] ?? '';
            if ($status) {
                $queue = array_filter($queue, fn($job) => ($job['status'] ?? '') === $status);
            }

            $this->respondSuccess(array_values($queue));
        }

        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['niche'])) {
                $this->respondError('niche is required', 400);
            }

            $jobId = 'job-' . uniqid();
            $job = [
                'id' => $jobId,
                'type' => $input['type'] ?? 'article',
                'niche' => $input['niche'],
                'status' => 'pending',
                'created_at' => gmdate(DATE_ATOM),
            ];

            $this->storage->writeJson('queue/' . $jobId . '.json', $job);
            $this->respondSuccess($job, 201);
        }
    }

    /**
     * Handle generate endpoint (legacy for backward compatibility)
     */
    private function handleGenerate(string $method): void
    {
        if ($method !== 'POST') {
            $this->respondError('Method not allowed', 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['niche'])) {
            $this->respondError('niche is required', 400);
        }

        $niche = $input['niche'];
        if (is_string($niche)) {
            $niche = $this->storage->readJson('niches/' . basename($niche) . '.json');
            
            if (!$niche) {
                $this->respondError('Niche not found', 404);
            }
        }

        try {
            $startTime = microtime(true);
            $article = $this->builder->createFromNiche($niche);
            $duration = microtime(true) - $startTime;

            $this->logger->articleGenerated(
                $article['slug'] ?? '',
                $niche['name'] ?? '',
                $article['model_usage'][0] ?? 'unknown',
                $duration
            );

            $this->respondSuccess([
                'article' => $article,
                'duration' => $duration,
            ], 201);
        } catch (Throwable $e) {
            $this->logger->error('Article generation failed: ' . $e->getMessage());
            $this->respondError('Generation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Handle stats endpoint
     */
    private function handleStats(string $method): void
    {
        if ($method !== 'GET') {
            $this->respondError('Method not allowed', 405);
        }

        $articles = $this->storage->listJson('articles');
        $niches = $this->storage->listJson('niches');
        $queue = $this->storage->listJson('queue');

        $stats = [
            'articles' => count($articles),
            'published' => count(array_filter($articles, fn($a) => ($a['status'] ?? '') === 'published')),
            'niches' => count($niches),
            'queue' => count($queue),
            'queue_pending' => count(array_filter($queue, fn($j) => ($j['status'] ?? '') === 'pending')),
            'queue_processing' => count(array_filter($queue, fn($j) => ($j['status'] ?? '') === 'processing')),
            'queue_failed' => count(array_filter($queue, fn($j) => ($j['status'] ?? '') === 'failed')),
        ];

        $this->respondSuccess($stats);
    }

    /**
     * Verify API authentication
     */
    private function verifyAuth(): bool
    {
        $token = $_SERVER['HTTP_X_API_TOKEN'] ?? $_GET['token'] ?? '';
        return hash_equals($token, $this->apiToken);
    }

    /**
     * Send success response
     */
    private function respondSuccess(mixed $data, int $code = 200): never
    {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send error response
     */
    private function respondError(string $message, int $code = 400): never
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
