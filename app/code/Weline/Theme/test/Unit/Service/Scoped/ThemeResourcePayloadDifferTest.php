<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Service\Scoped\ThemeLayoutPayloadDiffer;
use Weline\Theme\Service\Scoped\ThemePatchEngine;
use Weline\Theme\Service\Scoped\ThemeResourcePayloadDiffer;

final class ThemeResourcePayloadDifferTest extends TestCase
{
    public function testDigitsOnlyStableTranslationUidRemainsAddressable(): void
    {
        $uid = str_repeat('1', 32);
        $context = new ThemeEditorContext(
            scope: new ScopeContext(
                identity: ScopeIdentity::global(),
                storageScope: 'default.default.default',
                storeMode: ScopeIdentity::MODE_NORMAL,
                fallbackStorageScopes: ['default.default.default'],
            ),
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_I18N,
            themeId: 1,
        );
        $differ = new ThemeResourcePayloadDiffer(new ThemeLayoutPayloadDiffer());

        $commands = $differ->diff(
            $context,
            ['translations' => [$uid => 'Website']],
            ['translations' => [$uid => 'Store']],
        );

        self::assertCount(1, $commands);
        self::assertSame('/translations/' . $uid, $commands[0]->path);
        self::assertSame('Store', $commands[0]->value);
    }

    public function testMapKeysUseLosslessJsonPointerEscaping(): void
    {
        $context = new ThemeEditorContext(
            scope: new ScopeContext(
                identity: ScopeIdentity::global(),
                storageScope: 'default.default.default',
                storeMode: ScopeIdentity::MODE_NORMAL,
                fallbackStorageScopes: ['default.default.default'],
            ),
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_META,
            themeId: 1,
        );
        $differ = new ThemeResourcePayloadDiffer(new ThemeLayoutPayloadDiffer());

        $commands = $differ->diff(
            $context,
            ['values' => []],
            ['values' => ['hero/title~primary' => 'Store']],
        );

        self::assertCount(1, $commands);
        self::assertSame('/values/hero~1title~0primary', $commands[0]->path);
        self::assertSame(
            ['values' => ['hero/title~primary' => 'Store']],
            (new ThemePatchEngine())->apply(['values' => []], $commands),
        );
    }

    public function testLayoutFullFormDoesNotOwnParentFieldsMissingFromTarget(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $context = new ThemeEditorContext(
            scope: new ScopeContext(
                identity: ScopeIdentity::global(),
                storageScope: 'default.default.default',
                storeMode: ScopeIdentity::MODE_NORMAL,
                fallbackStorageScopes: ['default.default.default'],
            ),
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: 1,
        );
        $differ = new ThemeResourcePayloadDiffer(new ThemeLayoutPayloadDiffer());
        $parent = [
            'nodes' => [$uid => [
                'node_uid' => $uid,
                'widget_code' => 'hero',
                'config' => ['title' => 'Website', 'subtitle' => 'Inherited'],
            ]],
            'selection' => [],
        ];

        $commands = $differ->diff($context, $parent, [
            'nodes' => [$uid => [
                'node_uid' => $uid,
                'widget_code' => 'hero',
                'config' => ['title' => 'Store'],
            ]],
            'selection' => [],
        ]);

        self::assertSame(['/nodes/' . $uid . '/config/title'], \array_map(
            static fn(ThemePatchCommand $command): string => $command->path,
            $commands,
        ));
        $effective = (new ThemePatchEngine())->apply($parent, $commands);
        self::assertSame('Store', $effective['nodes'][$uid]['config']['title']);
        self::assertSame('Inherited', $effective['nodes'][$uid]['config']['subtitle']);
    }

    public function testLayoutFullFormConvertsRelativePlacementToStructuralCommand(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $anchor = 'fedcba9876543210fedcba9876543210';
        $differ = new ThemeLayoutPayloadDiffer();
        $parent = ['nodes' => [
            $uid => ['node_uid' => $uid, 'area' => 'content', 'sort_order' => 0],
            $anchor => ['node_uid' => $anchor, 'area' => 'header', 'sort_order' => 0],
        ], 'selection' => []];
        $target = ['nodes' => [
            $uid => [
                'node_uid' => $uid,
                'area' => 'header',
                'sort_order' => 1,
                'anchor_uid' => $anchor,
                'position' => 'after',
            ],
            $anchor => ['node_uid' => $anchor, 'area' => 'header', 'sort_order' => 0],
        ], 'selection' => []];

        $commands = $differ->diff($parent, $target);
        self::assertSame(ThemePatchCommand::OP_MOVE_NODE, $commands[0]->operation);
        self::assertSame($anchor, $commands[0]->anchorUid);
        self::assertSame('after', $commands[0]->position);
        $effective = (new ThemePatchEngine())->apply($parent, $commands);
        self::assertArrayNotHasKey('parent_uid', $effective['nodes'][$uid]);
        self::assertSame($anchor, $effective['nodes'][$uid]['anchor_uid']);
        self::assertSame('after', $effective['nodes'][$uid]['position']);
    }

    public function testLayoutFullFormCanExplicitlyClearInheritedRelativePlacement(): void
    {
        $uid = '0123456789abcdef0123456789abcdef';
        $anchor = 'fedcba9876543210fedcba9876543210';
        $differ = new ThemeLayoutPayloadDiffer();
        $parent = ['nodes' => [
            $uid => [
                'node_uid' => $uid,
                'area' => 'header',
                'anchor_uid' => $anchor,
                'position' => 'after',
            ],
            $anchor => ['node_uid' => $anchor, 'area' => 'header'],
        ], 'selection' => []];
        $target = ['nodes' => [
            $uid => ['node_uid' => $uid, 'area' => 'content'],
            $anchor => ['node_uid' => $anchor, 'area' => 'header'],
        ], 'selection' => []];

        $commands = $differ->diff($parent, $target);
        $effective = (new ThemePatchEngine())->apply($parent, $commands);

        self::assertNull($effective['nodes'][$uid]['parent_uid']);
        self::assertNull($effective['nodes'][$uid]['anchor_uid']);
        self::assertNull($effective['nodes'][$uid]['position']);
        self::assertSame('content', $effective['nodes'][$uid]['area']);
    }
}
