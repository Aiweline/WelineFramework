<?php

declare(strict_types=1);

namespace Weline\Help\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Help\Controller\Router;

final class HelpRouterTest extends TestCase
{
    public function testRoutesHelpHubAndArticle(): void
    {
        $path = 'help';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('help/frontend', $path);
        self::assertSame('Weline_Help', $rule['module']);

        $path = 'faq';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('help/frontend', $path);

        $path = 'help/shipping-faq';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('help/frontend/view', $path);
        self::assertSame('Weline_Help', $rule['module']);
    }
}
