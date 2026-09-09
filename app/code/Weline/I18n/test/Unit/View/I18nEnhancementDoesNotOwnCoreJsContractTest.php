<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Weline_I18n 仅增强：不登记 / 不持有核心 i18n.js（归属 Framework）。
 */
final class I18nEnhancementDoesNotOwnCoreJsContractTest extends TestCase
{
    public function testNoCoreI18nModulesRegistrationInI18nModule(): void
    {
        $modules = dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js';
        $script = dirname(__DIR__, 3) . '/view/statics/js/i18n.js';
        self::assertFileDoesNotExist($modules);
        self::assertFileDoesNotExist($script);
    }

    public function testLanguageSwitcherEnhancementScriptRemains(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/language-switcher.js';
        self::assertFileExists($path);
    }
}
