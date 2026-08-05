<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class ScopeRateLimitPipelineTest extends TestCase
{
    public function testRateLimitRunsAfterFullScopeAndBeforeFpcOnlyOnce(): void
    {
        $moduleRoot = \dirname(__DIR__, 3);
        $app = (string)\file_get_contents($moduleRoot . '/App.php');
        $events = (string)\file_get_contents($moduleRoot . '/etc/event.xml');

        $scopeInstall = \strpos($app, '$this->installStorefrontNavigationScope(');
        $scopeGate = \strpos($app, "'Weline_Framework::App::storefront_scope_ready_gate'");
        $fpcLookup = \strpos($app, '$this->tryPersistentFpcFastPath()');

        self::assertIsInt($scopeInstall);
        self::assertIsInt($scopeGate);
        self::assertIsInt($fpcLookup);
        self::assertLessThan($scopeGate, $scopeInstall);
        self::assertLessThan($fpcLookup, $scopeGate);

        self::assertSame(1, \preg_match(
            '/<event name="Weline_Framework::App::storefront_scope_ready_gate">.*?'
            . 'Weline\\\\Framework\\\\Http\\\\Observer\\\\ScopeRateLimitObserver.*?<\\/event>/s',
            $events,
        ));
        self::assertSame(1, \preg_match(
            '/<event name="Weline_Framework_Router::before_start">(?P<body>.*?)<\\/event>/s',
            $events,
            $routerEvent,
        ));
        self::assertStringNotContainsString('ScopeRateLimitObserver', $routerEvent['body']);
    }
}
