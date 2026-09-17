<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Controller\Router;

final class LegacyFrontNameRouterContractTest extends TestCase
{
    public function testRewritesLegacyWelineSmtpFrontName(): void
    {
        $path = 'weline_smtp/backend/template/edit';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('smtp/backend/template/edit', $path);
        self::assertArrayNotHasKey('module', $rule);
    }

    public function testIgnoresAlreadyMatchedRules(): void
    {
        $path = 'weline_smtp/backend/template/edit';
        $rule = ['module' => 'Other'];
        Router::process($path, $rule);
        self::assertSame('weline_smtp/backend/template/edit', $path);
    }

    public function testLeavesCanonicalSmtpUntouched(): void
    {
        $path = 'smtp/backend/template/edit';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('smtp/backend/template/edit', $path);
    }
}
