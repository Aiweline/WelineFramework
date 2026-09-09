<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\B2B\View\Backend\MigrationStatusPresenter;

final class MigrationStatusPresenterTest extends TestCase
{
    public function testOffModePresentsChineseLabelsWithoutRawKeysAsPrimaryValue(): void
    {
        $view = MigrationStatusPresenter::present([
            'mode' => 'off',
            'allowlist_count' => 0,
            'env_locked' => false,
            'execution_policy' => 'registered_postgresql_full_clone_cli_only',
            'production_actions_exposed' => false,
        ]);

        self::assertStringContainsString('未开启', $view['summary']);
        self::assertStringContainsString('命令行', $view['alert']);
        self::assertStringNotContainsString('execution_policy', $view['summary']);

        $byKey = [];
        foreach ($view['rows'] as $row) {
            $byKey[$row['key']] = $row;
        }

        self::assertSame('灰度模式', $byKey['mode']['label']);
        self::assertSame('关闭（未启用）', $byKey['mode']['value']);
        self::assertSame('白名单站点数量', $byKey['allowlist_count']['label']);
        self::assertSame('0', $byKey['allowlist_count']['value']);
        self::assertSame('否', $byKey['env_locked']['value']);
        self::assertSame('生产迁移执行方式', $byKey['execution_policy']['label']);
        self::assertStringContainsString('命令行', $byKey['execution_policy']['value']);
        self::assertStringNotContainsString('registered_postgresql', $byKey['execution_policy']['value']);
        self::assertSame('否', $byKey['production_actions_exposed']['value']);
    }

    public function testTemplateUsesStatusViewMarkers(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml';
        $content = (string) file_get_contents($path);
        self::assertStringContainsString('workspace_status_view', $content);
        self::assertStringContainsString('data-testid="b2b-migration-status-summary"', $content);
        self::assertStringContainsString('data-testid="b2b-migration-status-rows"', $content);
    }
}
