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

    public function testPostedPcLeavesSurviveWhenParentMenuOmittedFromPost(): void
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
        // UI only posts jstree leaves; parent menus are omitted even when still granted.
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
        self::assertContains('Weline_AppStore::index_view', $kept);
        self::assertContains('Weline_AppStore::index_download', $kept);
        self::assertContains('Weline_AppStore::appstore', $expanded);
        self::assertContains('Weline_AppStore::index', $expanded);
    }

    public function testCheckingDashboardDoesNotDropGrantedCmsLeaves(): void
    {
        $rows = [
            ['source_id' => 'Weline_Backend::dashboard', 'parent_source' => '', 'type' => 'menus'],
            ['source_id' => 'Weline_Dashboard::index', 'parent_source' => 'Weline_Backend::dashboard', 'type' => 'pc'],
            ['source_id' => 'Weline_Backend::content', 'parent_source' => '', 'type' => 'menus'],
            ['source_id' => 'Weline_Cms::cms', 'parent_source' => 'Weline_Backend::content', 'type' => 'menus'],
            ['source_id' => 'Weline_Cms::page', 'parent_source' => 'Weline_Cms::cms', 'type' => 'menus'],
            ['source_id' => 'Weline_Cms::page_listing', 'parent_source' => 'Weline_Cms::page', 'type' => 'pc'],
            ['source_id' => 'Weline_Media::manager', 'parent_source' => 'Weline_Backend::content', 'type' => 'menus'],
            ['source_id' => 'Weline_Media::manager_index', 'parent_source' => 'Weline_Media::manager', 'type' => 'pc'],
            ['source_id' => 'Weline_Inquiry::inquiry', 'parent_source' => 'Weline_Backend::content', 'type' => 'menus'],
        ];
        $previous = [
            'Weline_Backend::content',
            'Weline_Cms::cms',
            'Weline_Cms::page',
            'Weline_Cms::page_listing',
            'Weline_Media::manager',
            'Weline_Media::manager_index',
            'Weline_Inquiry::inquiry',
        ];
        $posted = [
            'Weline_Dashboard::index',
            'Weline_Cms::page_listing',
            'Weline_Media::manager_index',
            'Weline_Inquiry::inquiry',
        ];

        $kept = AclResourcePresentation::revokeUncheckedMenuSubtrees($posted, $previous, $rows);
        $expanded = AclResourcePresentation::expandMenusAncestors($kept, $rows);

        self::assertContains('Weline_Dashboard::index', $expanded);
        self::assertContains('Weline_Cms::page_listing', $kept);
        self::assertContains('Weline_Media::manager_index', $kept);
        self::assertContains('Weline_Inquiry::inquiry', $kept);
        self::assertContains('Weline_Cms::cms', $expanded);
        self::assertContains('Weline_Media::manager', $expanded);
        self::assertContains('Weline_Backend::content', $expanded);
    }

    public function testFullyUncheckedMenuWithNoPostedDescendantsStaysDropped(): void
    {
        $rows = [
            ['source_id' => 'Weline_Backend::apps_tools', 'parent_source' => '', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::appstore', 'parent_source' => 'Weline_Backend::apps_tools', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::index', 'parent_source' => 'Weline_AppStore::appstore', 'type' => 'menus'],
            ['source_id' => 'Weline_AppStore::index_view', 'parent_source' => 'Weline_AppStore::index', 'type' => 'pc'],
            ['source_id' => 'GuoLaiRen_PageBuilder::page_builder_group', 'parent_source' => 'Weline_Backend::apps_tools', 'type' => 'menus'],
        ];
        $previous = [
            'Weline_Backend::apps_tools',
            'Weline_AppStore::appstore',
            'Weline_AppStore::index',
            'Weline_AppStore::index_view',
            'GuoLaiRen_PageBuilder::page_builder_group',
        ];
        $posted = [
            'GuoLaiRen_PageBuilder::page_builder_group',
        ];

        $kept = AclResourcePresentation::revokeUncheckedMenuSubtrees($posted, $previous, $rows);
        $expanded = AclResourcePresentation::expandMenusAncestors($kept, $rows);

        self::assertContains('GuoLaiRen_PageBuilder::page_builder_group', $expanded);
        self::assertNotContains('Weline_AppStore::appstore', $kept);
        self::assertNotContains('Weline_AppStore::index_view', $kept);
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
