<?php
declare(strict_types=1);

/**
 * HTTP 状态回执页渲染器。
 *
 * 统一 FPM / WLS / NoRouter / 异常生产页的自定义错误页加载：
 * - 优先 `pub/errors/{code}.php`
 * - 其次 `pub/errors/default.php`
 * - 最后内置 HTML
 * - 可选事件 `Weline_Framework_Http::error_page_render` 整页覆盖
 */

namespace Weline\Framework\Http;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

final class ErrorPageRenderer
{
    /**
     * @param array{
     *   home_href?: string,
     *   request_id?: string,
     *   prefer_json?: bool,
     *   accept?: string,
     *   detail?: string,
     *   is_dev?: bool
     * } $context
     */
    public static function render(int $statusCode, string $message = '', array $context = []): string
    {
        $statusCode = self::normalizeStatusCode($statusCode);
        $catalog = self::catalogEntry($statusCode);
        $statusText = $catalog['status_text'];
        $message = \trim($message) !== '' ? $message : $statusText;
        $isDev = \array_key_exists('is_dev', $context)
            ? (bool)$context['is_dev']
            : self::detectDevMode();

        if (self::wantsJson($context)) {
            return self::renderJson($statusCode, $statusText, $message, $context);
        }

        $vars = [
            'statusCode' => $statusCode,
            'statusText' => $statusText,
            'message' => $message,
            'pageTitle' => $catalog['title'],
            'pageLead' => $catalog['lead'],
            'pageHint' => $catalog['hint'],
            'homeHref' => self::resolveHomeHref($context),
            'requestId' => self::resolveRequestId($context),
            'detail' => (string)($context['detail'] ?? ''),
            'isDev' => $isDev,
            'accent' => $catalog['accent'],
        ];

        $html = self::dispatchOverride($vars);
        if ($html !== null) {
            return $html;
        }

        $base = \defined('BP') ? (string)BP : '';
        $candidates = [
            $base . 'pub/errors/' . $statusCode . '.php',
            $base . 'pub/errors/default.php',
        ];
        foreach ($candidates as $file) {
            if ($file !== '' && \is_file($file) && \is_readable($file)) {
                $rendered = self::includeTemplate($file, $vars);
                if ($rendered !== '') {
                    return $rendered;
                }
            }
        }

        return self::builtinHtml($vars);
    }

    public static function statusText(int $statusCode): string
    {
        return self::catalogEntry(self::normalizeStatusCode($statusCode))['status_text'];
    }

    /**
     * @return array{status_text: string, title: string, lead: string, hint: string, accent: string}
     */
    public static function catalogEntry(int $statusCode): array
    {
        $statusCode = self::normalizeStatusCode($statusCode);
        $map = [
            400 => [
                'status_text' => 'Bad Request',
                'title' => '请求无效',
                'lead' => '服务器无法理解本次请求。',
                'hint' => '请检查地址与参数后重试。',
                'accent' => 'warn',
            ],
            401 => [
                'status_text' => 'Unauthorized',
                'title' => '需要登录',
                'lead' => '当前页面需要有效身份才能访问。',
                'hint' => '请登录后再试，或返回首页。',
                'accent' => 'warn',
            ],
            403 => [
                'status_text' => 'Forbidden',
                'title' => '无权访问',
                'lead' => '你没有权限查看此内容。',
                'hint' => '如需访问，请联系站点管理员。',
                'accent' => 'warn',
            ],
            404 => [
                'status_text' => 'Not Found',
                'title' => '页面不存在',
                'lead' => '找不到你请求的页面或资源。',
                'hint' => '链接可能已失效，或地址输入有误。',
                'accent' => 'neutral',
            ],
            405 => [
                'status_text' => 'Method Not Allowed',
                'title' => '方法不被允许',
                'lead' => '当前请求方法不被此地址支持。',
                'hint' => '请改用正确的 HTTP 方法后重试。',
                'accent' => 'warn',
            ],
            410 => [
                'status_text' => 'Gone',
                'title' => '资源已下线',
                'lead' => '该地址对应的内容已永久移除。',
                'hint' => '请返回首页查找可用入口。',
                'accent' => 'neutral',
            ],
            413 => [
                'status_text' => 'Payload Too Large',
                'title' => '请求体过大',
                'lead' => '提交的内容超过服务器允许大小。',
                'hint' => '请减小文件或拆分后再试。',
                'accent' => 'warn',
            ],
            422 => [
                'status_text' => 'Unprocessable Entity',
                'title' => '无法处理请求',
                'lead' => '请求格式正确，但语义无法处理。',
                'hint' => '请核对提交数据后重试。',
                'accent' => 'warn',
            ],
            429 => [
                'status_text' => 'Too Many Requests',
                'title' => '请求过于频繁',
                'lead' => '短时间内请求次数过多，已被限流。',
                'hint' => '请稍候再试。',
                'accent' => 'warn',
            ],
            500 => [
                'status_text' => 'Internal Server Error',
                'title' => '服务器错误',
                'lead' => '处理请求时发生意外错误。',
                'hint' => '我们已记录问题，请稍后重试。',
                'accent' => 'danger',
            ],
            502 => [
                'status_text' => 'Bad Gateway',
                'title' => '网关错误',
                'lead' => '上游服务返回了无效响应。',
                'hint' => '请稍后重试；若持续出现请联系运维。',
                'accent' => 'danger',
            ],
            503 => [
                'status_text' => 'Service Unavailable',
                'title' => '服务暂不可用',
                'lead' => '服务正在维护或暂时过载。',
                'hint' => '请稍后再访问。',
                'accent' => 'warn',
            ],
            504 => [
                'status_text' => 'Gateway Timeout',
                'title' => '网关超时',
                'lead' => '上游服务未能及时响应。',
                'hint' => '请稍后重试。',
                'accent' => 'danger',
            ],
        ];

        if (isset($map[$statusCode])) {
            return $map[$statusCode];
        }

        return [
            'status_text' => 'Error',
            'title' => '请求无法完成',
            'lead' => '服务器返回了状态码 ' . $statusCode . '。',
            'hint' => '请返回首页或稍后重试。',
            'accent' => $statusCode >= 500 ? 'danger' : 'neutral',
        ];
    }

    public static function defaultMessage(int $statusCode): string
    {
        return self::catalogEntry($statusCode)['status_text'];
    }

    private static function normalizeStatusCode(int $statusCode): int
    {
        if ($statusCode < 100 || $statusCode > 599) {
            return 500;
        }

        return $statusCode;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function wantsJson(array $context): bool
    {
        if (\array_key_exists('prefer_json', $context)) {
            return (bool)$context['prefer_json'];
        }

        $accept = \strtolower((string)($context['accept'] ?? ''));
        if ($accept === '' && \class_exists(WelineEnv::class, false)) {
            try {
                $accept = \strtolower((string)WelineEnv::server('HTTP_ACCEPT', ''));
            } catch (\Throwable) {
                $accept = '';
            }
        }

        if ($accept === '') {
            return false;
        }

        if (\str_contains($accept, 'application/json')) {
            return !\str_contains($accept, 'text/html')
                || \strpos($accept, 'application/json') < (\strpos($accept, 'text/html') ?: \PHP_INT_MAX);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function renderJson(int $statusCode, string $statusText, string $message, array $context): string
    {
        $payload = [
            'ok' => false,
            'status' => $statusCode,
            'error' => $statusText,
            'message' => $message,
        ];
        $requestId = self::resolveRequestId($context);
        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }
        if (!empty($context['detail']) && self::detectDevMode()) {
            $payload['detail'] = (string)$context['detail'];
        }

        return (string)\json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function dispatchOverride(array $vars): ?string
    {
        try {
            if (!\class_exists(ObjectManager::class, false) && !\class_exists(ObjectManager::class)) {
                return null;
            }
            /** @var EventsManager $events */
            $events = ObjectManager::getInstance(EventsManager::class);
            $data = new DataObject([
                'status_code' => $vars['statusCode'],
                'status_text' => $vars['statusText'],
                'message' => $vars['message'],
                'page_title' => $vars['pageTitle'],
                'page_lead' => $vars['pageLead'],
                'page_hint' => $vars['pageHint'],
                'home_href' => $vars['homeHref'],
                'request_id' => $vars['requestId'],
                'detail' => $vars['detail'],
                'is_dev' => $vars['isDev'],
                'html' => '',
            ]);
            $events->dispatch('Weline_Framework_Http::error_page_render', $data);
            $html = \trim((string)$data->getData('html'));
            return $html !== '' ? $html : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function includeTemplate(string $file, array $vars): string
    {
        \ob_start();
        try {
            // Expose both camelCase and legacy names for site overrides.
            $statusCode = (int)$vars['statusCode'];
            $statusText = (string)$vars['statusText'];
            $message = (string)$vars['message'];
            $msg = $message;
            $code = $statusCode;
            $pageTitle = (string)$vars['pageTitle'];
            $pageLead = (string)$vars['pageLead'];
            $pageHint = (string)$vars['pageHint'];
            $homeHref = (string)$vars['homeHref'];
            $requestId = (string)$vars['requestId'];
            $detail = (string)$vars['detail'];
            $isDev = (bool)$vars['isDev'];
            $accent = (string)$vars['accent'];
            include $file;
            return (string)\ob_get_clean();
        } catch (\Throwable) {
            \ob_end_clean();
            return '';
        }
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function builtinHtml(array $vars): string
    {
        $statusCode = (int)$vars['statusCode'];
        $statusText = \htmlspecialchars((string)$vars['statusText'], ENT_QUOTES, 'UTF-8');
        $pageTitle = \htmlspecialchars((string)$vars['pageTitle'], ENT_QUOTES, 'UTF-8');
        $pageLead = \htmlspecialchars((string)$vars['pageLead'], ENT_QUOTES, 'UTF-8');
        $pageHint = \htmlspecialchars((string)$vars['pageHint'], ENT_QUOTES, 'UTF-8');
        $message = \htmlspecialchars((string)$vars['message'], ENT_QUOTES, 'UTF-8');
        $homeHref = \htmlspecialchars((string)$vars['homeHref'], ENT_QUOTES, 'UTF-8');
        $requestId = \htmlspecialchars((string)$vars['requestId'], ENT_QUOTES, 'UTF-8');
        $detail = \htmlspecialchars((string)$vars['detail'], ENT_QUOTES, 'UTF-8');
        $showDetail = (bool)$vars['isDev'] && $detail !== '';
        $accent = (string)($vars['accent'] ?? 'neutral');
        $codeClass = 'w-error-code' . match ($accent) {
            'danger' => ' is-danger',
            'warn' => ' is-warn',
            default => '',
        };
        $requestMeta = $requestId !== ''
            ? '<p class="w-error-meta">Request ID: ' . $requestId . '</p>'
            : '';
        $detailBlock = $showDetail
            ? '<pre class="w-error-detail">' . $detail . '</pre>'
            : '';
        $messageBlock = $message !== '' && $message !== $statusText
            ? '<p class="w-error-msg">' . $message . '</p>'
            : '';
        $homeJs = \json_encode((string)$vars['homeHref'] !== '' ? (string)$vars['homeHref'] : '/', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{$statusCode} · {$pageTitle}</title>
    <style>
        :root { color-scheme: light; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --bg:#f8fafc; --card:#fff; --accent:#0f766e; --danger:#b91c1c; --warn:#b45309; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px; font-family:"IBM Plex Sans","Segoe UI",sans-serif; color:var(--ink); background: radial-gradient(1200px 600px at 10% -10%, #ccfbf1 0%, transparent 55%), radial-gradient(900px 500px at 100% 0%, #e2e8f0 0%, transparent 50%), var(--bg); }
        .w-error { width:min(560px,100%); background:var(--card); border:1px solid var(--line); border-radius:18px; padding:36px 32px; box-shadow:0 18px 50px rgba(15,23,42,.06); }
        .w-error-code { font-family:"IBM Plex Mono",ui-monospace,monospace; font-size:clamp(3rem,8vw,4.5rem); font-weight:600; letter-spacing:-.04em; line-height:1; color:var(--accent); margin:0 0 12px; }
        .w-error-code.is-danger { color:var(--danger); }
        .w-error-code.is-warn { color:var(--warn); }
        h1 { margin:0 0 10px; font-size:1.5rem; font-weight:650; letter-spacing:-.02em; }
        .w-error-lead,.w-error-hint,.w-error-msg,.w-error-meta { margin:0 0 10px; color:var(--muted); line-height:1.55; }
        .w-error-msg { color:var(--ink); }
        .w-error-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:22px; }
        .w-error-actions a, .w-error-actions button { appearance:none; border:1px solid var(--line); background:#fff; color:var(--ink); text-decoration:none; border-radius:999px; padding:10px 16px; font:inherit; cursor:pointer; }
        .w-error-actions a.primary { background:var(--ink); border-color:var(--ink); color:#fff; }
        .w-error-detail { margin-top:18px; padding:12px; border-radius:10px; background:#0f172a; color:#e2e8f0; font-size:12px; overflow:auto; max-height:220px; }
    </style>
</head>
<body>
    <main class="w-error" role="alert">
        <p class="{$codeClass}">{$statusCode}</p>
        <h1>{$pageTitle}</h1>
        <p class="w-error-lead">{$pageLead}</p>
        {$messageBlock}
        <p class="w-error-hint">{$pageHint}</p>
        {$requestMeta}
        {$detailBlock}
        <div class="w-error-actions">
            <a class="primary" href="{$homeHref}">返回首页</a>
            <button type="button" data-w-error-back>返回上一页</button>
        </div>
    </main>
    <script>
        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest && e.target.closest('[data-w-error-back]');
            if (!btn) return;
            if (window.history.length > 1) { window.history.back(); return; }
            window.location.href = {$homeJs};
        });
    </script>
</body>
</html>
HTML;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveHomeHref(array $context): string
    {
        $home = \trim((string)($context['home_href'] ?? ''));
        if ($home !== '') {
            return $home;
        }

        return '/';
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveRequestId(array $context): string
    {
        $id = \trim((string)($context['request_id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        try {
            if (\function_exists('w_env')) {
                $id = \trim((string)\w_env('request.id', ''));
                if ($id !== '') {
                    return $id;
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private static function detectDevMode(): bool
    {
        if (\defined('DEV')) {
            return (bool)DEV;
        }
        try {
            if (\class_exists(\Weline\Framework\App\Env::class, false) || \class_exists(\Weline\Framework\App\Env::class)) {
                return (bool)\Weline\Framework\App\Env::getInstance()->getConfig('debug');
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
