<?php

declare(strict_types=1);

namespace Weline\Framework\UnitTest\Console\Test;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\Service\TestCollectionService;

class Collect extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $type = $this->pick($args, ['type', 't']);
        $module = $this->pick($args, ['module', 'm']);
        $format = strtolower($this->pick($args, ['format', 'f']) ?: 'text');
        $output = $this->pick($args, ['output', 'o']);
        $e2eLegacy = isset($args['e2e_legacy']) || isset($args['e2e-legacy']);

        /** @var TestCollectionService $collector */
        $collector = ObjectManager::getInstance(TestCollectionService::class);
        $manifest = $e2eLegacy || $type === 'e2e'
            ? $collector->collectE2eManifest($module !== '' ? $module : null)
            : $collector->collect($type !== '' ? $type : null, $module !== '' ? $module : null);

        if ($output !== '') {
            $outputPath = str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : BP . ltrim($output, DIRECTORY_SEPARATOR);
            if (!$collector->writeJson($manifest, $outputPath)) {
                $this->printer->error(__('测试清单写入失败：%{1}', [$outputPath]));
                return 1;
            }
        }

        if ($format === 'json') {
            echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            return 0;
        }

        $this->printer->note(__('测试收集完成：模块 %{1} 个，文件 %{2} 个', [
            (string)($manifest['total_modules'] ?? count($manifest['modules'] ?? [])),
            (string)($manifest['total_tests'] ?? 0),
        ]));

        foreach (($manifest['modules'] ?? []) as $moduleName => $moduleInfo) {
            $count = (int)($moduleInfo['count'] ?? 0);
            $this->printer->note(' - ' . $moduleName . ' (' . $count . ')');
        }

        return 0;
    }

    public function tip(): string
    {
        return __('收集模块内单元测试、集成测试和 E2E 测试');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'test:collect [options]',
            __('从各模块 test/Test 目录收集测试清单。'),
            [
                '--type=unit|integration|e2e|phpunit' => __('只收集指定类型'),
                '--module=Vendor_Module' => __('只收集指定模块'),
                '--format=json' => __('输出 JSON manifest'),
                '--output=PATH' => __('同时写入 JSON 文件'),
            ],
            [],
            [
                __('收集全部测试') => 'php bin/w test:collect',
                __('收集 E2E 并写入 Playwright 清单') => 'php bin/w test:collect --type=e2e --output=tests/e2e/collected-tests.json',
                __('输出 JSON') => 'php bin/w test:collect --format=json',
            ]
        );
    }

    private function pick(array $args, array $keys): string
    {
        foreach ($keys as $key) {
            if (!isset($args[$key]) || is_bool($args[$key])) {
                continue;
            }
            $value = trim((string)$args[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
