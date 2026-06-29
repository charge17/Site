<?php
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../src/Storage.php';
require __DIR__ . '/../src/OpenRouterClient.php';
require __DIR__ . '/../src/SeoArticleBuilder.php';
$storage = new Storage($config['storage_path']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$tab = preg_replace('/[^a-z_-]/', '', $_GET['tab'] ?? 'dashboard');
$message = '';
$error = '';

function admin_slug(string $text): string { return trim(preg_replace('/[^\p{Arabic}a-z0-9_-]+/iu', '-', mb_strtolower($text)), '-') ?: 'item-' . time(); }
function admin_json_response(array $payload): never { header('Content-Type: application/json'); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit; }
function admin_require_csrf(): void { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { throw new RuntimeException('CSRF token غير صالح.'); } }

try {
    if (isset($_GET['export'])) {
        $collection = preg_replace('/[^a-z_-]/', '', $_GET['export']);
        admin_json_response(['collection' => $collection, 'items' => $storage->listJson($collection)]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        admin_require_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'save_niche') {
            $slug = admin_slug($_POST['slug'] ?: $_POST['name']);
            $storage->writeJson('niches/' . $slug . '.json', [
                'slug' => $slug,
                'name' => trim($_POST['name']),
                'keywords' => array_values(array_filter(array_map('trim', explode(',', $_POST['keywords'] ?? '')))),
                'audience' => trim($_POST['audience'] ?? ''),
                'tone' => trim($_POST['tone'] ?? 'خبير وعملي'),
                'updated_at' => gmdate(DATE_ATOM),
            ]);
            $message = 'تم حفظ النيش.';
        }
        if ($action === 'generate') {
            $niche = $storage->readJson('niches/' . basename($_POST['niche']) . '.json');
            $article = (new SeoArticleBuilder(new OpenRouterClient($config['openrouter'], $storage), $storage, $config))->createFromNiche($niche);
            $message = 'تم إنشاء مسودة المقال: ' . ($article['title'] ?? '');
        }
        if ($action === 'article_status') {
            $slug = basename($_POST['slug']);
            $article = $storage->readJson('articles/' . $slug . '.json');
            $article['status'] = $_POST['status'] === 'published' ? 'published' : 'draft';
            $article['updated_at'] = gmdate(DATE_ATOM);
            $storage->writeJson('articles/' . $slug . '.json', $article);
            $message = 'تم تحديث حالة المقال.';
        }
        if (in_array($action, ['save_ad', 'save_script', 'save_page', 'save_plugin', 'save_task'], true)) {
            $map = ['save_ad' => 'ads', 'save_script' => 'scripts', 'save_page' => 'pages', 'save_plugin' => 'plugins', 'save_task' => 'tasks'];
            $dir = $map[$action];
            $slug = admin_slug($_POST['slug'] ?: ($_POST['title'] ?? $_POST['name'] ?? $dir));
            $payload = $_POST;
            unset($payload['csrf'], $payload['action']);
            $payload['slug'] = $slug;
            $payload['enabled'] = isset($_POST['enabled']);
            $payload['updated_at'] = gmdate(DATE_ATOM);
            $storage->writeJson($dir . '/' . $slug . '.json', $payload);
            $message = 'تم الحفظ بنجاح.';
        }
        if ($action === 'seo_settings') {
            $storage->writeJson('settings/seo.json', [
                'default_title_pattern' => trim($_POST['default_title_pattern'] ?? '{title}'),
                'default_description' => trim($_POST['default_description'] ?? ''),
                'robots_extra' => trim($_POST['robots_extra'] ?? ''),
                'authority_links' => array_values(array_filter(array_map('trim', explode("\n", $_POST['authority_links'] ?? '')))),
                'updated_at' => gmdate(DATE_ATOM),
            ]);
            $message = 'تم حفظ إعدادات SEO.';
        }
    }
} catch (Throwable $e) { $error = $e->getMessage(); }

$niches = $storage->listJson('niches');
$articles = $storage->listJson('articles');
$queue = $storage->listJson('queue');
$ads = $storage->listJson('ads');
$scripts = $storage->listJson('scripts');
$pages = $storage->listJson('pages');
$plugins = $storage->listJson('plugins');
$tasks = $storage->listJson('tasks');
$seo = $storage->readJson('settings/seo.json', []);
$logs = is_file($config['storage_path'] . '/logs/ai-failover.log') ? array_slice(file($config['storage_path'] . '/logs/ai-failover.log'), -8) : [];
$csrf = htmlspecialchars($_SESSION['csrf']);
$tabs = ['dashboard'=>'الرئيسية','niches'=>'النيشات','articles'=>'المقالات','ai'=>'الذكاء الاصطناعي','seo'=>'SEO','ads'=>'الإعلانات','assets'=>'السكريبتات والصور','pages'=>'الصفحات','plugins'=>'الإضافات','tasks'=>'المهام','stats'=>'الأداء'];
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>لوحة إدارة AI SEO</title><link rel="stylesheet" href="/assets/app.css"></head><body><aside class="sidebar"><h2>AI SEO</h2><?php foreach ($tabs as $key=>$label): ?><a class="<?= $tab===$key?'active':'' ?>" href="?tab=<?= $key ?>"><?= $label ?></a><?php endforeach; ?></aside><main class="admin"><h1><?= $tabs[$tab] ?? 'لوحة الإدارة' ?></h1><?php if ($message): ?><p class="notice"><?= htmlspecialchars($message) ?></p><?php endif; ?><?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if ($tab === 'dashboard'): ?><section class="kpis"><div><b><?= count($articles) ?></b><span>مقال</span></div><div><b><?= count($niches) ?></b><span>نيش</span></div><div><b><?= count($queue) ?></b><span>انتظار</span></div><div><b><?= count($ads)+count($scripts)+count($plugins) ?></b><span>تكاملات</span></div></section><section><h2>خطة العمل السريعة</h2><ol><li>أضف النيش والكلمات والجمهور.</li><li>ولّد مسودة عبر OpenRouter Free.</li><li>راجع المقال وانشره.</li><li>شغّل Cron ليحوّل FAQ إلى مقالات داعمة.</li></ol></section><?php endif; ?>
<?php if ($tab === 'niches'): ?><section class="grid"><form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_niche"><h2>نيش جديد</h2><label>الاسم<input name="name" required></label><label>Slug<input name="slug"></label><label>الكلمات المفتاحية<input name="keywords" placeholder="seo, ai, ..."></label><label>الجمهور<textarea name="audience"></textarea></label><label>النبرة<input name="tone" value="خبير وعملي"></label><button>حفظ النيش</button></form><div class="card"><h2>النيشات الحالية</h2><?php foreach ($niches as $niche): ?><p><b><?= htmlspecialchars($niche['name'] ?? '') ?></b><br><small><?= htmlspecialchars(implode(', ', $niche['keywords'] ?? [])) ?></small></p><?php endforeach; ?><a href="?export=niches">تصدير JSON</a></div></section><?php endif; ?>
<?php if ($tab === 'ai'): ?><section class="grid"><form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="generate"><h2>توليد مقال</h2><select name="niche"><?php foreach ($niches as $niche): ?><option value="<?= htmlspecialchars($niche['slug'] ?? $niche['name']) ?>"><?= htmlspecialchars($niche['name']) ?></option><?php endforeach; ?></select><button>إنشاء مسودة SEO</button></form><div class="card"><h2>نماذج OpenRouter المجانية</h2><ol><?php foreach ($config['openrouter']['free_models'] as $model): ?><li><?= htmlspecialchars($model) ?></li><?php endforeach; ?></ol><p>يتم استخدام النماذج بالتتابع مع استبدال تلقائي عند الفشل.</p></div></section><section><h2>آخر سجلات Failover</h2><pre><?= htmlspecialchars(implode('', $logs)) ?></pre></section><?php endif; ?>
<?php if ($tab === 'articles'): ?><section><h2>إدارة المقالات</h2><table><tr><th>العنوان</th><th>النيش</th><th>الحالة</th><th>إجراء</th></tr><?php foreach ($articles as $article): ?><tr><td><?= htmlspecialchars($article['title'] ?? '') ?></td><td><?= htmlspecialchars($article['niche'] ?? '') ?></td><td><?= htmlspecialchars($article['status'] ?? 'draft') ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="article_status"><input type="hidden" name="slug" value="<?= htmlspecialchars($article['slug'] ?? '') ?>"><select name="status"><option value="draft">مسودة</option><option value="published">منشور</option></select><button>تحديث</button></form></td></tr><?php endforeach; ?></table></section><section><h2>قائمة انتظار FAQ</h2><pre><?= htmlspecialchars(json_encode($queue, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></section><?php endif; ?>
<?php if ($tab === 'seo'): ?><section class="grid"><form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="seo_settings"><h2>إعدادات SEO</h2><label>نمط العنوان<input name="default_title_pattern" value="<?= htmlspecialchars($seo['default_title_pattern'] ?? '{title}') ?>"></label><label>وصف افتراضي<textarea name="default_description"><?= htmlspecialchars($seo['default_description'] ?? '') ?></textarea></label><label>Authority Links<textarea name="authority_links"><?= htmlspecialchars(implode("\n", $seo['authority_links'] ?? $config['seo']['default_authority_links'])) ?></textarea></label><label>Robots إضافي<textarea name="robots_extra"><?= htmlspecialchars($seo['robots_extra'] ?? '') ?></textarea></label><button>حفظ SEO</button></form><div class="card"><h2>الأرشفة</h2><p><a href="/sitemap.xml" target="_blank">Sitemap.xml</a></p><p><a href="/robots.txt" target="_blank">Robots.txt</a></p></div></section><?php endif; ?>
<?php if ($tab === 'ads'): ?><section class="grid"><form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_ad"><h2>إعلان جديد</h2><label>العنوان<input name="title"></label><label>المكان<select name="placement"><option>header</option><option>inside_article</option><option>sidebar</option><option>footer</option></select></label><label>الكود<textarea name="code"></textarea></label><label><input type="checkbox" name="enabled" checked> مفعل</label><button>حفظ الإعلان</button></form><div class="card"><h2>الإعلانات</h2><pre><?= htmlspecialchars(json_encode($ads, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></div></section><?php endif; ?>
<?php if ($tab === 'assets'): ?><section class="grid"><form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_script"><h2>سكريبت</h2><label>الاسم<input name="name"></label><label>المكان<select name="placement"><option>head</option><option>body_end</option></select></label><label>الكود<textarea name="code"></textarea></label><label><input type="checkbox" name="enabled" checked> مفعل</label><button>حفظ السكريبت</button></form><div class="card"><h2>مكتبة الصور</h2><p>ارفع الصور إلى <code>data/media</code> أو اربط CDN، ثم استخدمها داخل المقالات والـ CTA.</p><pre><?= htmlspecialchars(json_encode($scripts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></div></section><?php endif; ?>
<?php if ($tab === 'pages'): ?><section class="grid"><form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_page"><h2>صفحة ثابتة</h2><label>العنوان<input name="title"></label><label>Slug<input name="slug"></label><label>HTML<textarea name="html"></textarea></label><label><input type="checkbox" name="enabled" checked> منشورة</label><button>حفظ الصفحة</button></form><div class="card"><pre><?= htmlspecialchars(json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></div></section><?php endif; ?>
<?php if ($tab === 'plugins'): ?><section class="grid"><form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_plugin"><h2>إضافة</h2><label>الاسم<input name="name"></label><label>Hook<input name="hook" placeholder="before_article, after_article"></label><label>الإعدادات JSON<textarea name="settings"></textarea></label><label><input type="checkbox" name="enabled" checked> مفعلة</label><button>حفظ الإضافة</button></form><div class="card"><pre><?= htmlspecialchars(json_encode($plugins, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></div></section><?php endif; ?>
<?php if ($tab === 'tasks'): ?><section class="grid"><form method="post" class="card"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_task"><h2>مهمة مجدولة</h2><label>الاسم<input name="name"></label><label>النوع<select name="task_type"><option>process_queue</option><option>refresh_sitemap</option><option>performance_snapshot</option></select></label><label>التكرار<input name="schedule" placeholder="hourly/daily"></label><label><input type="checkbox" name="enabled" checked> مفعلة</label><button>حفظ المهمة</button></form><div class="card"><h2>أمر Cron</h2><code>php <?= htmlspecialchars(dirname(__DIR__) . '/cron/run.php') ?></code><pre><?= htmlspecialchars(json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></div></section><?php endif; ?>
<?php if ($tab === 'stats'): ?><section class="grid"><div class="card"><h2>أداء المحتوى</h2><p>متوسط الأقسام لكل مقال: <?= count($articles) ? round(array_sum(array_map(fn($a)=>count($a['sections'] ?? []), $articles))/count($articles), 1) : 0 ?></p><p>روابط داخلية مقترحة: <?= array_sum(array_map(fn($a)=>count($a['internal_links'] ?? []), $articles)) ?></p></div><div class="card"><h2>الصحة التقنية</h2><p>PHP: <?= PHP_VERSION ?></p><p>cURL: <?= function_exists('curl_init') ? 'متاح' : 'غير متاح' ?></p><p>قابلية كتابة data: <?= is_writable($config['storage_path']) ? 'نعم' : 'لا' ?></p></div></section><?php endif; ?>
</main></body></html>
