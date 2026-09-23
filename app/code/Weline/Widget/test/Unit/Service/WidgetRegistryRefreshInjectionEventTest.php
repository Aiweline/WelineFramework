<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\EventsManager;
use Weline\Widget\Service\ParamSchemaRegistry;
use Weline\Widget\Service\WidgetRegistry;
use Weline\Widget\Service\WidgetRegistryRefreshService;

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
}

final class WidgetRegistryRefreshInjectionEventTest extends TestCase
{
    public function testLastDeclarationRemovalIsDeliveredWithoutInventingInstalledWidgets(): void
    {
        $changes = [['widget_identity' => ['module' => 'Weline_Test', 'type' => 'test', 'code' => 'hero'], 'before' => [['layout_type' => 'homepage']], 'after' => []]];
        $registry = $this->createMock(WidgetRegistry::class);
        $registry->method('refreshWithReport')->willReturn(['success' => true, 'created_default_injection_widgets' => [], 'injection_structure_changes' => $changes]);
        $schemas = $this->createMock(ParamSchemaRegistry::class);
        $schemas->method('refresh')->willReturn(true);
        $events = $this->createMock(EventsManager::class);
        $captured = null;
        $events->method('dispatch')->willReturnCallback(function (string $name, mixed &$data) use (&$captured, $events) {
            $captured = [$name, $data];
            return $events;
        });
        $result = (new WidgetRegistryRefreshService($registry, $schemas, $events))->refresh('test');
        self::assertTrue($result['widget_install_event_dispatched']);
        self::assertSame('Weline_Widget::widget_install_after', $captured[0]);
        self::assertSame([], $captured[1]['widgets']);
        self::assertSame($changes, $captured[1]['injection_structure_changes']);
    }
}
