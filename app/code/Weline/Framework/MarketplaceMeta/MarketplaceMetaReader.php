<?php

declare(strict_types=1);

namespace Weline\Framework\MarketplaceMeta;

class MarketplaceMetaReader
{
    private const META_RELATIVE_PATH = 'etc/marketplace/meta.json';

    public function __construct(
        private readonly ?MarketplaceMetaValidator $validator = null
    ) {
    }

    /**
     * @return array{meta:?array,hash:string,path:string,warnings:string[]}
     */
    public function readFromModuleDir(string $moduleDir, ?string $expectedModuleName = null, bool $strict = false): array
    {
        $path = rtrim($moduleDir, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::META_RELATIVE_PATH);
        if (!is_file($path)) {
            if ($strict) {
                throw new \RuntimeException('marketplace_meta_missing_file');
            }
            return $this->emptyResult();
        }

        return $this->readFile($path, $expectedModuleName, null, $strict);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{meta:?array,hash:string,path:string,warnings:string[]}
     */
    public function readFromPackageDir(
        string $packageDir,
        string $moduleDir,
        array $manifest = [],
        ?string $expectedModuleName = null,
        bool $strict = false
    ): array {
        $declared = $manifest['marketplace_meta'] ?? null;
        if (is_array($declared) && trim((string)($declared['path'] ?? '')) !== '') {
            $relativePath = $this->normalizeRelativePath((string)$declared['path']);
            $path = rtrim($packageDir, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $this->assertReadablePackagePath($packageDir, $path);

            $actualHash = hash_file('sha256', $path) ?: '';
            $declaredHash = strtolower(trim((string)($declared['sha256'] ?? '')));
            if ($declaredHash !== '' && $actualHash !== strtolower($declaredHash)) {
                throw new \RuntimeException('marketplace_meta_hash_mismatch');
            }

            return $this->readFile($path, $expectedModuleName, $actualHash, $strict);
        }

        return $this->readFromModuleDir($moduleDir, $expectedModuleName, $strict);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function hash(array $meta): string
    {
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return is_string($json) ? hash('sha256', $json) : '';
    }

    /**
     * @return array{meta:?array,hash:string,path:string,warnings:string[]}
     */
    private function readFile(string $path, ?string $expectedModuleName = null, ?string $knownHash = null, bool $strict = false): array
    {
        $hash = $knownHash ?: (hash_file('sha256', $path) ?: '');
        $json = file_get_contents($path);
        if (!is_string($json) || trim($json) === '') {
            if ($strict) {
                throw new \RuntimeException('meta_empty_file');
            }
            return [
                'meta' => null,
                'hash' => $hash,
                'path' => $path,
                'warnings' => ['meta_empty_file'],
            ];
        }

        $meta = json_decode($json, true);
        if (!is_array($meta)) {
            if ($strict) {
                throw new \RuntimeException('meta_invalid_json');
            }
            return [
                'meta' => null,
                'hash' => $hash,
                'path' => $path,
                'warnings' => ['meta_invalid_json'],
            ];
        }

        $meta = $this->mergeLocaleOverrides($path, $this->normalizeMeta($meta));
        $validation = $this->validator()->validate($meta, $expectedModuleName, $strict);
        if ($validation['errors'] !== []) {
            throw new \RuntimeException(implode(',', $validation['errors']));
        }
        $meta = $this->ensurePrimaryTag($meta);

        return [
            'meta' => $meta,
            'hash' => $hash,
            'path' => $path,
            'warnings' => $validation['warnings'],
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function normalizeMeta(array $meta): array
    {
        $meta['schema_version'] = (int)($meta['schema_version'] ?? 0);
        $meta['module_name'] = trim((string)($meta['module_name'] ?? ''));
        if (!isset($meta['i18n']) || !is_array($meta['i18n'])) {
            $meta['i18n'] = [];
        }
        if (!isset($meta['i18n']['locales']) || !is_array($meta['i18n']['locales'])) {
            $meta['i18n']['locales'] = [];
        }
        $sourceLocale = trim((string)($meta['i18n']['source_locale'] ?? 'zh_Hans_CN')) ?: 'zh_Hans_CN';

        $tags = [];
        foreach ((array)($meta['tags'] ?? []) as $index => $tag) {
            if (is_string($tag)) {
                $tag = ['code' => $tag];
            }
            if (!is_array($tag)) {
                continue;
            }
            $code = $this->normalizeTagCode((string)($tag['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $type = trim((string)($tag['type'] ?? ''));
            if ($type === '') {
                $type = str_starts_with($code, 'custom.') ? 'custom' : 'system';
            }
            $labels = is_array($tag['labels'] ?? null) ? $tag['labels'] : [];
            $label = $tag['label'] ?? null;
            if (is_array($label)) {
                $labels = array_replace($labels, $label);
            } elseif (is_string($label) && trim($label) !== '') {
                $labels[$sourceLocale] = trim($label);
            }
            $tag['code'] = $code;
            $tag['type'] = $type;
            $tag['labels'] = $labels;
            unset($tag['label']);
            $tag['primary'] = !empty($tag['primary']);
            $tag['sort_order'] = (int)($tag['sort_order'] ?? $index);
            $tags[] = $tag;
        }
        $meta['tags'] = $tags;

        $surfaces = [];
        foreach ((array)($meta['surfaces'] ?? []) as $surface) {
            $surface = trim((string)$surface);
            if ($surface !== '') {
                $surfaces[$surface] = true;
            }
        }
        foreach ($tags as $tag) {
            $code = (string)($tag['code'] ?? '');
            if (str_starts_with($code, 'surface.')) {
                $surfaces[substr($code, strlen('surface.'))] = true;
            }
        }
        $meta['surfaces'] = array_keys($surfaces);

        return $meta;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function ensurePrimaryTag(array $meta): array
    {
        $hasPrimary = false;
        foreach ((array)($meta['tags'] ?? []) as $tag) {
            if (is_array($tag) && !empty($tag['primary'])) {
                $hasPrimary = true;
                break;
            }
        }
        if ($hasPrimary || empty($meta['tags']) || !is_array($meta['tags'])) {
            return $meta;
        }

        $first = array_key_first($meta['tags']);
        if ($first !== null && is_array($meta['tags'][$first])) {
            $meta['tags'][$first]['primary'] = true;
        }

        return $meta;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function mergeLocaleOverrides(string $metaPath, array $meta): array
    {
        $dir = dirname($metaPath);
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'meta.*.json') ?: [] as $overridePath) {
            if (basename($overridePath) === 'meta.json') {
                continue;
            }
            $override = json_decode((string)file_get_contents($overridePath), true);
            if (is_array($override)) {
                $meta = array_replace_recursive($meta, $override);
            }
        }

        return $this->normalizeMeta($meta);
    }

    private function normalizeTagCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = str_replace('-', '_', $code);
        return trim($code, '.');
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, ':')) {
            throw new \RuntimeException('marketplace_meta_invalid_path');
        }

        return $path;
    }

    private function assertReadablePackagePath(string $packageDir, string $path): void
    {
        $realRoot = realpath($packageDir);
        $realPath = realpath($path);
        if (!$realRoot || !$realPath || !is_file($realPath)) {
            throw new \RuntimeException('marketplace_meta_missing_file');
        }

        $root = str_replace('\\', '/', rtrim($realRoot, '\\/')) . '/';
        $candidate = str_replace('\\', '/', $realPath);
        if (!str_starts_with($candidate, $root)) {
            throw new \RuntimeException('marketplace_meta_invalid_path');
        }
    }

    /**
     * @return array{meta:?array,hash:string,path:string,warnings:string[]}
     */
    private function emptyResult(): array
    {
        return [
            'meta' => null,
            'hash' => '',
            'path' => '',
            'warnings' => [],
        ];
    }

    private function validator(): MarketplaceMetaValidator
    {
        return $this->validator ?? new MarketplaceMetaValidator();
    }
}
