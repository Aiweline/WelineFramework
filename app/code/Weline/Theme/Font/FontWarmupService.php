<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Font;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * Collect fonts/languages and warm language subsets (upgrade-time).
 *
 * Automatic discovery: every active module's `{module}/view/fonts/` tree is included.
 * Optional extras:
 * - Theme/Font/etc/fonts.php
 * - Event {@see self::EVENT_WARMUP_COLLECT} (fonts / languages)
 */
class FontWarmupService
{
    public const EVENT_WARMUP_COLLECT = 'Weline_Theme_Font::warmup_collect';

    private FontSubsetService $subsetService;

    private FontDiscovery $discovery;

    private ?EventsManager $eventsManager;

    public function __construct(
        ?FontSubsetService $subsetService = null,
        ?EventsManager $eventsManager = null,
        ?FontDiscovery $discovery = null
    ) {
        $this->subsetService = $subsetService ?? new FontSubsetService();
        $this->eventsManager = $eventsManager;
        $this->discovery = $discovery ?? new FontDiscovery();
    }

    /**
     * Languages from built-in charset files (en, zh_Hans, …).
     *
     * @return list<string>
     */
    public function defaultLanguages(): array
    {
        $dir = LanguageCharsetResolver::CHARSET_DIR;
        if (!is_dir($dir)) {
            return [Env::default_LANGUAGE_CODE];
        }

        $langs = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.txt') ?: [] as $file) {
            $langs[] = pathinfo($file, PATHINFO_FILENAME);
        }
        sort($langs);

        return $langs !== [] ? $langs : [Env::default_LANGUAGE_CODE];
    }

    /**
     * @return array{
     *   fonts: list<array{path:string,languages:list<string>}>,
     *   languages: list<string>
     * }
     */
    public function collect(): array
    {
        $discovered = [];
        foreach ($this->discovery->discover() as $item) {
            $discovered[] = $item['path'];
        }

        $payload = [
            'fonts' => array_merge($discovered, $this->loadConfiguredFonts()),
            'languages' => $this->defaultLanguages(),
        ];

        $events = $this->eventsManager;
        if ($events === null && class_exists(ObjectManager::class)) {
            try {
                $events = ObjectManager::getInstance(EventsManager::class);
            } catch (\Throwable) {
                $events = null;
            }
        }

        if ($events instanceof EventsManager) {
            $events->dispatch(self::EVENT_WARMUP_COLLECT, $payload);
        }

        return $this->normalizeCollectPayload($payload);
    }

    /**
     * Warm all collected fonts × languages. Existing subsets are skipped.
     *
     * @param list<string>|null $languages Override global languages (null = collected)
     * @return array{
     *   built:int,
     *   skipped:int,
     *   failed:int,
     *   items:list<array{font:string,lang:string,status:string,path?:string,error?:string}>
     * }
     */
    public function warmup(?array $languages = null): array
    {
        $collected = $this->collect();
        $globalLangs = $languages ?? $collected['languages'];
        $built = 0;
        $skipped = 0;
        $failed = 0;
        $items = [];

        foreach ($collected['fonts'] as $font) {
            $path = $font['path'];
            $langs = $font['languages'] !== [] ? $font['languages'] : $globalLangs;
            foreach ($langs as $lang) {
                try {
                    $result = $this->subsetService->ensureLangSubset($path, $lang);
                    if ($result['skipped']) {
                        $skipped++;
                        $items[] = [
                            'font' => $path,
                            'lang' => $lang,
                            'status' => 'skipped',
                            'path' => $result['path'],
                        ];
                    } else {
                        $built++;
                        $items[] = [
                            'font' => $path,
                            'lang' => $lang,
                            'status' => 'built',
                            'path' => $result['path'],
                        ];
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $items[] = [
                        'font' => $path,
                        'lang' => $lang,
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'built' => $built,
            'skipped' => $skipped,
            'failed' => $failed,
            'items' => $items,
        ];
    }

    /**
     * @return list<array{path:string,languages:list<string>}|string>
     */
    private function loadConfiguredFonts(): array
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'fonts.php';
        if (!is_file($file)) {
            return [];
        }

        $data = include $file;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   fonts: list<array{path:string,languages:list<string>}>,
     *   languages: list<string>
     * }
     */
    private function normalizeCollectPayload(array $payload): array
    {
        $languages = [];
        foreach ((array)($payload['languages'] ?? []) as $lang) {
            $lang = trim((string)$lang);
            if ($lang !== '') {
                $languages[] = $this->subsetService->getCharsetResolver()->normalize($lang);
            }
        }
        $languages = array_values(array_unique($languages));
        if ($languages === []) {
            $languages = $this->defaultLanguages();
        }

        $fonts = [];
        $seen = [];
        foreach ((array)($payload['fonts'] ?? []) as $entry) {
            if (is_string($entry)) {
                $path = $entry;
                $fontLangs = [];
            } elseif (is_array($entry)) {
                $path = (string)($entry['path'] ?? $entry['file'] ?? $entry['src'] ?? '');
                $fontLangs = [];
                foreach ((array)($entry['languages'] ?? $entry['langs'] ?? []) as $lang) {
                    $lang = trim((string)$lang);
                    if ($lang !== '') {
                        $fontLangs[] = $this->subsetService->getCharsetResolver()->normalize($lang);
                    }
                }
                $fontLangs = array_values(array_unique($fontLangs));
            } else {
                continue;
            }

            $path = trim($path);
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $real = realpath($path) ?: $path;
            $key = $real . '|' . implode(',', $fontLangs);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fonts[] = [
                'path' => $real,
                'languages' => $fontLangs,
            ];
        }

        return [
            'fonts' => $fonts,
            'languages' => $languages,
        ];
    }
}
