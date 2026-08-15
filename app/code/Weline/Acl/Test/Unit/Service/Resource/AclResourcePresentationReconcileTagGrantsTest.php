<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service\Resource;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Service\Resource\AclResourcePresentation;

final class AclResourcePresentationReconcileTagGrantsTest extends TestCase
{
    public function testUncheckingAMenuSubtreeDropsTheCoveringTagGrant(): void
    {
        $byTagPath = [
            'appstore' => [
                'Weline_AppStore::appstore' => true,
                'Weline_AppStore::index' => true,
                'Weline_AppStore::index_view' => true,
            ],
        ];
        $previous = [
            'Weline_Backend::apps_tools',
            'Weline_AppStore::appstore',
            'Weline_AppStore::index',
            'Weline_AppStore::index_view',
            'GuoLaiRen_PageBuilder::page_builder_group',
        ];
        $posted = [
            'Weline_Backend::apps_tools',
            'GuoLaiRen_PageBuilder::page_builder_group',
        ];

        $reconciled = AclResourcePresentation::reconcileTagGrantsForSave(
            $posted,
            ['appstore'],
            $byTagPath,
            $previous,
        );

        self::assertSame([], $reconciled['tag_paths']);
        self::assertSame([], $reconciled['extra_ids']);
    }

    public function testIntactTagGrantStillExpandsNewSources(): void
    {
        $byTagPath = [
            'appstore' => [
                'Weline_AppStore::appstore' => true,
                'Weline_AppStore::index' => true,
                'Weline_AppStore::index_new' => true,
            ],
        ];
        $previous = [
            'Weline_AppStore::appstore',
            'Weline_AppStore::index',
        ];
        $posted = [
            'Weline_AppStore::appstore',
            'Weline_AppStore::index',
        ];

        $reconciled = AclResourcePresentation::reconcileTagGrantsForSave(
            $posted,
            ['appstore'],
            $byTagPath,
            $previous,
        );

        self::assertSame(['appstore'], $reconciled['tag_paths']);
        self::assertSame(['Weline_AppStore::index_new'], $reconciled['extra_ids']);
    }

    public function testExpandMenusAncestorsDoesNotInventAParentWithoutDescendants(): void
    {
        $rows = [
            ['source_id' => 'Weline_Backend::apps_tools', 'parent_source' => '', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::appstore', 'parent_source' => 'Weline_Backend::apps_tools', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::index', 'parent_source' => 'Weline_AppStore::appstore', 'type' => 'menus'],
            ['source_id' => 'GuoLaiRen_PageBuilder::page_builder_group', 'parent_source' => 'Weline_Backend::apps_tools', 'type' => 'menus'],
        ];

        $expanded = AclResourcePresentation::expandMenusAncestors(
            ['GuoLaiRen_PageBuilder::page_builder_group'],
            $rows,
        );

        self::assertContains('GuoLaiRen_PageBuilder::page_builder_group', $expanded);
        self::assertContains('Weline_Backend::apps_tools', $expanded);
        self::assertNotContains('Weline_AppStore::appstore', $expanded);
        self::assertNotContains('Weline_AppStore::index', $expanded);
    }

    public function testRevokeUncheckedMenuSubtreeDropsPostedPcOrphans(): void
    {
        $rows = [
            ['source_id' => 'Weline_Backend::apps_tools', 'parent_source' => '', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::appstore', 'parent_source' => 'Weline_Backend::apps_tools', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::index', 'parent_source' => 'Weline_AppStore::appstore', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::index_view', 'parent_source' => 'Weline_AppStore::index', 'type' => 'pc'],
            ['source_id' => 'Weline_AppStore::index_download', 'parent_source' => 'Weline_AppStore::index', 'type' => 'pc'],
            ['source_id' => 'GuoLaiRen_PageBuilder::page_builder_group', 'parent_source' => 'Weline_Backend::apps_tools', 'type' => 'menus'],
        ];
        $previous = [
            'Weline_Backend::apps_tools',
            'Weline_AppStore::appstore',
            'Weline_AppStore::index',
            'Weline_AppStore::index_view',
            'Weline_AppStore::index_download',
            'GuoLaiRen_PageBuilder::page_builder_group',
        ];
        $posted = [
            'Weline_Backend::apps_tools',
            'GuoLaiRen_PageBuilder::page_builder_group',
            'Weline_AppStore::index_view',
            'Weline_AppStore::index_download',
        ];

        $kept = AclResourcePresentation::revokeUncheckedMenuSubtrees($posted, $previous, $rows);
        $expanded = AclResourcePresentation::expandMenusAncestors($kept, $rows);

        self::assertContains('GuoLaiRen_PageBuilder::page_builder_group', $expanded);
        self::assertContains('Weline_Backend::apps_tools', $expanded);
        self::assertNotContains('Weline_AppStore::appstore', $kept);
        self::assertNotContains('Weline_AppStore::index_view', $kept);
        self::assertNotContains('Weline_AppStore::index_download', $kept);
        self::assertNotContains('Weline_AppStore::appstore', $expanded);
        self::assertNotContains('Weline_AppStore::index', $expanded);
    }

    public function testPartialMenuUncheckKeepsRemainingChildren(): void
    {
        $rows = [
            ['source_id' => 'Weline_AppStore::appstore', 'parent_source' => '', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::index', 'parent_source' => 'Weline_AppStore::appstore', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::license', 'parent_source' => 'Weline_AppStore::appstore', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::index_view', 'parent_source' => 'Weline_AppStore::index', 'type' => 'pc'],
            ['source_id' => 'Weline_AppStore::license_view', 'parent_source' => 'Weline_AppStore::license', 'type' => 'pc'],
        ];
        $previous = [
            'Weline_AppStore::appstore',
            'Weline_AppStore::index',
            'Weline_AppStore::license',
            'Weline_AppStore::index_view',
            'Weline_AppStore::license_view',
        ];
        $posted = [
            'Weline_AppStore::index',
            'Weline_AppStore::index_view',
        ];

        $kept = AclResourcePresentation::revokeUncheckedMenuSubtrees($posted, $previous, $rows);

        self::assertContains('Weline_AppStore::index', $kept);
        self::assertContains('Weline_AppStore::index_view', $kept);
        self::assertNotContains('Weline_AppStore::license', $kept);
        self::assertNotContains('Weline_AppStore::license_view', $kept);
    }

    public function testAssignmentNodePreselectedOnlyMarksLeaves(): void
    {
        self::assertTrue(AclResourcePresentation::assignmentNodePreselected(true, false));
        self::assertFalse(AclResourcePresentation::assignmentNodePreselected(true, true));
        self::assertFalse(AclResourcePresentation::assignmentNodePreselected(false, false));
        self::assertFalse(AclResourcePresentation::assignmentNodePreselected(false, true));
    }
}
