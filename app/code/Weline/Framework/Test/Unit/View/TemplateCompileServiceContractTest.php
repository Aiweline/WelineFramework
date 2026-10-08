<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\TemplateCompileService;

final class TemplateCompileServiceContractTest extends TestCase
{
    public function testServiceExposesPinnedCompileWithCallerLocalesAndProgress(): void
    {
        $path = dirname(__DIR__, 3) . '/View/TemplateCompileService.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString('final class TemplateCompileService', $source);
        self::assertStringContainsString('compilePinnedSources', $source);
        self::assertStringContainsString('compileFile', $source);
        self::assertStringContainsString('onProgress', $source);
        self::assertStringNotContainsString('WebsiteLanguage', $source);
        self::assertStringNotContainsString('Weline\\Websites', $source);
    }
}
