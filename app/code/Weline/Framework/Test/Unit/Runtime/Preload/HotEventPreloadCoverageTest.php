<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime\Preload;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Preload\Provider\CriticalRuntimeClassPreloadProvider;

/**
 * Regression gate for the framework's hot-event warm-up.
 *
 * A provider that claims to warm "the events every request dispatches" is only
 * useful while that list actually covers the dispatch points on the request
 * path — and only correct while it resolves observers from the registry instead
 * of naming module classes (the framework must not build-time depend on
 * Weline_Websites / Weline_Theme / Weline_SystemConfig).
 *
 * Source assertions mirror {@see \Weline\Framework\Test\Unit\Architecture\ColdStartArchitectureContractTest}:
 * a regression fails at test time instead of showing up as first-request lag.
 */
final class HotEventPreloadCoverageTest extends TestCase
{
    /**
     * Events dispatched on every request (or every template fetch / FPC hit)
     * that are otherwise first-paid inside the arriving request.
     */
    private const REQUIRED_HOT_EVENTS = [
        'Weline_Framework::App::pre_route_gate',
        'Weline_Framework_View::resolve_theme_cache_suffix',
        'Weline_Framework_Fpc::cache_hit_response',
        'Weline_Framework_Http::response_ready',
    ];

    public function testPerRequestEventsAreInTheHotList(): void
    {
        $hotEvents = $this->hotEvents();

        foreach (self::REQUIRED_HOT_EVENTS as $eventName) {
            self::assertContains(
                $eventName,
                $hotEvents,
                $eventName . ' is dispatched on the request path and must be warmed at worker boot',
            );
        }
    }

    public function testHotListHasNoDuplicates(): void
    {
        $hotEvents = $this->hotEvents();

        self::assertSame(
            \array_values(\array_unique($hotEvents)),
            $hotEvents,
            'A duplicated hot event only pays the warm-up twice',
        );
    }

    public function testFrameworkDoesNotNameModuleObserverClasses(): void
    {
        $source = (string)\file_get_contents(
            __DIR__ . '/../../../../Runtime/Preload/Provider/CriticalRuntimeClassPreloadProvider.php'
        );

        foreach (['Weline\\Websites\\Observer', 'Weline\\SystemConfig\\Observer', 'Weline\\Websites\\Model'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $source,
                'Framework warm-up must resolve observers from the event registry, not name module classes',
            );
        }

        self::assertStringContainsString(
            'getEventObservers',
            $source,
            'Observer warm-up must go through the event registry',
        );
    }

    /** @return list<string> */
    private function hotEvents(): array
    {
        /** @var list<string> $events */
        $events = (new \ReflectionClassConstant(
            CriticalRuntimeClassPreloadProvider::class,
            'HOT_EVENTS',
        ))->getValue();

        return \array_values($events);
    }
}
