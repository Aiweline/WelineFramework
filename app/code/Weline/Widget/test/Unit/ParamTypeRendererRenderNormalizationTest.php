<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit;

use Weline\Framework\UnitTest\TestCore;
use Weline\Widget\Service\ParamTypeRenderer;

class ParamTypeRendererRenderNormalizationTest extends TestCore
{
    public function testRenderFormBackfillsJsonEncodedArrayItems(): void
    {
        $renderer = new ParamTypeRenderer();
        $params = [
            'slides' => [
                'type' => 'array',
                'label' => '轮播图片',
                'item_schema' => [
                    'image' => ['type' => 'media_image', 'label' => '图片'],
                    'title' => ['type' => 'string', 'label' => '标题'],
                ],
            ],
        ];
        $config = [
            'slides' => '[{"image":"https://example.com/banner.jpg","title":"Hero Title"}]',
        ];

        $html = $renderer->renderForm(10, $params, $config);

        $this->assertStringContainsString('https://example.com/banner.jpg', $html);
        $this->assertStringContainsString('Hero Title', $html);
        $this->assertStringNotContainsString('暂无项目，点击下方按钮添加', $html);
    }

    public function testRenderFieldBackfillsJsonEncodedMultipleSelectValues(): void
    {
        $renderer = new ParamTypeRenderer();
        $param = [
            'type' => 'select',
            'label' => '预设徽章',
            'multiple' => true,
            'options' => [
                'secure-payment' => '安全支付',
                'money-back' => '无忧退款',
                'free-shipping' => '免费配送',
            ],
        ];

        $html = $renderer->renderField('preset_badges', $param, '["secure-payment","free-shipping"]', 10);

        $this->assertStringContainsString('value="secure-payment" selected', $html);
        $this->assertStringContainsString('value="free-shipping" selected', $html);
    }
}
