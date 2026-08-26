<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

final class WidgetDefaultInjectionAreaContractTest extends TestCase
{
    public function testLayoutRegionsAreNotFrontendBackend(): void
    {
        self::assertFalse(\defined(ThemeLayout::class . '::AREA_BACKEND'));
        self::assertFalse(\defined(ThemeLayout::class . '::AREA_FRONTEND'));
        self::assertSame('content', ThemeLayout::AREA_CONTENT);
        self::assertSame('header', ThemeLayout::AREA_HEADER);
    }

    public function testInjectionFillUsesCallerComponentAreaNotMissingLayoutConstant(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WidgetDefaultInjectionService.php'
        );
        self::assertStringNotContainsString('ThemeLayout::AREA_BACKEND', $source);

        $method = new ReflectionMethod(WidgetDefaultInjectionService::class, 'injectionTargetNeedsFill');
        $params = [];
        foreach ($method->getParameters() as $parameter) {
            $params[$parameter->getName()] = $parameter;
        }
        self::assertArrayHasKey('componentArea', $params);
        self::assertSame(
            PreviewContextService::AREA_FRONTEND,
            $params['componentArea']->getDefaultValue()
        );
    }
}
