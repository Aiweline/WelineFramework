<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Extra;

use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;

/**
 * 升级期收集控制器 Extra 注解（docblock 键 type=）声明；未知 type 失败。
 * 本类注释禁止写字面 @Extra，以免被自身扫描误解析。
 */
final class ExtraCollector
{
    public const SCHEMA = 'controller-extra.v1';
    public const SIDECAR_RELATIVE = 'generated/framework/controller_extra.php';

    public function __construct(
        private readonly ExtraTypeRegistry $types,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collectAll(): array
    {
        $declarations = [];
        foreach (Env::getInstance()->getModuleList() as $moduleName => $module) {
            if (!($module['status'] ?? false)) {
                continue;
            }
            $declarations = array_merge($declarations, $this->collectModule((string)$moduleName, $module));
        }
        return $declarations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collectAndPersist(): array
    {
        $declarations = $this->collectAll();
        $path = BP . self::SIDECAR_RELATIVE;
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception(__('无法创建 Extra 侧车目录'));
        }
        $export = var_export([
            'schema_version' => self::SCHEMA,
            'declarations' => $declarations,
        ], true);
        if (file_put_contents($path, "<?php\nreturn " . $export . ";\n") === false) {
            throw new Exception(__('无法写入 Extra 侧车'));
        }
        return $declarations;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function loadSidecar(): ?array
    {
        $path = BP . self::SIDECAR_RELATIVE;
        if (!is_file($path)) {
            return null;
        }
        $data = include $path;
        if (!is_array($data) || ($data['schema_version'] ?? '') !== self::SCHEMA) {
            return null;
        }
        $list = $data['declarations'] ?? null;
        return is_array($list) ? $list : null;
    }

    /**
     * @param array<string, mixed> $module
     * @return list<array<string, mixed>>
     */
    private function collectModule(string $moduleName, array $module): array
    {
        $basePath = (string)($module['base_path'] ?? '');
        if ($basePath === '') {
            return [];
        }
        $out = [];
        foreach (['Controller', 'Api'] as $segment) {
            $dir = $basePath . DIRECTORY_SEPARATOR . $segment;
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->scanPhpFiles($dir) as $file) {
                $class = $this->classFromFile($file);
                if ($class === '') {
                    continue;
                }
                if (!class_exists($class, false) && is_file($file)) {
                    require_once $file;
                }
                if (!class_exists($class, false)) {
                    continue;
                }
                try {
                    $out = array_merge($out, $this->collectClass(new ReflectionClass($class), $moduleName, $module));
                } catch (\Throwable $e) {
                    if ($e instanceof Exception) {
                        throw $e;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $module
     * @return list<array<string, mixed>>
     */
    private function collectClass(ReflectionClass $reflection, string $moduleName, array $module): array
    {
        $out = [];
        $classExtra = $this->parseExtraDoc($reflection->getDocComment() ?: '');
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            $methodExtra = $this->parseExtraDoc($method->getDocComment() ?: '');
            $extra = $methodExtra ?? $classExtra;
            if ($extra === null) {
                continue;
            }
            $type = strtolower(trim((string)($extra['type'] ?? '')));
            if ($type === '') {
                throw new Exception(__('控制器 %{class}::%{method} 的 @Extra 缺少 type', [
                    'class' => $reflection->getName(),
                    'method' => $method->getName(),
                ]));
            }
            $provider = $this->types->get($type);
            if ($provider === null) {
                throw new Exception(__(
                    '未知 Extra type=%{type}（已注册：%{registered}）于 %{class}::%{method}',
                    [
                        'type' => $type,
                        'registered' => implode(', ', $this->types->registeredTypes()) ?: '(无)',
                        'class' => $reflection->getName(),
                        'method' => $method->getName(),
                    ]
                ));
            }
            $normalized = $provider->normalize($extra);
            $path = $this->resolvePath($reflection, $method, $module);
            $patterns = [];
            if (!empty($normalized['public_path_patterns']) && is_array($normalized['public_path_patterns'])) {
                $patterns = array_values(array_filter(array_map('strval', $normalized['public_path_patterns'])));
            }
            $out[] = [
                'module' => $moduleName,
                'class' => $reflection->getName(),
                'method' => $method->getName(),
                'path_pattern' => $path,
                'public_path_patterns' => $patterns,
                'type' => $type,
                'attrs' => $normalized,
                'namespaces' => is_array($normalized['namespaces'] ?? null)
                    ? array_values($normalized['namespaces'])
                    : [],
            ];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function parseExtraDoc(string $doc): ?array
    {
        // 不可用 [^\n*]：public_path_patterns 的 * 通配会被截断。
        if ($doc === '' || !preg_match('/@Extra\s+([^\n]+)/', $doc, $m)) {
            return null;
        }
        // 去掉行尾 PHPDoc 残留的 " */" 或单独的闭合星号，但保留 pattern 通配 *
        $raw = rtrim((string)$m[1]);
        $raw = preg_replace('/\s*\*\/\s*$/', '', $raw) ?? $raw;
        $raw = rtrim($raw);
        $attrs = ['type' => ''];
        if (preg_match_all('/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))/', $raw, $parts, PREG_SET_ORDER)) {
            foreach ($parts as $part) {
                $key = strtolower((string)$part[1]);
                $val = $part[2] !== '' ? $part[2] : ($part[3] !== '' ? $part[3] : $part[4]);
                if ($key === 'namespaces' || $key === 'public_path_patterns') {
                    $attrs[$key] = array_values(array_filter(array_map('trim', explode(',', (string)$val))));
                } elseif ($key === 'enabled') {
                    $attrs[$key] = !in_array(strtolower((string)$val), ['0', 'false', 'no', 'off'], true);
                } elseif ($key === 'ttl') {
                    $attrs[$key] = (int)$val;
                } else {
                    $attrs[$key] = $val;
                }
            }
        }
        return $attrs;
    }

    /** @param array<string, mixed> $module */
    private function resolvePath(ReflectionClass $reflection, ReflectionMethod $method, array $module): string
    {
        $routerPath = (string)($module['router'] ?? $module['name'] ?? '');
        $class = $reflection->getName();
        $slice = [];
        $capture = false;
        foreach (explode('\\', $class) as $p) {
            if ($p === 'Controller' || $p === 'Api') {
                $capture = true;
                continue;
            }
            if ($capture) {
                $slice[] = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $p) ?? $p);
            }
        }
        $relative = implode('/', $slice);
        $action = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $method->getName()) ?? $method->getName());
        if ($action === 'index') {
            $action = '';
        }
        $path = '/' . trim($routerPath . '/' . $relative . ($action !== '' ? '/' . $action : ''), '/');
        return $path === '' ? '/' : $path;
    }

    /** @return list<string> */
    private function scanPhpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private function classFromFile(string $file): string
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return '';
        }
        $namespace = '';
        if (preg_match('/namespace\s+([^;]+);/', $content, $m) === 1) {
            $namespace = trim((string)$m[1]);
        }
        if (preg_match('/class\s+(\w+)/', $content, $m) !== 1) {
            return '';
        }
        $class = (string)$m[1];
        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }
}
