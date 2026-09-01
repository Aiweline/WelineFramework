<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 模板内嵌部件删除依赖 template_deleted 墓碑行；scoped projectDraft 不得将其 deactivate。
 */
final class TemplateInlineTombstonePersistenceContractTest extends TestCase
{
    public function testProjectDraftIsNoOpForLayoutAuthority(): void
    {
        $adapter = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/Scoped/ThemeScopedResourceProjector.php'
        );

        self::assertStringContainsString('function projectDraft', $adapter);
        self::assertStringContainsString('theme_scope_workspace only', $adapter);
        self::assertStringNotContainsString('isTemplateInlineTombstoneRow', $adapter);
    }

    public function testScopedSnapshotNormalizerSkipsTemplateDeletedRows(): void
    {
        $normalizer = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/Scoped/ThemeLayoutSnapshotNormalizer.php'
        );

        self::assertStringContainsString('CONFIG_TEMPLATE_DELETED', $normalizer);
        self::assertStringContainsString('continue;', $normalizer);
    }

    public function testInitSlotDefaultsClearsTemplateDeletedTombstones(): void
    {
        $service = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/WidgetDefaultInjectionService.php'
        );
        $layoutService = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/ThemeLayoutService.php'
        );

        self::assertStringContainsString('clearTemplateDeletedTombstonesForSlot', $layoutService);
        self::assertStringContainsString('clearTemplateDeletedTombstonesForSlot', $service);
        $layoutWriter = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Service/Scoped/ThemeScopedLayoutWriteService.php'
        );
        self::assertStringContainsString('clearTemplateDeletedTombstonesForSlot', $layoutWriter);
        self::assertStringContainsString('cleared_template_deleted', $service);
    }
}
