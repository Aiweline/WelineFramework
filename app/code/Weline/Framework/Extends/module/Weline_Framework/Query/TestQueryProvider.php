<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Controller\Backend\Test as TestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Test\Model\TestRun;
use Weline\Framework\Test\Service\TestCatalogService;
use Weline\Framework\Test\Service\TestRunService;
use Weline\Framework\Test\Service\TestUiSettings;

final class TestQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'test';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'listModules' => $this->catalog()->listModules($this->nullableString($params, 'type')),
            'listCases' => $this->catalog()->listCases(
                $this->requiredString($params, 'module'),
                $this->nullableString($params, 'type'),
            ),
            'refreshCatalog' => $this->catalog()->refresh(
                $this->nullableString($params, 'type'),
                $this->nullableString($params, 'module'),
            ),
            'runE2e' => $this->runService()->startRun(
                TestRun::TYPE_E2E,
                $this->requiredString($params, 'module'),
                array_key_exists('ui_enabled', $params)
                    ? $this->boolParam($params, 'ui_enabled', false)
                    : $this->uiSettings()->isUiEnabled(),
                $this->stringList($params, 'files'),
            ),
            'runUnit' => $this->runService()->startRun(
                $this->unitType($params),
                $this->requiredString($params, 'module'),
                false,
                $this->stringList($params, 'files'),
            ),
            'getRun' => $this->runService()->getRun((int)($params['run_id'] ?? 0)),
            'listRuns' => $this->runService()->listRuns(
                max(1, (int)($params['page'] ?? 1)),
                max(1, min(100, (int)($params['page_size'] ?? 20))),
                $this->nullableString($params, 'module'),
                $this->nullableString($params, 'type'),
            ),
            'getSettings' => [
                'ui_enabled' => $this->uiSettings()->isUiEnabled(),
            ],
            'setUiEnabled' => $this->persistUiEnabled($params),
            default => throw new \InvalidArgumentException(
                (string)__('测试查询器不支持操作：%{1}', [$operation]),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('框架测试管理'),
            'description' => (string)__('收集各模块测试用例，并异步运行 E2E / 单元测试。'),
            'module' => 'Weline_Framework',
            'operations' => [
                $this->op('listModules', (string)__('列出含测试的模块与计数'), 'read', TestController::ACL_CATALOG_VIEW, [
                    ['name' => 'type', 'type' => 'string|null', 'required' => false, 'max_length' => 32, 'description' => (string)__('可选过滤：e2e|unit|integration')],
                ], 1),
                $this->op('listCases', (string)__('列出某模块用例文件'), 'read', TestController::ACL_CATALOG_VIEW, [
                    ['name' => 'module', 'type' => 'string', 'required' => true, 'max_length' => 128, 'description' => (string)__('模块名')],
                    ['name' => 'type', 'type' => 'string|null', 'required' => false, 'max_length' => 32, 'description' => (string)__('可选过滤：e2e|unit|integration')],
                ], 1),
                $this->op('refreshCatalog', (string)__('刷新测试目录缓存'), 'write', TestController::ACL_CATALOG_VIEW, [
                    ['name' => 'type', 'type' => 'string|null', 'required' => false, 'max_length' => 32, 'description' => (string)__('可选过滤类型')],
                    ['name' => 'module', 'type' => 'string|null', 'required' => false, 'max_length' => 128, 'description' => (string)__('可选模块名')],
                ], 2),
                $this->op('runE2e', (string)__('异步运行模块 E2E'), 'write', TestController::ACL_E2E_RUN, [
                    ['name' => 'module', 'type' => 'string', 'required' => true, 'max_length' => 128, 'description' => (string)__('模块名')],
                    ['name' => 'ui_enabled', 'type' => 'bool', 'required' => false, 'description' => (string)__('缺省时读系统配置 test.ui_enabled；true 时 headed UI')],
                    ['name' => 'files', 'type' => 'array|null', 'required' => false, 'description' => (string)__('可选用例文件列表')],
                ], 3),
                $this->op('runUnit', (string)__('异步运行模块单元/集成测试'), 'write', TestController::ACL_UNIT_RUN, [
                    ['name' => 'module', 'type' => 'string', 'required' => true, 'max_length' => 128, 'description' => (string)__('模块名')],
                    ['name' => 'type', 'type' => 'string|null', 'required' => false, 'max_length' => 32, 'description' => (string)__('unit 或 integration，默认 unit')],
                    ['name' => 'files', 'type' => 'array|null', 'required' => false, 'description' => (string)__('可选用例文件列表')],
                ], 3),
                $this->op('getRun', (string)__('读取一次测试运行状态与日志'), 'read', TestController::ACL_HISTORY_VIEW, [
                    ['name' => 'run_id', 'type' => 'int', 'required' => true, 'min' => 1, 'description' => (string)__('运行 ID')],
                ], 1),
                $this->op('listRuns', (string)__('分页列出测试运行历史'), 'read', TestController::ACL_HISTORY_VIEW, [
                    ['name' => 'page', 'type' => 'int', 'required' => false, 'min' => 1, 'description' => (string)__('页码')],
                    ['name' => 'page_size', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 100, 'description' => (string)__('每页条数')],
                    ['name' => 'module', 'type' => 'string|null', 'required' => false, 'max_length' => 128, 'description' => (string)__('模块过滤')],
                    ['name' => 'type', 'type' => 'string|null', 'required' => false, 'max_length' => 32, 'description' => (string)__('类型过滤')],
                ], 1),
                $this->op('getSettings', (string)__('读取测试管理运行偏好'), 'read', TestController::ACL_VIEW, [], 1),
                $this->op('setUiEnabled', (string)__('保存 UI 测试开关到系统配置'), 'write', TestController::ACL_E2E_RUN, [
                    ['name' => 'ui_enabled', 'type' => 'bool', 'required' => true, 'description' => (string)__('是否开启 headed UI 测试')],
                ], 1),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $params
     * @return array<string,mixed>
     */
    private function op(string $name, string $description, string $mode, string $acl, array $params, int $cost): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'frontend' => true,
            'backend' => true,
            'external' => true,
            'auth' => 'backend',
            'backend_acl' => [
                'kind' => 'source',
                'source_id' => $acl,
            ],
            'mode' => $mode,
            'graph' => false,
            'cost' => $cost,
            'params' => $params,
            'returns' => ['type' => 'map'],
        ];
    }

    private function catalog(): TestCatalogService
    {
        return ObjectManager::getInstance(TestCatalogService::class);
    }

    private function runService(): TestRunService
    {
        return ObjectManager::getInstance(TestRunService::class);
    }

    private function uiSettings(): TestUiSettings
    {
        return ObjectManager::getInstance(TestUiSettings::class);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{ui_enabled:bool,saved:bool}
     */
    private function persistUiEnabled(array $params): array
    {
        $enabled = $this->boolParam($params, 'ui_enabled', false);
        $saved = $this->uiSettings()->setUiEnabled($enabled);

        return [
            'ui_enabled' => $enabled,
            'saved' => $saved,
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function requiredString(array $params, string $key): string
    {
        $value = trim((string)($params[$key] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException((string)__('缺少参数：%{1}', [$key]));
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function nullableString(array $params, string $key): ?string
    {
        if (!array_key_exists($key, $params) || $params[$key] === null) {
            return null;
        }
        $value = trim((string)$params[$key]);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function boolParam(array $params, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }
        $value = $params[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<string>
     */
    private function stringList(array $params, string $key): array
    {
        $raw = $params[$key] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function unitType(array $params): string
    {
        $type = strtolower((string)($this->nullableString($params, 'type') ?? TestRun::TYPE_UNIT));
        return $type === TestRun::TYPE_INTEGRATION ? TestRun::TYPE_INTEGRATION : TestRun::TYPE_UNIT;
    }
}
