<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 审图契约：B2B ControlCenter 可写页表单层次与人性化文案。
 */
final class ControlCenterTemplateShentuTest extends TestCase
{
    public function testWritableWorkspaceTemplateHasHumanFormAndEmptyState(): void
    {
        $path = BP . 'app/code/Weline/B2B/view/templates/Backend/ControlCenter/index.phtml';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('data-testid="b2b-<?= $escape($code) ?>-form-actions"', $content);
        self::assertStringContainsString("__('新增客户组')", $content);
        self::assertStringContainsString("__('组代码')", $content);
        self::assertStringContainsString("><?= __('启用') ?></option>", $content);
        self::assertStringContainsString("><?= __('停用') ?></option>", $content);
        self::assertStringContainsString('class="w-empty"', $content);
        self::assertStringContainsString("__('暂无客户组。请在上方填写信息后保存。')", $content);
        self::assertStringContainsString("__('可在此维护 B2B 配置。保存前会检查业务开关，并按规则校验站点与相关数据。')", $content);

        self::assertStringNotContainsString('B2B rollout 门禁', $content);
        self::assertStringNotContainsString('候选解析', $content);
        self::assertStringNotContainsString('<option value="active">active</option>', $content);
        self::assertStringNotContainsString('style="--w-span-md:2;"><button class="w-button" type="submit"', $content);
    }
}
