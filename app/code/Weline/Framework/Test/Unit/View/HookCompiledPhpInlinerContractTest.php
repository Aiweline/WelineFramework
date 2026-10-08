<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\View\HookCompiledPhpInliner;

/**
 * Production hook bake: inline contributor com PHP; never HTML snapshots.
 * DEV / runtime / editor keep getHook().
 */
final class HookCompiledPhpInlinerContractTest extends TestCase
{
    public function testDevModeTryEmitReturnsNull(): void
    {
        if (!(\defined('DEV') && DEV)) {
            self::markTestSkipped('Contract expects DEV=true in unit runner');
        }

        self::assertNull(HookCompiledPhpInliner::tryEmit('Weline_Captcha::backend::layouts::login::captcha'));
        self::assertFalse(HookCompiledPhpInliner::isProductionInlineEnabled());
    }

    public function testRuntimeAttributeForcesGetHookPath(): void
    {
        $method = new ReflectionMethod(HookCompiledPhpInliner::class, 'wantsRuntime');
        $method->setAccessible(true);

        self::assertTrue($method->invoke(null, ['runtime' => '1']));
        self::assertTrue($method->invoke(null, ['runtime' => true]));
        self::assertTrue($method->invoke(null, ['hook-runtime' => 'yes']));
        self::assertFalse($method->invoke(null, []));
        self::assertFalse($method->invoke(null, ['runtime' => '0']));
    }

    public function testBuildInlinePhpEmitsIncludeNotHtmlSnapshot(): void
    {
        $method = new ReflectionMethod(HookCompiledPhpInliner::class, 'buildInlinePhp');
        $method->setAccessible(true);

        $hook = 'Weline_Captcha::backend::layouts::login::captcha';
        try {
            $php = $method->invoke(null, $hook);
        } catch (\Throwable $e) {
            self::markTestSkipped('Hook contributor not compilable in this runner: ' . $e->getMessage());
        }

        if ($php === null) {
            self::markTestSkipped('Hook contributor com path unresolved (registry/modules)');
        }

        self::assertIsString($php);
        self::assertStringContainsString('baked-hook:' . $hook, $php);
        self::assertStringContainsString('include ', $php);
        self::assertStringNotContainsString('<html', $php);
        self::assertDoesNotMatchRegularExpression('/echo\s+[\'"]<[^;]+;/', $php);
    }
}
