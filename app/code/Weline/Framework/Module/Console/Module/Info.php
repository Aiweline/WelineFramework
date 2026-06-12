<?php

declare(strict_types=1);

namespace Weline\Framework\Module\Console\Module;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\MarketplaceMeta\MarketplaceMetaReader;

class Info extends CommandAbstract
{
    public const ALIASES = ['module:info'];

    public function execute(array $args = [], array $data = [])
    {
        $moduleName = $this->resolveModuleName($args);
        if ($moduleName === '') {
            $this->printer->error(__('请提供模块名称，例如：php bin/w module:info Weline_AppStore'));
            return;
        }

        $locale = $this->resolveLocale($args);
        $moduleList = Env::getInstance()->getModuleList(true);
        $moduleInfo = is_array($moduleList[$moduleName] ?? null) ? $moduleList[$moduleName] : [];
        if ($moduleInfo === []) {
            $this->printer->error(__('不存在的模块：') . $moduleName);
            return;
        }

        $moduleDir = $this->moduleDir($moduleName, $moduleInfo);
        /** @var MarketplaceMetaReader $reader */
        $reader = ObjectManager::getInstance(MarketplaceMetaReader::class);
        try {
            $metaResult = $reader->readFromModuleDir($moduleDir, $moduleName);
        } catch (\Throwable $e) {
            $this->printer->error(__('Marketplace Meta 校验失败：') . $e->getMessage());
            return;
        }

        $this->printer->note('module: ' . $moduleName);
        $this->printer->note('status: ' . (!empty($moduleInfo['status']) ? 'enabled' : 'disabled'));
        $this->printer->note('version: ' . (string)($moduleInfo['version'] ?? ''));
        $this->printer->note('path: ' . $moduleDir);

        $meta = is_array($metaResult['meta'] ?? null) ? $metaResult['meta'] : [];
        if ($meta === []) {
            $this->printer->note('marketplace_meta: none');
            return;
        }

        $this->printer->note('marketplace_meta: ' . (string)($metaResult['path'] ?? ''));
        $this->printer->note('marketplace_meta_hash: ' . (string)($metaResult['hash'] ?? ''));
        foreach ((array)($metaResult['warnings'] ?? []) as $warning) {
            $this->printer->warning('meta_warning: ' . (string)$warning);
        }

        $localized = $this->localizedInfo($meta, $locale);
        if (!empty($localized['display_name'])) {
            $this->printer->note('display_name: ' . (string)$localized['display_name']);
        }
        if (!empty($localized['description'])) {
            $this->printer->note('description: ' . (string)$localized['description']);
        }

        $surfaces = array_values(array_filter(array_map('strval', (array)($meta['surfaces'] ?? []))));
        $this->printer->note('surfaces: ' . ($surfaces ? implode(', ', $surfaces) : '-'));
        $this->printer->note('tags:');
        foreach ($this->localizedTags($meta, $locale) as $tag) {
            $primary = !empty($tag['primary']) ? ' primary' : '';
            $this->printer->note('  - ' . $tag['code'] . $primary . ': ' . $tag['label']);
        }
    }

    public function tip(): string
    {
        return '查看模块 Marketplace Meta 信息';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'module:info',
            $this->tip(),
            [
                'module' => '模块名称，例如 Weline_AppStore',
                '-l, --locale' => '输出本地化标签的 locale，默认使用 user.lang',
            ],
            [
                'php bin/w module:info Weline_AppStore --locale=en_US' => '查看模块 meta/tag 信息',
            ],
            []
        );
    }

    private function resolveModuleName(array $args): string
    {
        $candidate = trim((string)($args['module'] ?? $args['m'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
        foreach ($args as $key => $value) {
            if (!is_int($key)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '' && str_contains($value, '_')) {
                return $value;
            }
        }
        return '';
    }

    private function resolveLocale(array $args): string
    {
        $locale = trim((string)($args['locale'] ?? $args['l'] ?? ''));
        return $locale !== '' ? $locale : (string)w_env('user.lang', Env::get('user.lang', 'zh_Hans_CN'));
    }

    private function moduleDir(string $moduleName, array $moduleInfo): string
    {
        foreach (['dir', 'base_path', 'path'] as $key) {
            $path = trim((string)($moduleInfo[$key] ?? ''));
            if ($path === '') {
                continue;
            }
            if (is_file($path)) {
                return dirname($path);
            }
            if (is_dir($path)) {
                return $path;
            }
        }

        [$vendor, $module] = explode('_', $moduleName, 2);
        return rtrim(APP_CODE_PATH, DS) . DS . $vendor . DS . $module;
    }

    /**
     * @return array<string, mixed>
     */
    private function localizedInfo(array $meta, string $locale): array
    {
        $i18n = is_array($meta['i18n'] ?? null) ? $meta['i18n'] : [];
        $locales = is_array($i18n['locales'] ?? null) ? $i18n['locales'] : [];
        $sourceLocale = (string)($i18n['source_locale'] ?? 'zh_Hans_CN');
        $source = is_array($locales[$sourceLocale] ?? null) ? $locales[$sourceLocale] : [];
        $localized = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];

        return array_replace($source, $localized);
    }

    /**
     * @return array<int, array{code:string,label:string,primary:bool}>
     */
    private function localizedTags(array $meta, string $locale): array
    {
        $sourceLocale = (string)($meta['i18n']['source_locale'] ?? 'zh_Hans_CN');
        $tags = [];
        foreach ((array)($meta['tags'] ?? []) as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $code = trim((string)($tag['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $labels = is_array($tag['labels'] ?? null) ? $tag['labels'] : [];
            $label = (string)($labels[$locale] ?? $labels[$sourceLocale] ?? $code);
            $tags[] = [
                'code' => $code,
                'label' => $label,
                'primary' => !empty($tag['primary']),
            ];
        }

        return $tags;
    }
}
