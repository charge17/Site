<?php
/**
 * Email service for sending notifications
 */
final class EmailService
{
    private string $adminEmail;
    private string $siteUrl;
    private string $siteName;

    public function __construct(string $adminEmail, string $siteUrl, string $siteName = 'AI SEO Site')
    {
        $this->adminEmail = $adminEmail;
        $this->siteUrl = $siteUrl;
        $this->siteName = $siteName;
    }

    /**
     * Send admin notification for article generation
     */
    public function notifyArticleGenerated(string $title, string $slug, string $niche): bool
    {
        $subject = "📝 تم إنشاء مقال جديد: {$title}";
        
        $body = $this->renderTemplate('article-generated', [
            'title' => $title,
            'slug' => $slug,
            'niche' => $niche,
            'url' => rtrim($this->siteUrl, '/') . '/article/' . $slug,
            'adminUrl' => rtrim($this->siteUrl, '/') . '/admin.php?tab=articles',
        ]);

        return $this->send($this->adminEmail, $subject, $body);
    }

    /**
     * Send admin notification for queue processing
     */
    public function notifyQueueProcessed(int $processedCount, int $failedCount): bool
    {
        $subject = "⚙️ تقرير معالجة قائمة الانتظار - {$this->siteName}";
        
        $body = $this->renderTemplate('queue-report', [
            'processed' => $processedCount,
            'failed' => $failedCount,
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ]);

        return $this->send($this->adminEmail, $subject, $body);
    }

    /**
     * Send admin notification for errors
     */
    public function notifyError(string $message, array $context = []): bool
    {
        $subject = "❌ خطأ في {$this->siteName}";
        
        $body = $this->renderTemplate('error-alert', [
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);

        return $this->send($this->adminEmail, $subject, $body);
    }

    /**
     * Send email using mail() function (compatible with shared hosting)
     */
    private function send(string $to, string $subject, string $body): bool
    {
        // Validate email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $headers = [
            'From: ' . $this->adminEmail,
            'Reply-To: ' . $this->adminEmail,
            'Content-Type: text/html; charset=UTF-8',
            'X-Mailer: ' . $this->siteName,
        ];

        $headerString = implode("\r\n", $headers);

        // In production, add more sophisticated error handling
        return @mail($to, $subject, $body, $headerString);
    }

    /**
     * Render email template
     */
    private function renderTemplate(string $template, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        
        ob_start();
        
        // Include appropriate template
        match($template) {
            'article-generated' => $this->templateArticleGenerated(),
            'queue-report' => $this->templateQueueReport(),
            'error-alert' => $this->templateErrorAlert(),
            default => echo 'Template not found',
        };
        
        return (string)ob_get_clean();
    }

    private function templateArticleGenerated(): void
    {
        ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; }
        .header { border-bottom: 3px solid #007bff; padding-bottom: 10px; margin-bottom: 20px; }
        .content { color: #333; line-height: 1.6; }
        .button { display: inline-block; background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin: 10px 0; }
        .footer { border-top: 1px solid #ddd; padding-top: 10px; margin-top: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>📝 مقال جديد تم إنشاؤه</h2>
        </div>
        <div class="content">
            <p>تم إنشاء مقال جديد بنجاح!</p>
            <p><strong>العنوان:</strong> <?= Security::escapeHtml($title ?? '') ?></p>
            <p><strong>النيش:</strong> <?= Security::escapeHtml($niche ?? '') ?></p>
            <p><strong>الحالة:</strong> مسودة (يتطلب المراجعة)</p>
            <p>
                <a href="<?= Security::escapeHtml($url ?? '') ?>" class="button">عرض المقال</a>
                <a href="<?= Security::escapeHtml($adminUrl ?? '') ?>" class="button">لوحة الإدارة</a>
            </p>
        </div>
        <div class="footer">
            <p>هذا بريد آلي من <?= htmlspecialchars($siteName) ?></p>
        </div>
    </div>
</body>
</html>
        <?php
    }

    private function templateQueueReport(): void
    {
        ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; }
        .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 20px 0; }
        .stat { background: #f9f9f9; padding: 15px; border-radius: 4px; text-align: center; }
        .stat-value { font-size: 24px; font-weight: bold; color: #007bff; }
        .stat-label { color: #666; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>⚙️ تقرير معالجة قائمة الانتظار</h2>
        <p><?= htmlspecialchars($timestamp ?? '') ?></p>
        <div class="stats">
            <div class="stat">
                <div class="stat-value">✅ <?= htmlspecialchars($processed ?? '0') ?></div>
                <div class="stat-label">تمت معالجتها</div>
            </div>
            <div class="stat">
                <div class="stat-value">❌ <?= htmlspecialchars($failed ?? '0') ?></div>
                <div class="stat-label">فشلت</div>
            </div>
        </div>
    </div>
</body>
</html>
        <?php
    }

    private function templateErrorAlert(): void
    {
        ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #dc3545; }
        .alert { background: #fff3cd; padding: 15px; border-radius: 4px; margin: 20px 0; }
        pre { background: #f9f9f9; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h2>❌ تنبيه خطأ</h2>
        <div class="alert">
            <p><?= Security::escapeHtml($message ?? '') ?></p>
        </div>
        <p><strong>الوقت:</strong> <?= htmlspecialchars($timestamp ?? '') ?></p>
        <p><strong>IP:</strong> <?= htmlspecialchars($ip ?? '') ?></p>
        <details>
            <summary>التفاصيل الإضافية</summary>
            <pre><?= htmlspecialchars($context ?? '') ?></pre>
        </details>
    </div>
</body>
</html>
        <?php
    }
}
