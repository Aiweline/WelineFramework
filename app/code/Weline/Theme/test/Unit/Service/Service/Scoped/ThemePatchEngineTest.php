<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Service\Scoped\ThemePatchEngine;

final class ThemePatchEngineTest extends TestCase
{
    private ThemePatchEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ThemePatchEngine();
    }

    public function testExplicitEmptyFalseZeroAndNullRemainOwnedValues(): void
    {
        $commands = [
            $this->set('/values/empty', ''),
            $this->set('/values/false', false),
            $this->set('/values/zero', 0),
            $this->set('/values/null', null),
        ];

        $payload = $this->engine->apply(['values' => []], $commands);

        self::assertSame('', $payload['values']['empty']);
        self::assertFalse($payload['values']['false']);
        self::assertSame(0, $payload['values']['zero']);
        self::assertArrayHasKey('null', $payload['values']);
        self::assertNull($payload['values']['null']);
    }

    public function testInheritNodeRemovesAllDescendantOwnershipOnly(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $other = 'fedcba9876543210fedcba9876543210';
        $current = [
            $this->set('/nodes/' . $uid . '/config/title', 'Store'),
            $this->set('/nodes/' . $uid . '/config/enabled', false),
            $this->set('/nodes/' . $other . '/config/title', 'Other'),
        ];
        $inherit = ThemePatchCommand::fromArray([
            'op' => 'inherit',
            'path' => '/nodes/' . $uid,
        ]);

        $owned = $this->engine->mergeOwnedCommands($current, [$inherit]);

        self::assertSame(['/nodes/' . $other . '/config/title'], array_map(
            static fn(ThemePatchCommand $command): string => $command->path,
            $owned,
        ));
    }

    public function testParentDeleteOfOwnedNodeProducesStructuralConflict(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $oldParent = ['nodes' => [$uid => ['node_uid' => $uid, 'config' => ['title' => 'Parent']]]];
        $newParent = ['nodes' => []];

        $conflicts = $this->engine->structuralConflicts(
            $oldParent,
            $newParent,
            [$this->set('/nodes/' . $uid . '/config/title', 'Store')],
        );

        self::assertSame('parent_deleted_owned_node', $conflicts[0]['code'] ?? null);
        self::assertSame($uid, $conflicts[0]['node_uid'] ?? null);
    }

    public function testDigitsOnlyStableUidIsNotTreatedAsArrayIndex(): void
    {
        $uid = str_repeat('1', 32);
        $command = ThemePatchCommand::fromArray([
            'op' => 'set',
            'path' => '/nodes/' . $uid . '/config/title',
            'value' => 'Owned',
        ]);

        self::assertSame('/nodes/' . $uid . '/config/title', $command->path);
    }

    public function testArrayIndexPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ThemePatchCommand::fromArray([
            'op' => 'set',
            'path' => '/values/slides/0/title',
            'value' => 'Invalid',
        ]);
    }

    public function testSetCannotSpoofNodeIdentityMetadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ThemePatchCommand::fromArray([
            'op' => 'set',
            'path' => '/nodes/0123456789abcdef0123456789abcdef/config/title',
            'node_uid' => 'fedcba9876543210fedcba9876543210',
            'value' => 'Invalid',
        ]);
    }

    public function testWholeNodeSetMustPreserveStableIdentity(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $this->expectException(\InvalidArgumentException::class);
        ThemePatchCommand::fromArray([
            'op' => 'set',
            'path' => '/nodes/' . $uid,
            'value' => ['node_uid' => 'fedcba9876543210fedcba9876543210'],
        ]);
    }

    public function testAddNodeRequiresCompletePlacementMetadata(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $this->expectException(\InvalidArgumentException::class);
        ThemePatchCommand::fromArray([
            'op' => 'add_node',
            'path' => '/nodes/' . $uid,
            'node_uid' => $uid,
            'anchor_uid' => 'fedcba9876543210fedcba9876543210',
            'value' => ['node_uid' => $uid],
        ]);
    }

    public function testAddNodeRejectsValueIdentityMismatch(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $this->expectException(\InvalidArgumentException::class);
        ThemePatchCommand::fromArray([
            'op' => 'add_node',
            'path' => '/nodes/' . $uid,
            'node_uid' => $uid,
            'value' => ['node_uid' => 'fedcba9876543210fedcba9876543210'],
        ]);
    }

    public function testIgnoredOperationPayloadIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ThemePatchCommand::fromArray([
            'op' => 'inherit',
            'path' => '/values/title',
            'value' => null,
        ]);
    }

    public function testEditingDescendantAfterNodeTombstoneRestoresParentNode(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $parent = [
            'nodes' => [
                $uid => [
                    'node_uid' => $uid,
                    'widget_code' => 'hero',
                    'config' => ['title' => 'Website', 'enabled' => true],
                ],
            ],
        ];
        $removed = ThemePatchCommand::fromArray([
            'op' => 'remove_node',
            'path' => '/nodes/' . $uid,
            'node_uid' => $uid,
        ]);

        $owned = $this->engine->mergeOwnedCommands(
            [$removed],
            [$this->set('/nodes/' . $uid . '/config/title', 'Store')],
        );
        $payload = $this->engine->apply($parent, $owned);

        self::assertSame(['/nodes/' . $uid . '/config/title'], array_map(
            static fn(ThemePatchCommand $command): string => $command->path,
            $owned,
        ));
        self::assertSame('hero', $payload['nodes'][$uid]['widget_code']);
        self::assertTrue($payload['nodes'][$uid]['config']['enabled']);
        self::assertSame('Store', $payload['nodes'][$uid]['config']['title']);
    }

    public function testMovingNodeOutOfParentClearsStaleParentUid(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $anchor = 'fedcba9876543210fedcba9876543210';
        $payload = $this->engine->apply([
            'nodes' => [
                $uid => ['node_uid' => $uid, 'parent_uid' => $anchor],
                $anchor => ['node_uid' => $anchor],
            ],
        ], [ThemePatchCommand::fromArray([
            'op' => 'move_node',
            'path' => '/nodes/' . $uid,
            'node_uid' => $uid,
            'anchor_uid' => $anchor,
            'position' => 'after',
        ])]);

        self::assertArrayNotHasKey('parent_uid', $payload['nodes'][$uid]);
        self::assertSame($anchor, $payload['nodes'][$uid]['anchor_uid']);
        self::assertSame('after', $payload['nodes'][$uid]['position']);
    }

    public function testAddingNodeCanonicalizesPlacementFromCommand(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $anchor = 'fedcba9876543210fedcba9876543210';
        $payload = $this->engine->apply(['nodes' => [
            $anchor => ['node_uid' => $anchor],
        ]], [ThemePatchCommand::fromArray([
            'op' => 'add_node',
            'path' => '/nodes/' . $uid,
            'node_uid' => $uid,
            'anchor_uid' => $anchor,
            'position' => 'after',
            'value' => [
                'node_uid' => $uid,
                'parent_uid' => $anchor,
                'position' => 'inside',
            ],
        ])]);

        self::assertArrayNotHasKey('parent_uid', $payload['nodes'][$uid]);
        self::assertSame($anchor, $payload['nodes'][$uid]['anchor_uid']);
        self::assertSame('after', $payload['nodes'][$uid]['position']);
    }

    public function testMovingLocallyAddedNodeKeepsAddOwnership(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $anchor = 'fedcba9876543210fedcba9876543210';
        $added = ThemePatchCommand::fromArray([
            'op' => 'add_node',
            'path' => '/nodes/' . $uid,
            'node_uid' => $uid,
            'value' => ['node_uid' => $uid, 'widget_code' => 'hero'],
        ]);
        $moved = ThemePatchCommand::fromArray([
            'op' => 'move_node',
            'path' => '/nodes/' . $uid,
            'node_uid' => $uid,
            'anchor_uid' => $anchor,
            'position' => 'after',
        ]);

        $owned = $this->engine->mergeOwnedCommands([$added], [$moved]);
        self::assertCount(1, $owned);
        self::assertSame(ThemePatchCommand::OP_ADD_NODE, $owned[0]->operation);
        self::assertSame($anchor, $owned[0]->anchorUid);
        self::assertSame('hero', $owned[0]->value['widget_code']);
    }

    public function testRemovingLocallyAddedNodeRestoresInheritanceWithoutTombstone(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $added = ThemePatchCommand::fromArray([
            'op' => 'add_node',
            'path' => '/nodes/' . $uid,
            'node_uid' => $uid,
            'value' => ['node_uid' => $uid],
        ]);
        $removed = ThemePatchCommand::fromArray([
            'op' => 'remove_node',
            'path' => '/nodes/' . $uid,
            'node_uid' => $uid,
        ]);

        self::assertSame([], $this->engine->mergeOwnedCommands([$added], [$removed]));
    }

    public function testMoveReplacesOlderExplicitPlacementClears(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $oldAnchor = '11111111111111111111111111111111';
        $newAnchor = '22222222222222222222222222222222';
        $nodePath = '/nodes/' . $uid;
        $cleared = [
            $this->set($nodePath . '/parent_uid', null),
            $this->set($nodePath . '/anchor_uid', null),
            $this->set($nodePath . '/position', null),
        ];
        $move = ThemePatchCommand::fromArray([
            'op' => 'move_node',
            'path' => $nodePath,
            'node_uid' => $uid,
            'anchor_uid' => $newAnchor,
            'position' => 'after',
        ]);

        $owned = $this->engine->mergeOwnedCommands($cleared, [$move]);
        $payload = $this->engine->apply(['nodes' => [
            $uid => ['node_uid' => $uid, 'anchor_uid' => $oldAnchor, 'position' => 'before'],
            $oldAnchor => ['node_uid' => $oldAnchor],
            $newAnchor => ['node_uid' => $newAnchor],
        ]], $owned);

        self::assertCount(1, $owned);
        self::assertSame(ThemePatchCommand::OP_MOVE_NODE, $owned[0]->operation);
        self::assertSame($newAnchor, $payload['nodes'][$uid]['anchor_uid']);
        self::assertSame('after', $payload['nodes'][$uid]['position']);
    }

    public function testExplicitPlacementClearReplacesOlderMove(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $anchor = '11111111111111111111111111111111';
        $nodePath = '/nodes/' . $uid;
        $move = ThemePatchCommand::fromArray([
            'op' => 'move_node',
            'path' => $nodePath,
            'node_uid' => $uid,
            'anchor_uid' => $anchor,
            'position' => 'after',
        ]);
        $cleared = [
            $this->set($nodePath . '/parent_uid', null),
            $this->set($nodePath . '/anchor_uid', null),
            $this->set($nodePath . '/position', null),
        ];

        $owned = $this->engine->mergeOwnedCommands([$move], $cleared);
        $payload = $this->engine->apply(['nodes' => [
            $uid => ['node_uid' => $uid, 'anchor_uid' => $anchor, 'position' => 'before'],
            $anchor => ['node_uid' => $anchor],
        ]], $owned);

        self::assertCount(3, $owned);
        self::assertNull($payload['nodes'][$uid]['parent_uid']);
        self::assertNull($payload['nodes'][$uid]['anchor_uid']);
        self::assertNull($payload['nodes'][$uid]['position']);
    }

    private function set(string $path, mixed $value): ThemePatchCommand
    {
        return ThemePatchCommand::fromArray(['op' => 'set', 'path' => $path, 'value' => $value]);
    }
}
