<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;

final class ControllerFetchFileBeforeBackendDetectionTest extends TestCase
{
    public function testBackendControllerRouteKeepsBackendDetectionWhenContextIsDirty(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getServer')
            ->willReturnCallback(static function (string $key) {
                return match ($key) {
                    'WELINE_IS_BACKEND', 'WELINE_AREA' => '',
                    'REQUEST_URI' => '/pagebuilder/backend/ai-site-agent/index',
                    default => '',
                };
            });
        $request->method('getRouterData')
            ->willReturnCallback(static function (string $key) {
                return match ($key) {
                    'class/controller_name' => 'Backend/AiSiteAgent',
                    'class/name' => 'GuoLaiRen\\PageBuilder\\Controller\\Backend\\AiSiteAgent',
                    default => '',
                };
            });
        $request->method('isBackend')->willReturn(false);

        $observer = new ControllerFetchFileBefore(
            $this->createMock(WelineTheme::class),
            $this->createMock(ThemeContextService::class),
            new ThemePageTypeResolver(),
        );

        $method = new \ReflectionMethod($observer, 'isBackendRequest');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($observer, $request, null));
    }
}
