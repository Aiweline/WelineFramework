<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Presenter\MigrationStatusPresenter;

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

        // __() 可能返回中文源文或已译英文；只锁结构与键，不锁某一语种字面。
        self::assertNotSame('', trim($view['summary']));
        self::assertNotSame('', trim($view['alert']));
        self::assertStringNotContainsString('execution_policy', $view['summary']);

        $byKey = [];
        foreach ($view['rows'] as $row) {
            $byKey[$row['key']] = $row;
        }

        self::assertSame(['mode', 'allowlist_count', 'env_locked', 'execution_policy', 'production_actions_exposed'], array_keys($byKey));
        self::assertSame('0', $byKey['allowlist_count']['value']);
        self::assertNotSame('', trim($byKey['mode']['label']));
        self::assertNotSame('', trim($byKey['mode']['value']));
        self::assertNotSame('', trim($byKey['execution_policy']['value']));
        self::assertStringNotContainsString('registered_postgresql', $byKey['execution_policy']['value']);
        self::assertNotSame('', trim($byKey['env_locked']['value']));
        self::assertNotSame('', trim($byKey['production_actions_exposed']['value']));
    }

    public function testControlCenterMigrationWorkspaceStillAssignsStatusBag(): void
    {
        $controller = (string) file_get_contents(BP . 'app/code/Weline/B2B/Controller/Backend/ControlCenter.php');
        self::assertStringContainsString('function migration()', $controller);
        self::assertStringContainsString("assign('workspace_status'", $controller);

        $path = BP . 'app/code/Weline/B2B/view/templates/backend/ControlCenter/index.phtml';
        $content = (string) file_get_contents($path);
        self::assertStringContainsString("getData('workspace_status')", $content);
    }
}
