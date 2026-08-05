<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\MediaManager\Service\ConnectorService;

final class ConnectorServiceParameterSourceTest extends TestCase
{
    public function testFiberLocalCanonicalParamsWinOverProcessSuperglobals(): void
    {
        $previousGet = $_GET;
        $previousPost = $_POST;
        $_GET = ['cmd' => 'open', 'target' => 'mm_Lw'];
        $_POST = [];

        try {
            $request = new Request();
            $request->setData('params', [
                'cmd' => 'open',
                'target' => 'mm_YWktZ2VuZXJhdGVk',
                'tree' => '1',
            ]);

            $method = new \ReflectionMethod(ConnectorService::class, 'parseSource');
            $source = $method->invoke(new ConnectorService(), $request);

            self::assertSame('open', $source['cmd']);
            self::assertSame('mm_YWktZ2VuZXJhdGVk', $source['target']);
            self::assertSame('1', $source['tree']);
        } finally {
            $_GET = $previousGet;
            $_POST = $previousPost;
        }
    }
}
