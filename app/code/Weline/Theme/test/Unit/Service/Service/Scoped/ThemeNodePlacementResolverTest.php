<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Scoped\ThemeNodePlacementResolver;

final class ThemeNodePlacementResolverTest extends TestCase
{
    public function testAfterAnchorMovesLegacyProjectionIntoAnchorContainerAndOrder(): void
    {
        $moving = '0123456789abcdef0123456789abcdef';
        $anchor = 'fedcba9876543210fedcba9876543210';
        $resolved = (new ThemeNodePlacementResolver())->materialize([
            $moving => [
                'node_uid' => $moving,
                'area' => 'content',
                'slot_id' => 'content-main',
                'sort_order' => 0,
                'anchor_uid' => $anchor,
                'position' => 'after',
            ],
            $anchor => [
                'node_uid' => $anchor,
                'area' => 'header',
                'slot_id' => 'header-actions',
                'sort_order' => 7,
            ],
        ]);

        self::assertSame('header', $resolved[$moving]['area']);
        self::assertSame('header-actions', $resolved[$moving]['slot_id']);
        self::assertSame(0, $resolved[$anchor]['sort_order']);
        self::assertSame(1, $resolved[$moving]['sort_order']);
    }

    public function testAnchorCycleFailsClosed(): void
    {
        $left = '0123456789abcdef0123456789abcdef';
        $right = 'fedcba9876543210fedcba9876543210';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('theme_layout_node_anchor_cycle');
        (new ThemeNodePlacementResolver())->materialize([
            $left => [
                'node_uid' => $left,
                'anchor_uid' => $right,
                'position' => 'after',
            ],
            $right => [
                'node_uid' => $right,
                'anchor_uid' => $left,
                'position' => 'before',
            ],
        ]);
    }

    public function testInsideUsesParentContainerAndDeterministicLegacyOrder(): void
    {
        $child = '0123456789abcdef0123456789abcdef';
        $parent = 'fedcba9876543210fedcba9876543210';
        $resolved = (new ThemeNodePlacementResolver())->materialize([
            $child => [
                'node_uid' => $child,
                'area' => 'content',
                'slot_id' => 'content-main',
                'sort_order' => 0,
                'parent_uid' => $parent,
                'position' => 'inside',
            ],
            $parent => [
                'node_uid' => $parent,
                'area' => 'footer',
                'slot_id' => 'footer-main',
                'sort_order' => 5,
            ],
        ]);

        self::assertSame('footer', $resolved[$child]['area']);
        self::assertSame('footer-main', $resolved[$child]['slot_id']);
        self::assertSame(0, $resolved[$parent]['sort_order']);
        self::assertSame(1, $resolved[$child]['sort_order']);
    }

    public function testAmbiguousParentAndAnchorRelationFailsClosed(): void
    {
        $node = '0123456789abcdef0123456789abcdef';
        $target = 'fedcba9876543210fedcba9876543210';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('theme_layout_node_relation_ambiguous');
        (new ThemeNodePlacementResolver())->materialize([
            $node => [
                'node_uid' => $node,
                'parent_uid' => $target,
                'anchor_uid' => $target,
                'position' => 'inside',
            ],
            $target => ['node_uid' => $target],
        ]);
    }
}
