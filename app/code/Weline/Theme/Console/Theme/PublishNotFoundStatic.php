<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Http\StaticErrorPageMap;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Service\SetupSourceFingerprint;
use Weline\Theme\Service\StorefrontNotFoundStaticGenerator;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\DefaultWebsiteService;

/**
 * Publish storefront 404 static HTML (same work as setup:upgrade after-observer).
 *
 * Usage: php bin/w theme:publish-not-found-static [--force] [--lang=el_GR]
 */
final class PublishNotFoundStatic implements CommandInterface
{
    public function __construct(
        private readonly Printing $printing,
        private readonly StorefrontNotFoundStaticGenerator $generator,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $force = false;
        $langFilter = '';
        foreach ($args as $arg) {
            if (!\is_string($arg)) {
                continue;
            }
            if ($arg === '-h' || $arg === '--help') {
                $this->printing->printing($this->help());

                return;
            }
            if ($arg === '-f' || $arg === '--force') {
                $force = true;
                continue;
            }
            if (\str_starts_with($arg, '--lang=')) {
                $langFilter = \trim(\substr($arg, 7));
                continue;
            }
            if ($arg === '-l' || $arg === '--lang') {
                // next token handled below via remaining scan
                continue;
            }
        }
        // positional after --lang
        for ($i = 0, $n = \count($args); $i < $n; $i++) {
            $arg = $args[$i] ?? null;
            if (($arg === '-l' || $arg === '--lang') && isset($args[$i + 1]) && \is_string($args[$i + 1])) {
                $langFilter = \trim((string)$args[$i + 1]);
            }
        }

        if ($langFilter !== '' && \preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $langFilter) !== 1) {
            $this->printing->error(__('无效语种：%{lang}', ['lang' => $langFilter]));

            return;
        }

        if ($force) {
            $fp = new SetupSourceFingerprint();
            $removed = $langFilter === ''
                ? $fp->forgetByPrefix('static404:')
                : $this->forgetLocaleFingerprints($fp, $langFilter);
            $this->printing->note(__('已清除 404 静态指纹 %{count} 个（--force）', ['count' => $removed]));
        }

        $this->printing->setup(__('=== 发布前台 404 静态页 ==='));
        $start = \microtime(true);

        try {
            if ($langFilter !== '') {
                $written = $this->publishOneLocale($langFilter);
            } else {
                $written = $this->generator->publishAll();
            }
        } catch (\Throwable $e) {
            $this->printing->error(__('前台 404 静态页发布失败：%{msg}', ['msg' => $e->getMessage()]));

            return;
        }

        $elapsed = \round(\microtime(true) - $start, 2);
        $this->printing->success(__('前台 404 静态页发布完成：%{count} 个快照，耗时 %{sec}s', [
            'count' => \count($written),
            'sec' => $elapsed,
        ]));

        $this->reportSampleSizes($langFilter !== '' ? $langFilter : 'zh_Hans_CN');
    }

    /**
     * @return list<string>
     */
    private function publishOneLocale(string $lang): array
    {
        $websiteId = Website::ID_DEFAULT;
        $websiteCode = StaticErrorPageMap::DEFAULT_WEBSITE_CODE;
        $websiteUrl = 'http://127.0.0.1/';
        try {
            $defaults = ObjectManager::getInstance(DefaultWebsiteService::class)->ensureDefaultWebsite(false);
            $websiteId = (int)($defaults['website_id'] ?? Website::ID_DEFAULT);
            $websiteCode = StaticErrorPageMap::sanitizeWebsiteCode((string)($defaults['website_code'] ?? Website::CODE_DEFAULT))
                ?: StaticErrorPageMap::DEFAULT_WEBSITE_CODE;
            $websiteUrl = (string)($defaults['website_url'] ?? $websiteUrl);
        } catch (\Throwable) {
        }

        $this->printing->note(__('仅发布语种：%{lang}（站 %{code}）', [
            'lang' => $lang,
            'code' => $websiteCode,
        ]));
        $result = $this->generator->publishOne($websiteCode, $lang, $websiteId, $websiteUrl);
        if (empty($result['ok'])) {
            throw new \RuntimeException((string)($result['error'] ?? 'publishOne failed'));
        }

        return [$websiteCode . '/' . $lang];
    }

    private function forgetLocaleFingerprints(SetupSourceFingerprint $fp, string $lang): int
    {
        $store = $fp->loadStore();
        $removed = 0;
        $suffix = ':' . $lang;
        foreach (\array_keys($store) as $key) {
            $key = (string)$key;
            if (\str_starts_with($key, 'static404:') && \str_ends_with($key, $suffix)) {
                unset($store[$key]);
                $removed++;
            }
        }
        if ($removed > 0) {
            $fp->saveStore($store);
        }

        return $removed;
    }

    private function reportSampleSizes(string $lang): void
    {
        $paths = [
            \Weline\Framework\Http\StorefrontNotFoundStaticPage::staticFilePath($lang, StaticErrorPageMap::DEFAULT_WEBSITE_CODE),
            \Weline\Framework\Http\StorefrontNotFoundStaticPage::staticFilePath($lang, ''),
        ];
        foreach ($paths as $path) {
            if (!\is_file($path)) {
                continue;
            }
            $bytes = (int)\filesize($path);
            $html = (string)\file_get_contents($path);
            $emptyBag = \preg_match(
                '/widgetTranslations\s*:\s*(\{\s*\}|\[\s*\])/',
                $html
            ) === 1;
            $hasFatBag = \preg_match(
                '/widgetTranslations\s*:\s*\{(?!\s*\})[^[]{200,}/',
                $html
            ) === 1;
            $this->printing->note(__(
                '抽检 %{path}：%{kb} KB，词典袋=%{bag}',
                [
                    'path' => $path,
                    'kb' => \round($bytes / 1024, 1),
                    'bag' => $hasFatBag ? '仍有多语内容' : ($emptyBag ? '已清空' : '未找到/未知'),
                ]
            ));
        }
    }

    public function tip(): string
    {
        return (string)__('发布前台 404 静态页（website×locale，与 setup:upgrade 后置同源）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'theme:publish-not-found-static',
            '发布前台 404 静态 HTML 到 pub/errors/storefront-not-found/（与 setup:upgrade 观察者同源）',
            [
                '-f, --force' => '清除 static404 指纹后强制重发',
                '-l, --lang=<locale>' => '只发一个语种（如 el_GR）',
                '-h, --help' => '显示帮助',
            ],
            [],
            [
                '全量发布' => 'php bin/w theme:publish-not-found-static',
                '强制全量' => 'php bin/w theme:publish-not-found-static --force',
                '强制单语' => 'php bin/w theme:publish-not-found-static --force --lang=el_GR',
            ]
        );
    }
}
