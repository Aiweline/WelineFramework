<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/6/23 11:50:30
 */

namespace Weline\UrlManager\Plugin;

use Weline\Framework\App\Env;
use Weline\Framework\Module\ModuleIdentityProviderInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\UrlManager\Model\UrlManager;

class ModuleUpgradeExecuteAfterPlugin
{
    private const ROUTE_BATCH_SIZE = 500;
    private const ROUTE_IMPORT_MEMORY_LIMIT = '512M';

    private $urlManager =  null;
    private ?ModuleIdentityProviderInterface $moduleIdentity = null;
    /** @var array<string, int> */
    private array $moduleIdCache = [];
    function __construct(
        private readonly RuntimeProviderResolver $runtimeProviders,
        UrlManager $urlManager,
    )
    {
        $this->urlManager = $urlManager;
    }

    function afterExecute()
    {
        # 按类型分段处理并及时释放内存，避免升级阶段峰值过高
        $this->syncRoutesFromFile(Env::path_FRONTEND_PC_ROUTER_FILE, 'frontend_pc');
        $this->syncRoutesFromFile(Env::path_FRONTEND_REST_API_ROUTER_FILE, 'frontend_rest');
        $this->syncRoutesFromFile(Env::path_BACKEND_PC_ROUTER_FILE, 'backend_pc');
        $this->syncRoutesFromFile(Env::path_BACKEND_REST_API_ROUTER_FILE, 'backend_rest');
    }

    private function syncRoutesFromFile(string $filePath, string $type): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $previousMemoryLimit = null;
        if ($this->needRaiseMemoryLimit(self::ROUTE_IMPORT_MEMORY_LIMIT)) {
            $previousMemoryLimit = (string)ini_get('memory_limit');
            @ini_set('memory_limit', self::ROUTE_IMPORT_MEMORY_LIMIT);
        }

        $urls = include $filePath;
        if ($previousMemoryLimit !== null) {
            @ini_set('memory_limit', $previousMemoryLimit);
        }
        if (!is_array($urls)) {
            unset($urls);
            gc_collect_cycles();
            return;
        }

        $this->preloadModuleIdsFromRoutes($urls);
        $batchRows = [];
        foreach ($urls as $path => $urlConfig) {
            if (!is_array($urlConfig)) {
                continue;
            }
            $moduleName = (string)($urlConfig['module'] ?? '');
            if ($moduleName === '') {
                continue;
            }
            $module_id = $this->getModuleIdByName($moduleName);
            if (!$module_id) {
                continue;
            }

            $batchRows[] = [
                UrlManager::schema_fields_MODULE_ID => $module_id,
                UrlManager::schema_fields_PATH => $path,
                UrlManager::schema_fields_IDENTIFY => md5($path . $type),
                UrlManager::schema_fields_DATA => json_encode($urlConfig),
                UrlManager::schema_fields_TYPE => $type,
                UrlManager::schema_fields_IS_DELETE => 0,
            ];

            if (count($batchRows) >= self::ROUTE_BATCH_SIZE) {
                $this->flushBatchRows($batchRows);
                $batchRows = [];
            }
        }

        $this->flushBatchRows($batchRows);

        unset($urls, $batchRows);
        gc_collect_cycles();
    }

    private function getModuleIdByName(string $moduleName): int
    {
        if (isset($this->moduleIdCache[$moduleName])) {
            return $this->moduleIdCache[$moduleName];
        }

        $moduleId = (int)($this->moduleIdentity()->idsByNames([$moduleName])[$moduleName] ?? 0);
        $this->moduleIdCache[$moduleName] = $moduleId;
        return $moduleId;
    }

    /**
     * @param array<string, mixed> $urls
     */
    private function preloadModuleIdsFromRoutes(array $urls): void
    {
        $moduleNames = [];
        foreach ($urls as $urlConfig) {
            if (!is_array($urlConfig)) {
                continue;
            }
            $moduleName = (string)($urlConfig['module'] ?? '');
            if ($moduleName === '' || isset($this->moduleIdCache[$moduleName])) {
                continue;
            }
            $moduleNames[$moduleName] = $moduleName;
        }
        if ($moduleNames === []) {
            return;
        }

        $this->moduleIdCache += $this->moduleIdentity()->idsByNames(\array_values($moduleNames));
    }

    private function moduleIdentity(): ModuleIdentityProviderInterface
    {
        if ($this->moduleIdentity instanceof ModuleIdentityProviderInterface) {
            return $this->moduleIdentity;
        }
        $provider = $this->runtimeProviders->resolve(ModuleIdentityProviderInterface::class);
        if (!$provider instanceof ModuleIdentityProviderInterface) {
            throw new \RuntimeException('Module identity provider is unavailable.');
        }
        return $this->moduleIdentity = $provider;
    }

    /**
     * @param array<int, array<string, mixed>> $batchRows
     */
    private function flushBatchRows(array $batchRows): void
    {
        if ($batchRows === []) {
            return;
        }

        // 同一批次按 identify 去重，后出现的路由覆盖前者，避免批内重复键冲突
        $deduplicatedRows = [];
        foreach ($batchRows as $row) {
            $identify = (string)($row[UrlManager::schema_fields_IDENTIFY] ?? '');
            if ($identify === '') {
                continue;
            }
            $deduplicatedRows[$identify] = $row;
        }
        if ($deduplicatedRows === []) {
            return;
        }

        // 路由同步必须是单语句原子 upsert：“先删后插”在并发升级时存在唯一键竞态。
        // 临时清空自增主键 identity，让三种方言编译器生成真正的批量
        // INSERT ... ON CONFLICT/ON DUPLICATE KEY，冲突依据仍是 identify 唯一索引。
        $query = $this->urlManager->recovery()->getQuery();
        $query->identity('')
            ->insert(array_values($deduplicatedRows), UrlManager::schema_fields_IDENTIFY)
            ->fetch();
    }

    private function needRaiseMemoryLimit(string $targetLimit): bool
    {
        $currentLimit = $this->memoryLimitToBytes((string)ini_get('memory_limit'));
        $targetLimitBytes = $this->memoryLimitToBytes($targetLimit);
        if ($targetLimitBytes <= 0) {
            return false;
        }
        if ($currentLimit < 0) {
            return false;
        }
        return $currentLimit > 0 && $currentLimit < $targetLimitBytes;
    }

    private function memoryLimitToBytes(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        if ($memoryLimit === '' || $memoryLimit === '-1') {
            return -1;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int)$memoryLimit;
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
