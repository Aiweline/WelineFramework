<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\WidgetLibraryTabResolver;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class WidgetLibraryTabResolverTest extends TestCase
{
    public function testResolvesBasicThemeComponentByCodePrefix(): void
    {
        self::assertSame('basic', WidgetLibraryTabResolver::resolve([
            'module' => 'Weline_Theme',
            'type' => 'theme_component',
            'code' => 'basic/hero',
        ]));
    }

    public function testResolvesGeneralForModuleWidget(): void
    {
        self::assertSame('general', WidgetLibraryTabResolver::resolve([
            'module' => 'Weline_Product',
            'type' => 'content',
            'code' => 'featured-products',
        ]));
    }

    public function testDetectsAiGeneratedOnNestedWidget(): void
    {
        self::assertTrue(WidgetLibraryTabResolver::isAiGenerated([
            'code' => 'content/ai-banner',
            'widget' => ['is_ai_generated' => true],
        ]));
        self::assertFalse(WidgetLibraryTabResolver::isAiGenerated([
            'code' => 'content/plain',
            'is_ai_generated' => false,
        ]));
    }
}
