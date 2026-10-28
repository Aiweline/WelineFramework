<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Compilation\CompiledPhpRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Contract\StorefrontRewriteLookupInterface;

/**
 * M4 · 店面关键路径可解析性自检（只观测、不否决）：
 * route_update 提交后，对声明过 router 前缀的启用模块抽样其代表性短路径
 * （index / <router前缀>/index），断言「静态路由表命中 ∨ rewrite 契约可解析且目标命中」；
 * 两头都落空 = 该模块路由产物缺失或整表被吞 → 记 setup/route_integrity.log。
 *
 * 与 M1/M3 的分工：M1/M3 在 commit 前失败关闭防「写坏表」；本自检防的是其余
 * 「表好但解析链断」（如 rewrite 数据丢失、快照未生成）一类，属于症状级监测，
 * 因此只告警不抛错——禁止用观测项冒充门禁（§5.1）。
 */
final class StorefrontRouteSelfCheck
{
    private const MAX_MODULES = 60;
    private const LOG_FILE = 'setup/route_integrity.log';

    /**
     * @param array<string, mixed> $modules Handle::getModules() 结果
     * @return array{checked:int, unresolved:list<string>, lookup_available:bool}
     */
    public function check(array $modules): array
    {
        $frontendRouters = $this->loadFrontendPcRouters();
        $lookup = $this->resolveRewriteLookup();

        $checked = 0;
        $unresolved = [];
        foreach ($modules as $moduleName => $module) {
            if (!is_array($module)) {
                continue;
            }
            if ((int)($module['status'] ?? 1) !== 1) {
                continue;
            }
            $routerPrefix = trim((string)($module['router'] ?? ''), '/');
            if ($routerPrefix === '') {
                continue;
            }
            $basePath = rtrim((string)($module['base_path'] ?? ''), DIRECTORY_SEPARATOR);
            if ($basePath === '' || !is_dir($basePath . DIRECTORY_SEPARATOR . \Weline\Framework\Module\Handle::pc_DIR)) {
                continue;
            }
            if (++$checked > self::MAX_MODULES) {
                break;
            }
            // 代表路径：模块根 index + router 前缀页 index（店面短路径只有这两类形态）。
            foreach (['index/index', $routerPrefix . '/index'] as $candidate) {
                if ($this->resolvable($candidate, $frontendRouters, $lookup)) {
                    continue;
                }
                $unresolved[] = $moduleName . ':' . $candidate;
                w_log_warning(__(
                    '店面路径自检未通过：%1（静态路由表与 rewrite 契约均无法解析；请检查 generated/routers/frontend_pc.php 与 w_url_rewrite）',
                    [$moduleName . '/' . $candidate]
                ), [], self::LOG_FILE);
            }
        }

        return [
            'checked' => $checked,
            'unresolved' => $unresolved,
            'lookup_available' => $lookup !== null,
        ];
    }

    /**
     * @param array<string, mixed> $frontendRouters
     */
    private function resolvable(string $path, array $frontendRouters, ?StorefrontRewriteLookupInterface $lookup): bool
    {
        $normalized = trim($path, '/');
        if (isset($frontendRouters[$normalized]) || isset($frontendRouters[$normalized . '::GET'])) {
            return true;
        }
        if ($lookup === null) {
            // rewrite 提供方缺席时按「仅静态表」口径：查不到即视为未解析，由调用方记日志。
            return false;
        }
        try {
            if (!$lookup->hasRewrite($normalized)) {
                return false;
            }
            $target = trim((string)$lookup->resolveTargetPath($normalized), '/');
            if ($target === '') {
                return false;
            }
            // 目标可能带查询串（catalog/product/view/id/123）；路由表键是纯路径段。
            $targetPathOnly = explode('?', $target)[0];
            return isset($frontendRouters[$targetPathOnly])
                || isset($frontendRouters[$targetPathOnly . '::GET']);
        } catch (\Throwable) {
            // 观测链路自身故障不得升级为收集失败——降级为「静态表未命中」结论并留痕。
            w_log_warning(__('店面路径自检：rewrite 契约查询异常（降级为静态表口径）：%1', ['']), [], self::LOG_FILE);
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFrontendPcRouters(): array
    {
        $path = Env::path_FRONTEND_PC_ROUTER_FILE;
        if (!is_file($path)) {
            return [];
        }
        try {
            $routers = CompiledPhpRegistry::load($path);
            return is_array($routers) ? $routers : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveRewriteLookup(): ?StorefrontRewriteLookupInterface
    {
        try {
            $lookup = ObjectManager::getInstance(StorefrontRewriteLookupInterface::class);
            return $lookup instanceof StorefrontRewriteLookupInterface ? $lookup : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
