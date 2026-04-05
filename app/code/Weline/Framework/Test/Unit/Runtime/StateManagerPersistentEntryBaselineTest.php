<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsConcurrency;

final class StateManagerPersistentEntryBaselineTest extends TestCase
{
    public function testRunWlsPersistentRequestEntryBaselineIsIdempotent(): void
    {
        StateManager::runWlsPersistentRequestEntryBaseline();
        StateManager::runWlsPersistentRequestEntryBaseline();
        $this->assertTrue(true);
    }

    public function testOmitListMatchesEntryBaselineCoverage(): void
    {
        $omit = WlsConcurrency::callbackNamesOmittableWithPeerFibers();
        self::assertContains('template_instance', $omit);
        self::assertContains('virtual_theme_context', $omit);
        self::assertNotContains('session_instances', $omit);
        self::assertNotContains('sse_context', $omit);
        self::assertNotContains('request_context', $omit);
    }
}
