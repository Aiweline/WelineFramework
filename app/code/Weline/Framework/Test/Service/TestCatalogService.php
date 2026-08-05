<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Service;

/**
 * Thin catalog facade over TestCollectionService for admin/query use.
 */
final class TestCatalogService
{
    public function __construct(
        private readonly TestCollectionService $collectionService = new TestCollectionService(),
    ) {
    }

    /**
     * @return array{
     *   generated_at:string,
     *   total_modules:int,
     *   total_tests:int,
     *   modules:array<string,array<string,mixed>>
     * }
     */
    public function listModules(?string $type = null): array
    {
        $collection = $this->collectionService->collect($type);
        $modules = [];
        foreach ($collection['modules'] as $moduleName => $module) {
            $counts = is_array($module['counts'] ?? null) ? $module['counts'] : [];
            $modules[$moduleName] = [
                'module' => $moduleName,
                'base_path' => (string)($module['base_path'] ?? ''),
                'count' => (int)($module['count'] ?? 0),
                'counts' => [
                    'e2e' => (int)($counts['e2e'] ?? 0),
                    'unit' => (int)($counts['unit'] ?? 0),
                    'integration' => (int)($counts['integration'] ?? 0),
                    'phpunit' => (int)($counts['phpunit'] ?? 0),
                    'php_e2e' => (int)($counts['php_e2e'] ?? 0),
                ],
            ];
        }

        return [
            'generated_at' => (string)($collection['generated_at'] ?? date('c')),
            'total_modules' => (int)($collection['total_modules'] ?? count($modules)),
            'total_tests' => (int)($collection['total_tests'] ?? 0),
            'modules' => $modules,
        ];
    }

    /**
     * @return array{
     *   module:string,
     *   base_path:string,
     *   counts:array<string,int>,
     *   tests:array<string,list<string>>
     * }
     */
    public function listCases(string $module, ?string $type = null): array
    {
        $resolved = $this->collectionService->resolveModuleName($module);
        if ($resolved === null) {
            throw new \InvalidArgumentException((string)__('模块不存在或未激活：%{1}', [$module]));
        }

        $collection = $this->collectionService->collect($type, $resolved);
        $node = $collection['modules'][$resolved] ?? null;
        if (!is_array($node)) {
            return [
                'module' => $resolved,
                'base_path' => '',
                'counts' => [
                    'e2e' => 0,
                    'unit' => 0,
                    'integration' => 0,
                    'phpunit' => 0,
                    'php_e2e' => 0,
                ],
                'tests' => [
                    'e2e' => [],
                    'unit' => [],
                    'integration' => [],
                    'phpunit' => [],
                    'php_e2e' => [],
                ],
            ];
        }

        $counts = is_array($node['counts'] ?? null) ? $node['counts'] : [];
        $tests = is_array($node['tests'] ?? null) ? $node['tests'] : [];

        return [
            'module' => $resolved,
            'base_path' => (string)($node['base_path'] ?? ''),
            'counts' => [
                'e2e' => (int)($counts['e2e'] ?? 0),
                'unit' => (int)($counts['unit'] ?? 0),
                'integration' => (int)($counts['integration'] ?? 0),
                'phpunit' => (int)($counts['phpunit'] ?? 0),
                'php_e2e' => (int)($counts['php_e2e'] ?? 0),
            ],
            'tests' => [
                'e2e' => array_values(array_filter($tests['e2e'] ?? [], 'is_string')),
                'unit' => array_values(array_filter($tests['unit'] ?? [], 'is_string')),
                'integration' => array_values(array_filter($tests['integration'] ?? [], 'is_string')),
                'phpunit' => array_values(array_filter($tests['phpunit'] ?? [], 'is_string')),
                'php_e2e' => array_values(array_filter($tests['php_e2e'] ?? [], 'is_string')),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function refresh(?string $type = null, ?string $module = null): array
    {
        $collection = $this->collectionService->collect($type, $module);
        if ($type === 'e2e' || $type === null) {
            $manifest = $this->collectionService->collectE2eManifest($module);
            $file = BP . 'tests' . DIRECTORY_SEPARATOR . 'e2e' . DIRECTORY_SEPARATOR . 'collected-tests.json';
            $this->collectionService->writeJson($manifest, $file);
        }

        return $collection;
    }
}
