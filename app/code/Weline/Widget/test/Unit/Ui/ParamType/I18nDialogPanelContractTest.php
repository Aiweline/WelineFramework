<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Ui\ParamType;

use Weline\Framework\Test\TestCore;
use Weline\Widget\Service\ParamTypeRenderer;

final class I18nDialogPanelContractTest extends TestCore
{
    public function testArrayTextIntroRendersDialogWithoutMediaFlag(): void
    {
        $renderer = new ParamTypeRenderer();
        $params = [
            'tracks' => [
                'type' => 'array',
                'label' => '进店曲目',
                'item_schema' => [
                    'url' => [
                        'type' => 'media_image',
                        'label' => '曲目',
                        'i18n' => false,
                        'media_options' => ['kind' => 'audio'],
                    ],
                    'title' => [
                        'type' => 'string',
                        'label' => '曲名',
                        'i18n' => false,
                    ],
                    'intro' => [
                        'type' => 'textarea',
                        'label' => '简介',
                        'i18n' => true,
                    ],
                ],
            ],
        ];
        $config = [
            'tracks' => [
                [
                    'url' => '/media/a.mp3',
                    'title' => '测试曲',
                    'intro' => '简介文本',
                ],
            ],
        ];

        $html = $renderer->renderForm(42, $params, $config);

        self::assertStringContainsString(
            'id="i18n_panel_42_tracks_0_intro"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<dialog class="w-dialog w-param-i18n-panel w-param-i18n-dialog"[^>]*data-field="tracks\.0\.intro"[^>]*data-ui-type="textarea"/',
            $html
        );
        self::assertDoesNotMatchRegularExpression(
            '/<dialog[^>]*data-field="tracks\.0\.intro"[^>]*data-i18n-media="/',
            $html
        );
        self::assertStringContainsString('data-w-component="dialog"', $html);
        self::assertStringContainsString('data-w-closable="false"', $html);
        self::assertStringContainsString('data-w-backdrop="static"', $html);
        self::assertStringContainsString('w-dialog__surface', $html);
        self::assertStringContainsString('w-param-btn-i18n', $html);
        self::assertStringContainsString('data-close-i18n', $html);
        self::assertStringContainsString('data-i18n-locale-search', $html);
        self::assertStringContainsString('w-param-i18n-toolbar', $html);
        self::assertStringContainsString('搜索语言或代码', $html);
    }
}
