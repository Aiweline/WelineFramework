<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PaymentBrowserReturnLandingOrchestrator;
use Weline\Payment\Service\PaymentProviderControllerDispatcher;
use Weline\Payment\Service\PaymentProviderControllerRouteScanner;

final class PaymentProviderControllerRouteScannerContractTest extends TestCase
{
    public function testForbiddenControllerMethodsAreBlocked(): void
    {
        self::assertContains('controllerReturn', PaymentProviderControllerRouteScanner::FORBIDDEN_CONTROLLER_METHODS);
        self::assertContains('controllerCancel', PaymentProviderControllerRouteScanner::FORBIDDEN_CONTROLLER_METHODS);
        self::assertContains('controllerNotify', PaymentProviderControllerRouteScanner::FORBIDDEN_CONTROLLER_METHODS);
    }

    public function testScanDoesNotRegisterForbiddenRoutes(): void
    {
        $scanner = new PaymentProviderControllerRouteScanner();
        foreach ($scanner->scan(true) as $route) {
            self::assertNotContains($route['action'], PaymentProviderControllerDispatcher::FORBIDDEN_ACTIONS);
        }
    }
}
