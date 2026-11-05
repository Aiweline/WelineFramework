<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\PanelTraceCookie;

/**
 * 冷渲染画像：一次命令拿到「冷请求到底慢在哪」。
 *
 * WLS 的 `var/log/wls/timing.log` 已按请求记录 router_profile / template_profile /
 * trace_db_top / trace_summary，但要触发完整画像需要「穿章 + 面板 cookie + 真冷」。
 * 这个命令把这几步固化下来，便于每次优化前后直接对照。
 */
class ColdProfile implements CommandInterface
{
    public const ALIASES = ['theme:cold-profile'];

    public function __construct(
        private readonly Printing $printing,
    ) {
    }

    public function command(): string
    {
        return self::ALIASES[0];
    }

    public function tip(): string
    {
        return __('冷渲染画像：穿章 + 面板 cookie 打一次冷请求，解析 timing.log 输出热点');
    }

    public function help(): array
    {
        return [
            'usage' => [
                'php bin/w theme:cold-profile [--path=/products] [--site=host] [--top=8] [--clear-fpc=1]',
            ],
            'note' => __('--clear-fpc=1 会先清掉整页缓存，确保测到的是真冷渲染'),
        ];
    }

    public function execute(array $args = [], array $options = []): void
    {
        $merged = \array_merge($args, $options);
        $path = \trim((string)($merged['path'] ?? '/products'));
        if ($path === '') {
            $path = '/products';
        }
        if (!\str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $top = \max(1, (int)($merged['top'] ?? 8));
        $host = \trim((string)($merged['site'] ?? ''));
        if ($host === '') {
            $host = $this->resolveHost();
        }
        if ($host === '') {
            $this->printing->warning(__('没有可用的站点 Host：请用 --site=<域名> 指定'));

            return;
        }

        if ((int)($merged['clear-fpc'] ?? 1) === 1) {
            $this->clearFpc();
        }

        $secret = $this->pierceSecret();
        $token = (string)\hrtime(true);
        $url = 'https://127.0.0.1' . $path . '?';
        if ($secret !== '') {
            $url .= 'w_fpc_pierce=' . \rawurlencode($secret) . '&';
        }
        $url .= 'x=' . $token;

        $handle = \curl_init($url);
        if ($handle === false) {
            $this->printing->error(__('curl 初始化失败'));

            return;
        }
        $cookies = 'w_weline_trace_panel=' . PanelTraceCookie::buildPanelValue()
            . '; w_weline_tpl_perf=' . PanelTraceCookie::buildTplPerfValue();
        \curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Host: ' . $host, 'Cookie: ' . $cookies],
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
        ]);
        $started = \microtime(true);
        $body = (string)\curl_exec($handle);
        $wallMs = (\microtime(true) - $started) * 1000;
        $status = (int)\curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        \curl_close($handle);

        $this->printing->note(\sprintf(
            '冷请求 %s → HTTP %d  wall=%.0fms  bytes=%d',
            $path,
            $status,
            $wallMs,
            \strlen($body)
        ));
        if ($status !== 200) {
            $this->printing->warning(__('本次不是 200（维护模式或准入会给 503）；下面是日志里最近的同路径记录，不代表本次'));
        }

        $entry = $this->lastTimingEntry($path, $token);
        if ($entry === null) {
            $this->printing->warning(__('本次没有产生新画像：请求命中了整页缓存或未达记录阈值。加 --cold-restart=1 可重启 Worker 后测真冷，或换一个未预热的路径'));

            return;
        }

        $this->printing->note(\sprintf(
            '服务端 total=%.1fms  run_before=%.1f  url_parser=%.1f  router_start=%.1f  run_after=%.1f',
            (float)($entry['total_ms'] ?? 0),
            (float)($entry['run_before_ms'] ?? 0),
            (float)($entry['url_parser_ms'] ?? 0),
            (float)($entry['router_start_call_ms'] ?? 0),
            (float)($entry['run_after_ms'] ?? 0)
        ));

        $summary = (array)($entry['trace_summary'] ?? []);
        if ($summary !== []) {
            $this->printing->printing(\sprintf(
                '  spans=%s  db_spans=%s  db=%.1fms  wall=%.1fms',
                (string)($summary['span_count'] ?? '?'),
                (string)($summary['db_span_count'] ?? '?'),
                (float)($summary['db_duration_ms'] ?? 0),
                (float)($summary['envelope_wall_ms'] ?? 0)
            ));
        }

        $router = (array)($entry['router_profile'] ?? []);
        if ($router !== []) {
            $this->printing->printing(\sprintf(
                '  controller=%s::%s  controller_init=%.1fms  action_execute=%.1fms',
                (string)($router['controller'] ?? '?'),
                (string)($router['action'] ?? '?'),
                (float)($router['controller_init_ms'] ?? 0),
                (float)($router['action_execute_ms'] ?? 0)
            ));
        }

        $template = (array)($entry['template_profile'] ?? []);
        if ($template !== []) {
            \usort($template, static fn(array $a, array $b): int => (float)($b['total_ms'] ?? 0) <=> (float)($a['total_ms'] ?? 0));
            $this->printing->note(__('模板热点（自耗时降序，注意为嵌套值）：'));
            foreach (\array_slice($template, 0, $top) as $row) {
                $this->printing->printing(\sprintf(
                    '  %7.1fms  include=%7.1f  php=%6.1f  db=%6.1f  bytes=%-7d  %s',
                    (float)($row['total_ms'] ?? 0),
                    (float)($row['include_ms'] ?? 0),
                    (float)($row['php_ms'] ?? 0),
                    (float)($row['db_duration_ms'] ?? 0),
                    (int)($row['bytes'] ?? 0),
                    $this->shortName((string)($row['file'] ?? ''))
                ));
            }
        }

        $db = (array)($entry['trace_db_top'] ?? []);
        if ($db !== []) {
            $this->printing->note(__('慢查询：'));
            foreach (\array_slice($db, 0, $top) as $row) {
                $this->printing->printing(\sprintf(
                    '  %7.2fms  %s',
                    (float)($row['duration_ms'] ?? 0),
                    \substr((string)($row['name'] ?? ''), 0, 96)
                ));
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function lastTimingEntry(string $path, string $token): ?array
    {
        $file = BP . 'var/log/wls/timing.log';
        if (!\is_file($file)) {
            return null;
        }
        $lines = @\file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!\is_array($lines)) {
            return null;
        }
        $needle = \strtok($path, '?') ?: $path;
        // 只认本次请求（uri 里有本次的 x=<token>），避免把缓存命中的旧样本当成本次画像。
        $tokenNeedle = 'x=' . $token;
        foreach (\array_reverse(\array_slice($lines, -120)) as $line) {
            $decoded = \json_decode($line, true);
            if (!\is_array($decoded)) {
                continue;
            }
            $uri = (string)($decoded['uri'] ?? '');
            if ($uri !== '' && \str_contains($uri, $tokenNeedle) && \str_contains($uri, $needle)) {
                return $decoded;
            }
        }

        return null;
    }

    private function shortName(string $file): string
    {
        $parts = \explode('/', $file);

        return (string)\end($parts);
    }

    private function clearFpc(): void
    {
        $dir = BP . 'generated/html/generations';
        if (!\is_dir($dir)) {
            return;
        }
        $items = @\scandir($dir);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $dir . '/' . $item;
            if (\is_dir($target)) {
                $this->removeTree($target);
                continue;
            }
            @\unlink($target);
        }
    }

    private function removeTree(string $dir): void
    {
        $items = @\scandir($dir);
        if (!\is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $dir . '/' . $item;
            if (\is_dir($target)) {
                $this->removeTree($target);
                continue;
            }
            @\unlink($target);
        }
        @\rmdir($dir);
    }

    private function pierceSecret(): string
    {
        // 唯一权威是 FpcPierceStamp：env(wls.fpc_pierce_secret) 优先、密钥文件兜底。
        // 直接读 generated 文件会拿到**过期值**（env 与边缘 map 已换新章而文件没跟上），
        // 于是“带着章”的请求其实打不穿 FPC，本命令永远输出“没有产生新画像”。
        try {
            return FpcPierceStamp::readSecret();
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveHost(): string
    {
        try {
            $url = \trim((string)\Weline\Framework\App\Env::get('website.url', ''));
            if ($url !== '') {
                return (string)(\parse_url($url, PHP_URL_HOST) ?: $url);
            }
        } catch (\Throwable) {
            // fall through
        }
        foreach (['wls.public_host', 'wls.host', 'server.public_host'] as $key) {
            try {
                $value = \trim((string)\Weline\Framework\App\Env::get($key, ''));
            } catch (\Throwable) {
                $value = '';
            }
            if ($value !== '') {
                return (string)(\parse_url($value, PHP_URL_HOST) ?: $value);
            }
        }

        return '';
    }
}
