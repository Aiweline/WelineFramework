<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\EventAnnotationService;

final class EventAnnotationMatchParamTest extends TestCase
{
    public function testNormalizeMatchConditionsAcceptsHandwrittenParams(): void
    {
        $svc = new EventAnnotationService();
        $out = $svc->normalizeMatchConditions([
            ['param' => 'className', 'op' => 'contains', 'value' => 'weline-pixel'],
            ['param' => 'event_name', 'op' => 'equals', 'value' => 'click'],
            ['param' => '!!!', 'op' => 'equals', 'value' => 'x'],
            ['param' => '', 'op' => 'equals', 'value' => 'y'],
            ['param' => 'my.param_1', 'op' => 'equals', 'value' => 'z'],
        ]);

        self::assertCount(3, $out);
        self::assertSame('className', $out[0]['param']);
        self::assertSame('contains', $out[0]['op']);
        self::assertSame('weline-pixel', $out[0]['value']);
        self::assertSame('event_name', $out[1]['param']);
        self::assertSame('my.param_1', $out[2]['param']);
    }

    public function testMatchParamOptionsIncludeClassNamePreset(): void
    {
        $svc = new EventAnnotationService();
        $ids = \array_column($svc->matchParamOptions(), 'id');
        self::assertContains('event_name', $ids);
        self::assertContains('className', $ids);
        self::assertContains('text', $ids);
    }
}
