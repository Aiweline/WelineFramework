<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Shipping\Controller\Router;

final class GuidePublicRouterContractTest extends TestCase
{
    public function testGuideShippingAliasMapsToFrontendController(): void
    {
        $path = 'guide/shipping';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('shipping/frontend/guide/shipping', $path);
        self::assertSame('Weline_Shipping', $rule['module'] ?? null);
    }

    public function testGuideReturnsAliasMapsToFrontendController(): void
    {
        $path = 'guide/returns';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('shipping/frontend/guide/returns', $path);
        self::assertSame('Weline_Shipping', $rule['module'] ?? null);
    }

    public function testIgnoresAlreadyOwnedRules(): void
    {
        $path = 'guide/shipping';
        $rule = ['module' => 'Weline_Other'];
        Router::process($path, $rule);
        self::assertSame('guide/shipping', $path);
        self::assertSame('Weline_Other', $rule['module']);
    }
}
