<?php
final class SeoArticleBuilder
{
    public function __construct(private OpenRouterClient $ai, private Storage $storage, private array $config) {}

    public function createFromNiche(array $niche): array
    {
        $usedModels = [];
        $brief = $this->planArticle($niche, $usedModels);
        $title = $brief['meta_title'] ?? ($brief['title'] ?? 'مقال SEO');
        $headings = array_slice($brief['headings'] ?? [], 0, 20);
        $parts = [
            $this->writePart('اكتب مقدمة قوية و 6 أقسام أولى', $title, array_slice($headings, 0, 6), $usedModels),
            $this->writePart('اكتب 8 أقسام وسطى بجداول ومقارنات ونقاط مهمة', $title, array_slice($headings, 6, 8), $usedModels),
            $this->writePart('اكتب 6 أقسام أخيرة وخاتمة و CTA', $title, array_slice($headings, 14, 6), $usedModels),
        ];
        $article = $this->assemble($niche, $brief, $parts);
        $slug = $this->slug($title);
        $article['slug'] = $slug;
        $article['created_at'] = gmdate(DATE_ATOM);
        $article['model_usage'] = $usedModels;
        $this->storage->writeJson('articles/' . $slug . '.json', $article);
        foreach (($article['faq'] ?? []) as $faq) {
            $this->enqueueArticleFromFaq($niche, $faq['question'] ?? '');
        }
        return $article;
    }

    private function planArticle(array $niche, array &$usedModels): array
    {
        $prompt = 'أنت خبير SEO. أنشئ JSON فقط يحتوي: title, meta_title, meta_description, headings 20 عنصر, faq 8 عناصر question/answer, keywords, cta. النيش: ' . json_encode($niche, JSON_UNESCAPED_UNICODE);
        return $this->jsonAi([['role' => 'user', 'content' => $prompt]], [], $usedModels);
    }

    private function writePart(string $task, string $title, array $headings, array &$usedModels): array
    {
        $prompt = $task . ' للمقال: ' . $title . '. العناوين: ' . json_encode($headings, JSON_UNESCAPED_UNICODE) . '. أعد JSON فقط: sections array heading/html, tables, cta_blocks.';
        $avoid = count($usedModels) < count($this->config['openrouter']['free_models']) ? $usedModels : [];
        return $this->jsonAi([['role' => 'user', 'content' => $prompt]], $avoid, $usedModels);
    }

    private function assemble(array $niche, array $brief, array $parts): array
    {
        $sections = [];
        foreach ($parts as $part) {
            foreach (($part['sections'] ?? []) as $section) {
                $sections[] = $section;
            }
        }
        return [
            'status' => 'draft',
            'niche' => $niche['name'] ?? 'عام',
            'title' => $brief['title'] ?? $brief['meta_title'] ?? 'مقال',
            'meta_title' => $brief['meta_title'] ?? $brief['title'] ?? 'مقال',
            'meta_description' => $brief['meta_description'] ?? '',
            'keywords' => $brief['keywords'] ?? [],
            'sections' => $sections,
            'faq' => $brief['faq'] ?? [],
            'schemas' => $this->buildSchemas($brief),
            'internal_links' => $this->suggestInternalLinks(),
            'external_authority_links' => $this->config['seo']['default_authority_links'],
            'related_suggestions' => array_column($this->storage->listJson('articles'), 'title'),
        ];
    }

    private function jsonAi(array $messages, array $avoid = [], array &$usedModels = []): array
    {
        $result = $this->ai->complete($messages, $avoid);
        $usedModels[] = $result['model'];
        $content = preg_replace('/^```(?:json)?|```$/m', '', $result['content']);
        if (preg_match('/\{.*\}/s', (string) $content, $match)) {
            $content = $match[0];
        }
        $json = json_decode(trim((string) $content), true);
        if (!is_array($json)) {
            throw new RuntimeException('AI returned invalid JSON from ' . $result['model']);
        }
        $json['_model'] = $result['model'];
        return $json;
    }


    private function buildSchemas(array $brief): array
    {
        return [
            'Article' => ['headline' => $brief['meta_title'] ?? $brief['title'] ?? '', 'description' => $brief['meta_description'] ?? ''],
            'FAQPage' => $brief['faq'] ?? [],
            'HowTo' => ['name' => $brief['title'] ?? '', 'steps' => array_slice($brief['headings'] ?? [], 0, 6)],
            'BreadcrumbList' => ['الرئيسية', $brief['title'] ?? 'مقال'],
        ];
    }

    private function enqueueArticleFromFaq(array $niche, string $question): void
    {
        if ($question === '') {
            return;
        }
        $id = $this->slug($question) . '-' . substr(sha1($question), 0, 8);
        $this->storage->writeJson('queue/' . $id . '.json', ['type' => 'article_from_faq', 'niche' => $niche, 'question' => $question, 'status' => 'pending']);
    }

    private function suggestInternalLinks(): array
    {
        return array_slice(array_map(fn ($a) => ['title' => $a['title'] ?? '', 'slug' => $a['slug'] ?? ''], $this->storage->listJson('articles')), 0, (int) $this->config['seo']['internal_link_limit']);
    }

    private function slug(string $text): string
    {
        $slug = trim(preg_replace('/[^\p{Arabic}a-z0-9]+/iu', '-', mb_strtolower($text)), '-');
        return $slug !== '' ? $slug : 'article-' . time();
    }
}
