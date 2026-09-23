<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Widget\Service\WidgetScanner;

final class WidgetScannerTemplateKeyTest extends TestCase
{
    public function testTemplateKeyIsRetainedWithParameterOverrides(): void
    {
        $key = 'Weline_Theme::theme/frontend/widgets/navigation/all-menu/default.phtml';
        $entry = ['params' => ['menu_tree' => ['type' => 'all_menu_tree']]];
        $method = new ReflectionMethod(WidgetScanner::class, 'normalizeWidgetEntry');
        $actual = $method->invoke(new WidgetScanner(), $entry, $key);
        self::assertSame($key, $actual['template']);
        self::assertSame($entry['params'], $actual['params']);
        self::assertArrayNotHasKey('default_injections', $actual, 'Template annotation remains authoritative unless explicitly overridden.');
    }
    public function testExplicitTemplateAndUnrelatedArrayKeysArePreserved(): void
    {
        $method = new ReflectionMethod(WidgetScanner::class, 'normalizeWidgetEntry');
        $scanner = new WidgetScanner();
        self::assertSame(['template' => 'Weline_Test::other.phtml'], $method->invoke($scanner, ['template' => 'Weline_Test::other.phtml'], 'Weline_Theme::original.phtml'));
        self::assertSame(['params' => []], $method->invoke($scanner, ['params' => []], 'not-a-template'));
    }
}
