<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class RoleAssignTreeSyncContractTest extends TestCase
{
    public function testMenuSyncSelectsOnlyLeavesSoAncestorGrantsDoNotRecheckAppStore(): void
    {
        $template = (string)\file_get_contents(
            BP . '/app/code/Weline/Acl/view/templates/Backend/Acl/Role/assign.phtml'
        );

        self::assertStringContainsString('function isTreeLeaf(tree, id)', $template);
        self::assertStringContainsString('if (!isTreeLeaf(tree, id))', $template);
        self::assertStringContainsString('Do not programmatically select_node() parents', $template);
        self::assertStringContainsString('three_state: true', $template);
        self::assertStringContainsString('Shared grant set is the save source of truth', $template);
        self::assertStringNotContainsString('menuVisibleLeaves[id] && !menuTree.is_selected(id)', $template);
        self::assertStringNotContainsString('shared.delete(id);', $template);
    }

    public function testAssignTreeCssKillsJstreeLightSpriteInDarkMode(): void
    {
        $template = (string)\file_get_contents(
            BP . '/app/code/Weline/Acl/view/templates/Backend/Acl/Role/assign.phtml'
        );

        self::assertStringContainsString('background-image: none !important;', $template);
        self::assertStringContainsString('text-shadow: none !important;', $template);
        self::assertStringContainsString('#acl .jstree-hovered, #acl-tag .jstree-hovered', $template);
        self::assertStringContainsString('[data-theme-mode="dark"] #acl .jstree-anchor', $template);
        self::assertStringContainsString('display:flex 会打乱 jstree-wholerow', $template);
        self::assertStringContainsString('#acl .jstree-closed > .jstree-ocl::before', $template);
        self::assertStringContainsString('border-right: 1.5px solid', $template);
        self::assertStringNotContainsString('filter: invert(1) brightness(1.35)', $template);
        self::assertStringNotContainsString("variant: 'large'", $template);
        self::assertStringContainsString('.jstree-node > .jstree-children, #acl-tag > .jstree-container-ul > .jstree-node > .jstree-children {', $template);
        self::assertMatchesRegularExpression(
            '/#acl > \.jstree-container-ul > \.jstree-node > \.jstree-children[\s\S]*?background:\s*transparent;/',
            $template
        );
    }
}
