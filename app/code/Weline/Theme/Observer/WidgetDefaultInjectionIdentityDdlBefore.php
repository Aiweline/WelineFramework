<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Service\LayoutEntity\ThemeWidgetDefaultInjectionIdentityHealer;

/**
 * theme_widget_default_injection 的 DDL 逐 op 执行前，回填 layout_identity_hash 存量空值。
 *
 * 挂 table_ddl_before：列可能由同轮 ADD_COLUMN 新建；在 ADD_INDEX(UNIQUE) 之前触发时列已就绪。
 */
final class WidgetDefaultInjectionIdentityDdlBefore implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $tableName = \str_replace(['"', '`', '[', ']'], '', (string)$event->getData('table_name'));
        $segments = \explode('.', $tableName);
        $physicalOrLogical = \trim((string)\end($segments));
        if ($physicalOrLogical !== ThemeWidgetDefaultInjection::schema_table
            && !\str_ends_with($physicalOrLogical, ThemeWidgetDefaultInjection::schema_table)
        ) {
            return;
        }
        ObjectManager::getInstance(ThemeWidgetDefaultInjectionIdentityHealer::class)->heal();
    }
}
