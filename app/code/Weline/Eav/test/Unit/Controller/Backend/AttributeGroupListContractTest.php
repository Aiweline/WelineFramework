<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * Legacy Attribute Group/Set list pages must join LocalDescription on `id`.
 */
final class AttributeGroupListContractTest extends TestCase
{
    private static function moduleRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    public function testGroupIndexJoinsEntityLocalDescriptionOnIdColumn(): void
    {
        $source = (string)file_get_contents(
            self::moduleRoot() . '/Controller/Backend/Attribute/Group.php',
        );

        self::assertStringContainsString(
            'main_table.eav_entity_id=entity_local.id',
            $source,
        );
        self::assertStringNotContainsString(
            'entity_local.eav_entity_id',
            $source,
        );
    }

    public function testSetIndexJoinsEntityLocalDescriptionOnIdColumn(): void
    {
        $source = (string)file_get_contents(
            self::moduleRoot() . '/Controller/Backend/Attribute/Set.php',
        );

        self::assertStringContainsString(
            'main_table.eav_entity_id=entity_local.id',
            $source,
        );
        self::assertStringNotContainsString(
            'entity_local.eav_entity_id',
            $source,
        );
    }

    public function testGroupIndexTemplateUsesModernPageShell(): void
    {
        $source = (string)file_get_contents(
            self::moduleRoot() . '/view/templates/Backend/Attribute/Group/index.phtml',
        );

        self::assertStringContainsString('w-eav-manager-page', $source);
        self::assertStringContainsString('data-testid="eav-attribute-group-list"', $source);
        self::assertStringContainsString('off_canvas_attribute_group_add', $source);
        self::assertStringNotContainsString('page-title-box', $source);
    }
}
