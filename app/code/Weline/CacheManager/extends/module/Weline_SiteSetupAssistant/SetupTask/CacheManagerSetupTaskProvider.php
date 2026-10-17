<?php

declare(strict_types=1);

namespace Weline\CacheManager\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Http\Fpc\FpcMode;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;

/**
 * 性能优化提示：FPC 主开关与开发穿透（安装级，非按站配置）。
 *
 * - FPC 关闭 → 提示开启（开启后不提示）
 * - 开发穿透开启 → 提示关闭以恢复缓存命中（关闭后不提示）
 */
class CacheManagerSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $href = $this->backendPath('admin/system/full-page-cache');
        $fpcOn = FpcMode::isFullPageCacheEnabled();
        $pierceOn = FpcMode::isDevAutoPierceEnabled();

        return $this->tasks([
            [
                'code' => 'fpc_full_page_cache',
                'sort' => 10,
                'group' => self::GROUP_PERFORMANCE,
                'category' => (string)__('性能优化'),
                'module' => 'Weline_CacheManager',
                'title' => (string)__('开启全页缓存 (FPC)'),
                'tip' => $fpcOn
                    ? (string)__('全页缓存已开启。')
                    : (string)__('全页缓存当前关闭，店面无法命中共享 FPC，请到本页开启以恢复性能。'),
                'status' => $fpcOn ? 'done' : 'todo',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => [
                    'scope_kind' => 'install',
                    'fpc_enabled' => $fpcOn,
                ],
            ],
            [
                'code' => 'fpc_dev_auto_pierce',
                'sort' => 20,
                'group' => self::GROUP_PERFORMANCE,
                'category' => (string)__('性能优化'),
                'module' => 'Weline_CacheManager',
                'title' => (string)__('关闭开发穿透 · 链接自带签章'),
                'tip' => $pierceOn
                    ? (string)__('开发穿透已开启，生成的链接会打穿 FPC；验收结束后请关闭以恢复性能。')
                    : (string)__('开发穿透已关闭，全页缓存可正常命中。'),
                'status' => $pierceOn ? 'todo' : 'done',
                'href' => $href,
                'scenarios' => ['new', 'migrate'],
                'meta' => [
                    'scope_kind' => 'install',
                    'auto_pierce_enabled' => $pierceOn,
                ],
            ],
        ]);
    }
}
