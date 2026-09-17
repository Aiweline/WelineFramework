<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * ad-banner 角标必须绑定 alt_text，否则侧栏改「替代文字」后预览永不刷新可见文案。
 */
final class AdBannerAltTextLabelContractTest extends TestCase
{
    public function testAdLabelRendersAltTextNotHardcodedAdCopy(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/banner/ad-banner/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('@param alt_text {default="广告",type="string",label="替代文字"}', $source);
        self::assertStringContainsString('WidgetI18n::label(trim((string)($this->getData(\'alt_text\') ?? \'\')), \'广告\')', $source);
        self::assertStringContainsString('<span class="ad-label"><?= $esc((string)$altText) ?></span>', $source);
        self::assertStringNotContainsString('<span class="ad-label"><?= __(\'广告\') ?></span>', $source);
    }
}
