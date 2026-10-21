<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ui;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Minify\StaticAssetMinifier;

/**
 * 设计系统组件用量分析 + 首屏组件 CSS 内联。
 *
 * 编译期（resource:compile welineUi）扫描模板 class 使用，产出 usage.json；
 * 运行期 head 只内联该区域实际用到的组件 CSS（合并 + 压缩），
 * 组件 CSS 不再进入阻塞的 foundation 大包，首屏无额外 CSS 请求。
 *
 * 标准：app/code/Weline/Theme/doc/设计系统/组件与Token规范.md §2/§6
 */
final class ComponentUsageService
{
    public const USAGE_RELATIVE = 'app/code/Weline/Theme/view/statics/ui/components/usage.json';

    /** 组件注册表：key => [classes, output] */
    private const COMPONENTS = [
        'button' => [
            'classes' => ['w-button', 'w-button-group'],
            'output' => 'components/weline-ui-button.css',
        ],
        'field' => [
            'classes' => ['w-field', 'w-input', 'w-select', 'w-textarea', 'w-field__label', 'w-field__control'],
            'output' => 'components/weline-ui-field.css',
        ],
        'selection' => [
            'classes' => ['w-switch', 'w-check'],
            'output' => 'components/weline-ui-selection.css',
        ],
        'navigation' => [
            'classes' => ['w-tabs', 'w-tabs__list', 'w-tabs__tab', 'w-pagination', 'w-breadcrumb', 'w-menu'],
            'output' => 'components/weline-ui-navigation.css',
        ],
        'surface' => [
            'classes' => ['w-card', 'w-table', 'w-table-wrap', 'w-dialog', 'w-drawer', 'w-empty'],
            'output' => 'components/weline-ui-surface.css',
        ],
        'feedback' => [
            'classes' => ['w-badge', 'w-alert', 'w-spinner', 'w-skeleton', 'w-loading'],
            'output' => 'components/weline-ui-feedback.css',
        ],
    ];

    /** @var array<string, string> */
    private static array $inlineCache = [];

    /**
     * 扫描模板并写入组件用量清单（编译期调用）。
     *
     * @return array{areas: array<string, list<string>>, files: int, generated_at: string}
     */
    public function analyzeAndWrite(): array
    {
        $files = 0;
        $areas = ['frontend' => [], 'backend' => []];
        foreach ($this->templateFiles() as $file) {
            $content = @file_get_contents($file);
            if (!is_string($content) || $content === '') {
                continue;
            }
            $areas[$this->areaOf($file)] = \array_values(\array_unique(\array_merge(
                $areas[$this->areaOf($file)],
                $this->componentsIn($content)
            )));
            $files++;
        }
        foreach ($areas as $area => $components) {
            sort($components);
            $areas[$area] = $components;
        }
        $payload = [
            'areas' => $areas,
            'files' => $files,
            'generated_at' => \date('c'),
        ];
        $target = BP . self::USAGE_RELATIVE;
        if (!\is_dir(\dirname($target))) {
            @\mkdir(\dirname($target), 0775, true);
        }
        @\file_put_contents($target, \json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $payload;
    }

    /**
     * @return list<string>
     */
    public function componentKeysForArea(string $area): array
    {
        $area = $area === 'backend' ? 'backend' : 'frontend';
        $usage = $this->usage();
        $components = $usage['areas'][$area] ?? null;
        if (!\is_array($components) || $components === []) {
            return \array_keys(self::COMPONENTS);
        }
        return \array_values(\array_filter($components, static fn (string $key): bool => isset(self::COMPONENTS[$key])));
    }

    /**
     * 首屏内联：只包含该区域实际用到的组件 CSS（合并 + 压缩）。
     */
    public function renderInlineStyle(string $area): string
    {
        $area = $area === 'backend' ? 'backend' : 'frontend';
        if (isset(self::$inlineCache[$area])) {
            return self::$inlineCache[$area];
        }
        $css = '';
        foreach ($this->componentKeysForArea($area) as $key) {
            $output = self::COMPONENTS[$key]['output'];
            $file = BP . 'app/code/Weline/Theme/view/statics/ui/' . $output;
            if (\is_readable($file)) {
                $chunk = (string)\file_get_contents($file);
                if (\trim($chunk) !== '') {
                    $css .= $chunk . "\n";
                }
            }
        }
        $css = $this->minify($css);
        $html = $css === ''
            ? ''
            : '<style data-weline-ui-components="' . $area . '">' . \str_replace('</style', '<\\/style', $css) . '</style>';
        self::$inlineCache[$area] = $html;
        return $html;
    }

    /**
     * @return array{areas?: array<string, list<string>>}
     */
    private function usage(): array
    {
        $file = BP . self::USAGE_RELATIVE;
        if (!\is_readable($file)) {
            return [];
        }
        $data = \json_decode((string)\file_get_contents($file), true);
        return \is_array($data) ? $data : [];
    }

    /**
     * @return list<string>
     */
    private function componentsIn(string $content): array
    {
        $found = [];
        if (\preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $content, $matches) > 0) {
            foreach ($matches[2] as $classList) {
                foreach (\preg_split('/\s+/', (string)$classList) ?: [] as $token) {
                    $token = \strtolower(\trim($token));
                    if ($token === '') {
                        continue;
                    }
                    foreach (self::COMPONENTS as $key => $meta) {
                        if (\in_array($token, $meta['classes'], true)) {
                            $found[$key] = true;
                        }
                    }
                }
            }
        }
        return \array_keys($found);
    }

    /**
     * @return \Generator<string>
     */
    private function templateFiles(): \Generator
    {
        foreach (['templates', 'theme', 'blocks', 'widgets', 'hooks', 'components'] as $dir) {
            foreach (\glob(BP . 'app/code/*/*/view/' . $dir, GLOB_ONLYDIR) ?: [] as $root) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'phtml') {
                        yield $file->getPathname();
                    }
                }
            }
        }
    }

    private function areaOf(string $file): string
    {
        return \str_contains(\str_replace('\\', '/', $file), '/backend') ? 'backend' : 'frontend';
    }

    private function minify(string $css): string
    {
        try {
            $minifier = ObjectManager::getInstance(StaticAssetMinifier::class);
            $minified = $minifier->minifyFileContent($css, 'css');
            if (\is_string($minified) && \trim($minified) !== '') {
                return $minified;
            }
        } catch (\Throwable) {
        }
        return (string)\preg_replace('/\/\*.*?\*\//s', '', $css);
    }
}
