<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllEavBackendContractsPass().
 */
final class EavBackendContractsTest extends TestCase
{
    public function testAllEavBackendContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AttributeGroupList::testGroupIndexJoinsEntityLocalDescriptionOnIdColumn' => function () {
            $this->assert_AttributeGroupList_testGroupIndexJoinsEntityLocalDescriptionOnIdColumn();
        },
            'AttributeGroupList::testSetIndexJoinsEntityLocalDescriptionOnIdColumn' => function () {
            $this->assert_AttributeGroupList_testSetIndexJoinsEntityLocalDescriptionOnIdColumn();
        },
            'AttributeGroupList::testGroupIndexTemplateUsesModernPageShell' => function () {
            $this->assert_AttributeGroupList_testGroupIndexTemplateUsesModernPageShell();
        },
        ];
    }

private function moduleRoot_AttributeGroupList(): string
{
    return '/Users/weline/Project/Official/框架/app/code/Weline/Eav';
}

private function assert_AttributeGroupList_testGroupIndexJoinsEntityLocalDescriptionOnIdColumn(): void
{
    $source = (string) file_get_contents(self::moduleRoot_AttributeGroupList() . '/Controller/Backend/Attribute/Group.php');
    self::assertStringContainsString('main_table.eav_entity_id=entity_local.id', $source);
    self::assertStringNotContainsString('entity_local.eav_entity_id', $source);
}

private function assert_AttributeGroupList_testSetIndexJoinsEntityLocalDescriptionOnIdColumn(): void
{
    $source = (string) file_get_contents(self::moduleRoot_AttributeGroupList() . '/Controller/Backend/Attribute/Set.php');
    self::assertStringContainsString('main_table.eav_entity_id=entity_local.id', $source);
    self::assertStringNotContainsString('entity_local.eav_entity_id', $source);
}

private function assert_AttributeGroupList_testGroupIndexTemplateUsesModernPageShell(): void
{
    $source = (string) file_get_contents(self::moduleRoot_AttributeGroupList() . '/view/templates/backend/Attribute/Group/index.phtml');
    self::assertStringContainsString('w-eav-manager-page', $source);
    self::assertStringContainsString('data-testid="eav-attribute-group-list"', $source);
    self::assertStringContainsString('off_canvas_attribute_group_add', $source);
    self::assertStringNotContainsString('page-title-box', $source);
}
}
