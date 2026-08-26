<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\TemplateInlineWidgetMerger;

final class TemplateInlineWidgetMergerTest extends TestCase
{
    public function testPlanKeepsUnmodifiedTemplateAndOverridesMatchedRef(): void
    {
        $merger = new TemplateInlineWidgetMerger();
        $plan = $merger->plan(
            [
                ['ref' => 'tpl:a', 'html' => '<div data-ref="a">A</div>'],
                ['ref' => 'tpl:b', 'html' => '<div data-ref="b">B</div>'],
            ],
            [
                [
                    'widget_code' => 'account',
                    'sort_order' => 0,
                    'config' => [
                        TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF => 'tpl:a',
                        'title' => 'custom',
                    ],
                ],
            ]
        );

        self::assertCount(2, $plan);
        self::assertSame('layout', $plan[0]['kind']);
        self::assertSame('account', $plan[0]['widget']['widget_code']);
        self::assertSame('template', $plan[1]['kind']);
        self::assertStringContainsString('data-ref="b"', (string)$plan[1]['html']);
    }

    public function testPlanHonorsTombstoneAndAdditions(): void
    {
        $merger = new TemplateInlineWidgetMerger();
        $plan = $merger->plan(
            [
                ['ref' => 'tpl:a', 'html' => 'A'],
                ['ref' => 'tpl:b', 'html' => 'B'],
            ],
            [
                [
                    'widget_code' => 'gone',
                    'config' => [
                        TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF => 'tpl:a',
                        TemplateInlineWidgetMerger::CONFIG_TEMPLATE_DELETED => true,
                    ],
                ],
                [
                    'widget_code' => 'extra',
                    'sort_order' => 5,
                    'config' => [],
                ],
            ]
        );

        self::assertCount(2, $plan);
        self::assertSame('template', $plan[0]['kind']);
        self::assertSame('B', $plan[0]['html']);
        self::assertSame('layout', $plan[1]['kind']);
        self::assertSame('extra', $plan[1]['widget']['widget_code']);
    }

    public function testFullSlotOverrideUsesLayoutOrderOnly(): void
    {
        $merger = new TemplateInlineWidgetMerger();
        $plan = $merger->plan(
            [
                ['ref' => 'tpl:a', 'html' => 'A'],
                ['ref' => 'tpl:b', 'html' => 'B'],
            ],
            [
                [
                    'widget_code' => 'second',
                    'sort_order' => 20,
                    'config' => [
                        TemplateInlineWidgetMerger::CONFIG_COW_FULL_SLOT => true,
                        TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF => 'tpl:b',
                    ],
                ],
                [
                    'widget_code' => 'first',
                    'sort_order' => 10,
                    'config' => [
                        TemplateInlineWidgetMerger::CONFIG_TEMPLATE_REF => 'tpl:a',
                    ],
                ],
            ]
        );

        self::assertCount(2, $plan);
        self::assertSame('first', $plan[0]['widget']['widget_code']);
        self::assertSame('second', $plan[1]['widget']['widget_code']);
    }
}
