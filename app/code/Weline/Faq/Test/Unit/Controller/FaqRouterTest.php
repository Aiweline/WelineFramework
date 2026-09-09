<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Faq\Controller\Router;

final class FaqRouterTest extends TestCase
{
    public function testRoutesHelpHubAndArticle(): void
    {
        $path = 'faq';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('faq/frontend', $path);
        self::assertSame('Weline_Faq', $rule['module']);

        $path = 'faq';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('faq/frontend', $path);

        $path = 'faq/shipping-faq';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('faq/frontend/view', $path);
        self::assertSame('Weline_Faq', $rule['module']);
    }
}
