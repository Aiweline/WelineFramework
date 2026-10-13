<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Frontend slot meta merge must share path-first locale with WidgetI18n;
 * backend must not use storefront URL path locale.
 */
final class SlotRendererRenderLocaleContractTest extends TestCase
{
    public function testResolveRenderLocaleUsesWidgetI18nOnFrontendAndRcOnBackend(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/SlotRendererService.php',
        );

        self::assertStringContainsString('use Weline\\Theme\\Helper\\WidgetI18n;', $src);
        self::assertStringContainsString('WidgetI18n::storefrontLocale()', $src);
        self::assertStringContainsString('private function resolveRenderLocale(?string $renderArea = null)', $src);
        self::assertStringContainsString("\$locale = \$this->resolveRenderLocale(\$widgetArea);", $src);
        self::assertStringContainsString("\$locale = \$this->resolveRenderLocale(\$area);", $src);
        self::assertMatchesRegularExpression(
            '/if \(\$area !== \'backend\'\)[\s\S]{0,400}WidgetI18n::storefrontLocale\(\)/',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/area !== \'backend\'[\s\S]+RequestContext::locale\(\)/',
            $src,
        );
    }
}
