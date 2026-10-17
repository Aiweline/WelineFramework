<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Api\Param;

use PHPUnit\Framework\TestCase;
use Weline\Widget\Api\Param\ParamDefinition;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWidgetParamContractsPass().
 */
final class WidgetParamContractsTest extends TestCase
{
    public function testAllWidgetParamContractsPass(): void
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
            'ParamDefinitionImageI18n::testMediaImageUiTypeIsTranslatableByDefault' => function () {
            $this->assert_ParamDefinitionImageI18n_testMediaImageUiTypeIsTranslatableByDefault();
        },
            'ParamDefinitionImageI18n::testExplicitI18nFalseOptsOutImageFields' => function () {
            $this->assert_ParamDefinitionImageI18n_testExplicitI18nFalseOptsOutImageFields();
        },
            'ParamDefinitionImageI18n::testNonImageNonTextTypesStayLocaleNeutral' => function () {
            $this->assert_ParamDefinitionImageI18n_testNonImageNonTextTypesStayLocaleNeutral();
        },
        ];
    }

private function assert_ParamDefinitionImageI18n_testMediaImageUiTypeIsTranslatableByDefault(): void
{
    self::assertTrue(ParamDefinition::isTranslatable(['type' => 'string', 'ui_type' => 'media_image']));
    self::assertTrue(ParamDefinition::isTranslatable(['type' => 'media_image']));
    self::assertTrue(ParamDefinition::isTranslatable(['type' => 'image']));
    self::assertTrue(ParamDefinition::isImageUiType(['ui_type' => 'media_image']));
}

private function assert_ParamDefinitionImageI18n_testExplicitI18nFalseOptsOutImageFields(): void
{
    self::assertFalse(ParamDefinition::isTranslatable(['type' => 'media_image', 'i18n' => false]));
    self::assertFalse(ParamDefinition::isTranslatable(['type' => 'string', 'ui_type' => 'media_image', 'i18n' => false]));
}

private function assert_ParamDefinitionImageI18n_testNonImageNonTextTypesStayLocaleNeutral(): void
{
    self::assertFalse(ParamDefinition::isTranslatable(['type' => 'bool']));
    self::assertFalse(ParamDefinition::isTranslatable(['type' => 'color']));
    self::assertFalse(ParamDefinition::isTranslatable(['type' => 'select']));
}
}
