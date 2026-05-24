<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload\Provider;

use Weline\Framework\Event\EventRegistryInterface;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadProviderInterface;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;

final class EventDescriptorPreloadProvider implements WorkerPreloadProviderInterface
{
    public function code(): string
    {
        return 'event_descriptors';
    }

    public function phase(): string
    {
        return WorkerPreloadContext::PHASE_BOOTSTRAP;
    }

    public function priority(): int
    {
        return 40;
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return true;
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        /** @var EventRegistryInterface $eventRegistry */
        $eventRegistry = ObjectManager::getInstance(EventRegistryInterface::class);
        $registry = $eventRegistry->getRegistry(false);
        $events = \is_array($registry['events'] ?? null) ? $registry['events'] : [];
        $dynamicPatterns = \is_array($registry['dynamic_patterns'] ?? null) ? $registry['dynamic_patterns'] : [];

        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $observerCount = 0;
        foreach ($this->hotEvents() as $eventName) {
            $observerCount += \count($eventsManager->getEventObservers($eventName));
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $context->phase(),
            \count($events) + $observerCount,
            \round((\microtime(true) - $start) * 1000, 2),
            \memory_get_usage(true) - $memoryStart,
            [
                'events' => \count($events),
                'dynamic_patterns' => \count($dynamicPatterns),
                'hot_observers' => $observerCount,
            ]
        );
    }

    public function invalidationKeys(): array
    {
        return ['generated/events.php'];
    }

    /**
     * @return list<string>
     */
    private function hotEvents(): array
    {
        return [
            'Weline_Framework_Runtime::worker_bootstrap_after',
            'Weline_Framework::App::run_before',
            'Weline_Framework::App::run_after',
            'Weline_Framework::App::url_parsed_after',
            'Weline_Framework_Router::before_start',
            'Weline_Framework_Router::process_uri_before',
            'Weline_Framework_Router::route_before',
            'Weline_Framework_Router::route_after',
            'Weline_Framework_View::fetch_file',
            'Weline_Framework_Template::after_render',
            'Weline_Framework_FrontendController::init_before',
            'Weline_Framework_FrontendController::init_after',
            'Weline_Framework_Query::before_execute',
            'Weline_Framework_Query::after_execute',
        ];
    }
}
