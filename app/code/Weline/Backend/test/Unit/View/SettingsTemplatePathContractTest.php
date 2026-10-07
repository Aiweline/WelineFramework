<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * 占位 Settings/{Basic,Email,Storage} 已移除；根路径控制器模板仍须与 Controller 路径对齐。
 */
final class SettingsTemplatePathContractTest extends TestCase
{
    public function testPlaceholderSettingsControllersAndTemplatesAreRemoved(): void
    {
        $root = BP . 'app/code/Weline/Backend/';
        foreach (['Basic', 'Email', 'Storage'] as $name) {
            self::assertFileDoesNotExist($root . 'Controller/Settings/' . $name . '.php');
            self::assertFileDoesNotExist($root . 'view/templates/Settings/' . $name . '/index.phtml');
            self::assertFileDoesNotExist($root . 'view/templates/Backend/Settings/' . $name . '/index.phtml');
        }
        self::assertDirectoryDoesNotExist($root . 'Controller/Settings');
        self::assertDirectoryDoesNotExist($root . 'view/templates/Settings');
        self::assertDirectoryDoesNotExist($root . 'view/templates/Backend/Settings');
    }

    /** @return list<string> */
    private static function expectedRelativeTemplates(): array
    {
        return [
            'Maintenance/index.phtml',
            'Backup/index.phtml',
            'Monitor/index.phtml',
            'Statistics/index.phtml',
            'AccessLog/index.phtml',
        ];
    }

    public function testRootControllerTemplatesLiveBesideControllerPath(): void
    {
        $base = BP . 'app/code/Weline/Backend/view/templates/';
        foreach (self::expectedRelativeTemplates() as $relative) {
            $path = $base . $relative;
            self::assertFileExists(
                $path,
                'Missing template for default fetch(): templates/' . \dirname($relative) . '/index'
            );
            $legacy = $base . 'Backend/' . $relative;
            self::assertFileDoesNotExist(
                $legacy,
                'Stale Backend/ prefix would not match Controller root path: ' . $legacy
            );
        }
    }
}
