<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** 数组项表单必须走已接通的 paramrender/form，不得再自绘第二套卡片。 */
final class ThemeEditorArrayShellContractTest extends TestCase
{
    public function testArrayFormsUseParamRenderFormNotFallbackCards(): void
    {
        $file = dirname(__DIR__, 3) . '/view/statics/js/theme-editor.js';
        self::assertFileExists($file);
        $src = (string)file_get_contents($file);
        $detect = $this->functionBody($src, 'function paramsContainWidgetArray');
        self::assertStringContainsString("type === 'array'", $detect);
        self::assertStringContainsString("endsWith('_items')", $detect);
        self::assertStringContainsString("schemaType === 'all_menu_tree'", $detect);
        self::assertStringNotContainsString('fetch(', $detect);

        $form = $this->functionBody($src, 'async function generateWidgetConfigForm');
        self::assertStringContainsString('paramsContainWidgetArray(params)', $form);
        self::assertStringContainsString("config.apiParamRenderForm || '/theme/backend/widget/paramrender/form'", $form);
        self::assertStringContainsString('apiText(', $form);
        self::assertStringNotContainsString('renderFallbackArrayItem', $form);
        self::assertStringNotContainsString('paramrender/field', $form);
        self::assertStringNotContainsString('fetch(', $form);
        $arrayBranch = strstr($form, 'paramsContainWidgetArray(params)', true);
        self::assertIsString($arrayBranch);
        self::assertStringNotContainsString('generateWidgetConfigFormFallback', (string)$arrayBranch);
    }

    private function functionBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        self::assertNotFalse($start, $signature);
        $brace = strpos($src, '{', $start);
        self::assertNotFalse($brace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }
        self::fail('unclosed ' . $signature);
    }
}
