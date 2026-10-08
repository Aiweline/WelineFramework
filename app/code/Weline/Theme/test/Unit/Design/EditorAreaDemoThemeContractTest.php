<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Design;

use PHPUnit\Framework\TestCase;

/**
 * Acceptance fixture: editor-area-demo must ship both frontend/ and backend/
 * so Theme Editor can expose and switch the 后端 area.
 */
final class EditorAreaDemoThemeContractTest extends TestCase
{
    public function testDemoThemeShipsFrontendAndBackendSkins(): void
    {
        $root = dirname(__DIR__, 6) . '/design/Weline/editor-area-demo';
        self::assertDirectoryExists($root, 'editor-area-demo design theme missing');
        self::assertDirectoryExists($root . '/frontend');
        self::assertDirectoryExists($root . '/backend');
        self::assertFileExists($root . '/register.php');
        self::assertFileExists($root . '/frontend/colors/_editor-area-demo.css');
        self::assertFileExists($root . '/backend/colors/_editor-area-demo.css');
        self::assertFileExists($root . '/backend/assets/css/editor-area-demo.css');

        $register = (string)file_get_contents($root . '/register.php');
        self::assertStringContainsString("'name' => 'editor-area-demo'", $register);
        self::assertStringContainsString('Weline_EditorAreaDemo', $register);
    }
}
