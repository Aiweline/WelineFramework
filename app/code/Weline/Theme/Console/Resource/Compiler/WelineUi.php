<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Resource\Compiler;

use Weline\Framework\Resource\CompilerInterface;
use Weline\Theme\Service\Ui\AssetManifest;
use Weline\Theme\Service\Ui\IconRegistry;

final class WelineUi implements CompilerInterface
{
    public function __construct(
        private readonly AssetManifest $manifest,
        private readonly IconRegistry $icons,
    ) {
    }

    public function compile(string $source_file = '', string $out_file = ''): array
    {
        $outputRoot = $this->manifest->outputRoot();
        $this->ensureDirectory($outputRoot);

        $artifacts = [];
        $seenOutputs = [];
        foreach ($this->manifest->bundles() as $name => $bundle) {
            if (!is_string($name) || !is_array($bundle)) {
                throw new \RuntimeException(__('Weline UI bundle 声明无效'));
            }

            $output = trim((string)($bundle['output'] ?? ''), '/');
            if ($output === '' || isset($seenOutputs[$output]) || str_contains($output, '..')) {
                throw new \RuntimeException(__('Weline UI bundle 输出路径无效或重复：%{1}', [$output]));
            }
            $seenOutputs[$output] = true;

            $type = (string)($bundle['type'] ?? '');
            if (($bundle['generator'] ?? null) === 'icon_registry') {
                $content = $this->icons->sprite();
            } else {
                $content = $this->compileSources(
                    $name,
                    $type,
                    $bundle['sources'] ?? [],
                    $this->manifest->bundleSourceRoot($bundle),
                );
            }

            $artifacts[$name] = [
                'output' => $output,
                'type' => $type,
                'content' => $content,
            ];
        }

        $artifacts = $this->versionModuleDependencies($artifacts);

        $compiled = [];
        foreach ($artifacts as $name => $artifact) {
            $output = $artifact['output'];
            $content = $artifact['content'];
            $target = $outputRoot . $output;
            $this->ensureDirectory(dirname($target));
            $this->writeIfChanged($target, $content);
            $compiled[$name] = [
                'file' => $output,
                'type' => $artifact['type'],
                'bytes' => strlen($content),
                'gzip_bytes' => strlen((string)gzencode($content, 9)),
                'sha256' => hash('sha256', $content),
            ];
        }

        ksort($compiled);
        $buildManifest = $this->canonicalize([
            'schema_version' => AssetManifest::SCHEMA_VERSION,
            'bundles' => $compiled,
            'areas' => $this->manifest->areas(),
            'component_assets' => $this->manifest->componentAssets(),
            'lazy_components' => $this->manifest->lazyComponents(),
            'routes' => $this->manifest->routes(),
            'specialized_engines' => $this->manifest->specializedEngines(),
        ]);
        $buildManifest['build_id'] = hash('sha256', json_encode(
            $buildManifest,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
        $buildManifest = $this->canonicalize($buildManifest);
        $manifestJson = json_encode(
            $buildManifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        $this->writeIfChanged($outputRoot . 'manifest.json', $manifestJson);

        return $buildManifest;
    }

    /**
     * Add content-derived versions to relative ES module dependencies.
     *
     * Static assets are cached for a week by WLS. Versioning only the route
     * entry is insufficient because browsers cache its relative imports under
     * their unversioned URL. Resolve the compiled dependency graph from the
     * leaves upward so a transitive change also changes every importing URL.
     *
     * @param array<string,array{output:string,type:string,content:string}> $artifacts
     * @return array<string,array{output:string,type:string,content:string}>
     */
    private function versionModuleDependencies(array $artifacts): array
    {
        $outputIndex = [];
        foreach ($artifacts as $name => $artifact) {
            $outputIndex[$artifact['output']] = $name;
        }

        $resolved = [];
        $resolving = [];
        foreach (array_keys($artifacts) as $name) {
            $this->resolveModuleArtifact($name, $artifacts, $outputIndex, $resolved, $resolving);
        }

        foreach ($resolved as $name => $content) {
            $artifacts[$name]['content'] = $content;
        }

        return $artifacts;
    }

    /**
     * @param array<string,array{output:string,type:string,content:string}> $artifacts
     * @param array<string,string> $outputIndex
     * @param array<string,string> $resolved
     * @param array<string,true> $resolving
     */
    private function resolveModuleArtifact(
        string $name,
        array $artifacts,
        array $outputIndex,
        array &$resolved,
        array &$resolving,
    ): string {
        if (isset($resolved[$name])) {
            return $resolved[$name];
        }
        if (isset($resolving[$name])) {
            throw new \RuntimeException(__('Weline UI ES Module 依赖存在循环：%{1}', [$name]));
        }

        $artifact = $artifacts[$name] ?? null;
        if (!is_array($artifact)) {
            throw new \RuntimeException(__('Weline UI bundle 不存在：%{1}', [$name]));
        }
        $resolving[$name] = true;
        $content = $artifact['content'];

        if ($artifact['type'] === 'js') {
            $content = preg_replace_callback(
                '~(?<prefix>\b(?:from|import)\s*(?:\(\s*)?)(?<quote>["\'])(?<specifier>\.{1,2}/[^"\']+?\.js)(?:\?[^"\']*)?\k<quote>~',
                function (array $match) use ($artifact, $artifacts, $outputIndex, &$resolved, &$resolving): string {
                    $dependencyOutput = $this->resolveRelativeOutput(
                        $artifact['output'],
                        (string)$match['specifier'],
                    );
                    $dependencyName = $outputIndex[$dependencyOutput] ?? null;
                    if (!is_string($dependencyName)) {
                        return (string)$match[0];
                    }

                    $dependencyContent = $this->resolveModuleArtifact(
                        $dependencyName,
                        $artifacts,
                        $outputIndex,
                        $resolved,
                        $resolving,
                    );
                    $version = substr(hash('sha256', $dependencyContent), 0, 12);

                    return (string)$match['prefix']
                        . (string)$match['quote']
                        . (string)$match['specifier']
                        . '?v=' . $version
                        . (string)$match['quote'];
                },
                $content,
            );
            if (!is_string($content)) {
                throw new \RuntimeException(__('Weline UI ES Module 依赖版本化失败：%{1}', [$name]));
            }
        }

        unset($resolving[$name]);
        return $resolved[$name] = $content;
    }

    private function resolveRelativeOutput(string $fromOutput, string $specifier): string
    {
        $segments = explode('/', dirname($fromOutput) . '/' . $specifier);
        $normalized = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }

        return implode('/', $normalized);
    }

    private function compileSources(string $bundleName, string $type, mixed $sources, string $sourceRoot): string
    {
        if (!in_array($type, ['css', 'js'], true) || !is_array($sources) || $sources === []) {
            throw new \RuntimeException(__('Weline UI bundle %{1} 缺少有效源文件', [$bundleName]));
        }

        $parts = [];
        $seen = [];
        foreach ($sources as $relative) {
            $relative = ltrim((string)$relative, '/');
            if ($relative === '' || str_contains($relative, '..') || isset($seen[$relative])) {
                throw new \RuntimeException(__('Weline UI bundle %{1} 包含无效或重复源文件', [$bundleName]));
            }
            $seen[$relative] = true;
            $path = $sourceRoot . $relative;
            $content = @file_get_contents($path);
            if (!is_string($content)) {
                throw new \RuntimeException(__('Weline UI 源文件不存在：%{1}', [$path]));
            }
            $content = str_replace(["\r\n", "\r"], "\n", $content);
            $parts[] = "/* Weline UI source: {$relative} */\n" . trim($content) . "\n";
        }

        return implode("\n", $parts);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(__('无法创建 Weline UI 输出目录：%{1}', [$directory]));
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function writeIfChanged(string $path, string $content): void
    {
        if (is_file($path) && hash_equals(hash('sha256', $content), hash_file('sha256', $path))) {
            return;
        }

        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $content, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException(__('无法写入 Weline UI 编译产物：%{1}', [$path]));
        }
    }
}
