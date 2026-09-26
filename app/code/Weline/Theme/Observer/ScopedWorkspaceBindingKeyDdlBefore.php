<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Service\Scoped\ThemeScopeWorkspaceBindingKeyHealer;

/**
 * theme_scope_workspace 的 DDL 逐 op 执行前，回填 binding_identity_key 存量空值。
 *
 * 挂 table_ddl_before 而非 before_schema_diff_commit：binding_identity_key 是新增列，
 * 一次性事件早于 ADD_COLUMN，回填时列还不存在；本事件按 KIND_PRIORITY 在
 * ADD_COLUMN(1) 之后、ADD_INDEX(5) 之前触发，列已就绪。
 */
final class ScopedWorkspaceBindingKeyDdlBefore implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        // 事件里的表名是 SchemaDiff 落库形态，可能带引号、schema 与部署前缀，
        // 例如 "public"."w_theme_scope_workspace"；按末段 + 逻辑表名后缀匹配。
        $tableName = \str_replace(['"', '`', '[', ']'], '', (string)$event->getData('table_name'));
        $segments = \explode('.', $tableName);
        $physicalOrLogical = \trim((string)\end($segments));
        if ($physicalOrLogical !== ThemeScopeWorkspace::schema_table
            && !\str_ends_with($physicalOrLogical, ThemeScopeWorkspace::schema_table)
        ) {
            return;
        }
        ObjectManager::getInstance(ThemeScopeWorkspaceBindingKeyHealer::class)->heal();
    }
}
