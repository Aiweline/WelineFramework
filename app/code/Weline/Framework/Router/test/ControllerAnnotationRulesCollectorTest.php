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

    public function testAppleDoubleSidecarIsNotExecuted(): void
    {
        $root = sys_get_temp_dir() . '/weline-appledouble-' . bin2hex(random_bytes(4));
        mkdir($root . '/Controller', 0777, true);
        $sidecar = $root . '/Controller/._Webhook.php';
        file_put_contents($sidecar, "\x00\x05\x16\x07Mac OS X        com.apple.provenance");

        $events = $this->createMock(EventsManager::class);
        $collector = new ControllerAnnotationRulesCollector($events);
        $collect = new \ReflectionMethod(ControllerAnnotationRulesCollector::class, 'collectModule');

        ob_start();
        try {
            $rules = $collect->invoke($collector, 'Probe_Module', ['base_path' => $root]);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
            @unlink($sidecar);
            @rmdir($root . '/Controller');
            @rmdir($root);
        }

        self::assertSame([], $rules);
        self::assertSame('', $output);
        self::assertTrue(ControllerAnnotationRulesCollector::isAppleDoublePath($sidecar));
    }
}
