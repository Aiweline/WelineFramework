<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Router\Service\ControllerAnnotationRulesCollector;

final class ControllerAnnotationRulesCollectorTest extends TestCase
{
    public function testParseAttackDocAndDispatchEvent(): void
    {
        $events = $this->createMock(EventsManager::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(
                ControllerAnnotationRulesCollector::EVENT,
                self::callback(static function (array $payload): bool {
                    return ($payload['schema_version'] ?? '') === ControllerAnnotationRulesCollector::SCHEMA
                        && is_array($payload['rules'] ?? null);
                }),
            );

        $collector = new ControllerAnnotationRulesCollector($events);
        $rules = $collector->collectAll();
        self::assertIsArray($rules);
    }
}
