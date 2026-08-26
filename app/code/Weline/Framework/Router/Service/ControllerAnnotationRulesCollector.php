<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Service;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Router\Attribute\Attack;
use Weline\Framework\Service\Query\Attribute\BinQueryCache;

/**
 * Collects neutral @Cdn / @Attack docblocks and PHP attributes, resolves route paths, dispatches event.
 */
final class ControllerAnnotationRulesCollector
{
    public const EVENT = 'Weline_Framework::controller_annotation_rules_collected';
    public const SCHEMA = 'controller-annotation-rules.v1';

    public function __construct(
        private readonly EventsManager $eventsManager,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collectAll(): array
    {
        $rules = [];
        foreach (Env::getInstance()->getModuleList() as $moduleName => $module) {
            if (!($module['status'] ?? false)) {
                continue;
            }
            $rules = array_merge($rules, $this->collectModule($moduleName, $module));
        }
        $rules = array_merge($rules, $this->collectBinQueryRules());

        $this->eventsManager->dispatch(self::EVENT, [
            'schema_version' => self::SCHEMA,
            'rules' => $rules,
        ]);

        return $rules;
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

        $rules = [];
        foreach (['Controller', 'Api'] as $segment) {
            $dir = $basePath . DIRECTORY_SEPARATOR . $segment;
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($this->scanPhpFiles($dir) as $file) {
                $class = $this->classFromFile($file, $module);
                if ($class === '' || !class_exists($class, false)) {
                    if (is_file($file)) {
                        require_once $file;
                    }
                }
                if ($class === '' || !class_exists($class, false)) {
                    continue;
                }
                try {
                    $rules = array_merge($rules, $this->collectClass(new ReflectionClass($class), $moduleName, $module));
                } catch (\Throwable) {
                }
            }
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $module
     * @return list<array<string, mixed>>
     */
    private function collectClass(ReflectionClass $reflection, string $moduleName, array $module): array
    {
        $rules = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            $cache = $this->parseCdnDoc($method->getDocComment() ?: '');
            $attackDoc = $this->parseAttackDoc($method->getDocComment() ?: '');
            $attackAttr = $this->parseAttackAttribute($method);
            $attack = $attackAttr ?? $attackDoc;
            if ($cache === null && $attack === null) {
                continue;
            }
            $path = $this->resolvePath($reflection, $method, $module);
            $rules[] = [
                'rule_id' => sha1($moduleName . '|' . $reflection->getName() . '|' . $method->getName() . '|' . $path),
                'module' => $moduleName,
                'class' => $reflection->getName(),
                'method' => $method->getName(),
                'http_method' => 'GET',
                'path_pattern' => $path,
                'cache' => $cache,
                'attack' => $attack,
                'description' => (string)($attack['description'] ?? $cache['description'] ?? ''),
                'source' => 'annotation',
            ];
        }

        return $rules;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectBinQueryRules(): array
    {
        $rules = [];
        try {
            $registryClass = \Weline\Framework\Service\Query\QueryProviderRegistry::class;
            if (!class_exists($registryClass)) {
                return [];
            }
            $registry = \Weline\Framework\Manager\ObjectManager::getInstance($registryClass);
            foreach ($registry->getDescriptors() as $descriptor) {
                $module = (string)($descriptor['module'] ?? '');
                foreach ((array)($descriptor['operations'] ?? []) as $operation) {
                    $providerClass = (string)($operation['provider_class'] ?? '');
                    $methodName = (string)($operation['method'] ?? '');
                    if ($providerClass === '' || $methodName === '' || !class_exists($providerClass)) {
                        continue;
                    }
                    $method = new ReflectionMethod($providerClass, $methodName);
                    $cacheAttr = $method->getAttributes(BinQueryCache::class)[0] ?? null;
                    if (!$cacheAttr instanceof ReflectionAttribute) {
                        continue;
                    }
                    /** @var BinQueryCache $cache */
                    $cache = $cacheAttr->newInstance();
                    $rules[] = [
                        'rule_id' => sha1('binquery|' . $providerClass . '|' . $methodName),
                        'module' => $module,
                        'class' => $providerClass,
                        'method' => $methodName,
                        'http_method' => 'POST',
                        'path_pattern' => '/api/query/' . (string)($descriptor['name'] ?? '') . '/' . (string)($operation['name'] ?? ''),
                        'cache' => $cache->toDescriptor(),
                        'attack' => null,
                        'description' => $cache->description,
                        'source' => 'bin_query_cache',
                    ];
                }
            }
        } catch (\Throwable) {
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseCdnDoc(string $doc): ?array
    {
        if (!preg_match('/@Cdn\s+(.+)/s', $doc, $matches)) {
            return null;
        }
        $config = trim($matches[1]);
        $rule = ['source' => 'docblock'];
        if (preg_match('/cache\s*=\s*(\S+)/', $config, $m)) {
            $rule['cache'] = $m[1] === 'false' ? false : $m[1];
        }
        if (preg_match('/description\s*=\s*"([^"]+)"/', $config, $m)) {
            $rule['description'] = $m[1];
        }

        return $rule;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseAttackDoc(string $doc): ?array
    {
        if (!preg_match('/@Attack\s+(.+)/s', $doc, $matches)) {
            return null;
        }
        $config = trim($matches[1]);
        $rule = [];
        if (preg_match('/rate_limit\s*=\s*(\S+)/', $config, $m)) {
            $rule['rate_limit'] = $m[1];
        }
        if (preg_match('/challenge\s*=\s*(\S+)/', $config, $m)) {
            $rule['challenge'] = $m[1];
        }
        if (preg_match('/burst\s*=\s*(\d+)/', $config, $m)) {
            $rule['burst'] = (int)$m[1];
        }
        if (preg_match('/enabled\s*=\s*(true|false)/i', $config, $m)) {
            $rule['enabled'] = strtolower($m[1]) === 'true';
        }
        if (preg_match('/description\s*=\s*"([^"]+)"/', $config, $m)) {
            $rule['description'] = $m[1];
        }

        return $rule === [] ? null : $rule;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseAttackAttribute(ReflectionMethod $method): ?array
    {
        $attributes = $method->getAttributes(Attack::class);
        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->toRuleSegment();
    }

    /**
     * @param array<string, mixed> $module
     */
    private function resolvePath(ReflectionClass $reflection, ReflectionMethod $method, array $module): string
    {
        $router = trim((string)($module['router'] ?? ''), '/');
        $class = $reflection->getName();
        $methodName = $method->getName();
        $isApi = str_contains($class, '\\Api\\');
        $parts = [];
        if ($router !== '') {
            $parts[] = $router;
        }
        if ($isApi) {
            $parts[] = 'rest';
            $parts[] = 'v1';
        }
        $relative = str_replace('\\', '/', preg_replace('#^.+\\\\(Controller|Api)\\\\#', '', $class) ?? '');
        $segments = array_values(array_filter(explode('/', strtolower($relative)), static fn (string $s): bool => $s !== ''));
        foreach ($segments as $segment) {
            $parts[] = str_replace('_', '-', $segment);
        }
        if (strtolower($methodName) !== 'index') {
            $parts[] = str_replace('_', '-', strtolower($methodName));
        }

        return '/' . implode('/', $parts);
    }

    /**
     * @return list<string>
     */
    private function scanPhpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $module
     */
    private function classFromFile(string $file, array $module): string
    {
        $contents = @file_get_contents($file);
        if (!is_string($contents) || !preg_match('/namespace\s+([^;]+);/', $contents, $ns)) {
            return '';
        }
        if (!preg_match('/\b(class|interface|trait|enum)\s+(\w+)/', $contents, $class)) {
            return '';
        }

        return trim($ns[1]) . '\\' . $class[2];
    }
}
