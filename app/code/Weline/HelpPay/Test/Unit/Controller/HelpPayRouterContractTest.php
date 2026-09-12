<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\HelpPay\Controller\Router;

final class HelpPayRouterContractTest extends TestCase
{
    public function testRoutesHAndSAndQ(): void
    {
        $token = str_repeat('a', 24);

        $path = 'h/' . $token;
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('Weline_HelpPay', $rule['module'] ?? null);
        self::assertSame('weline_helppay/frontend/payer', $path);

        $path = 's/' . $token;
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('weline_helppay/frontend/selectionShare', $path);

        $path = 'q/' . $token;
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('weline_helppay/frontend/quickPay', $path);
    }

    public function testIgnoresWhenModuleAlreadySet(): void
    {
        $path = 'h/' . str_repeat('b', 24);
        $rule = ['module' => 'Other'];
        Router::process($path, $rule);
        self::assertSame('Other', $rule['module']);
        self::assertStringStartsWith('h/', $path);
    }
}
