<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit;

use Weline\Framework\Test\TestCore;
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

    public function testRenderFieldPrefersUiTypeOverSemanticType(): void
    {
        $renderer = new ParamTypeRenderer();

        $colorHtml = $renderer->renderField('accent', [
            'type' => 'string',
            'ui_type' => 'color',
            'label' => 'Accent',
        ], '#ff0000', 10);
        $this->assertStringContainsString('w-param-color', $colorHtml);
        $this->assertStringContainsString('type="color"', $colorHtml);

        $imageHtml = $renderer->renderField('hero_image', [
            'type' => 'string',
            'ui_type' => 'media_image',
            'label' => 'Hero Image',
        ], '/media/banner.jpg', 10);
        $this->assertStringContainsString('w-param-media-image', $imageHtml);
        $this->assertStringContainsString('w-param-media-image-select', $imageHtml);
    }

    public function testProcessConfigPreservesExplicitEmptyString(): void
    {
        $renderer = new ParamTypeRenderer();

        $processed = $renderer->processConfig([
            'title' => [
                'type' => 'string',
                'label' => 'Title',
                'default' => 'Default title',
            ],
            'subtitle' => [
                'type' => 'string',
                'label' => 'Subtitle',
                'default' => 'Default subtitle',
            ],
        ], [
            'title' => '',
        ]);

        $this->assertSame('', $processed['title']);
        $this->assertSame('Default subtitle', $processed['subtitle']);
    }

    public function testParameterFieldsUseCanonicalWelineUiControls(): void
    {
        $renderer = new ParamTypeRenderer();

        $text = $renderer->renderField('title', [
            'type' => 'string',
            'label' => '标题',
        ], 'Hero', 10);
        $select = $renderer->renderField('tone', [
            'type' => 'select',
            'label' => '色调',
            'options' => ['light' => '亮色'],
        ], 'light', 10);
        $textarea = $renderer->renderField('content', [
            'type' => 'textarea',
            'label' => '内容',
        ], 'Copy', 10);
        $form = $renderer->renderForm(10, [
            'title' => ['type' => 'string', 'label' => '标题'],
        ], ['title' => 'Hero']);

        $this->assertStringContainsString('class="w-input"', $text);
        $this->assertStringContainsString('class="w-select"', $select);
        $this->assertStringContainsString('class="w-textarea', $textarea);
        $this->assertStringNotContainsString('w-param-input', $text . $select . $textarea);
        $this->assertStringNotContainsString('w-param-select', $text . $select . $textarea);
        $this->assertStringContainsString('class="w-button w-param-btn-delete-widget"', $form);
        $this->assertStringContainsString('data-tone="danger"', $form);
        $this->assertStringContainsString('data-variant="outline"', $form);
    }

    public function testRenderFormMaterializesDottedArrayPathsForFileImageItems(): void
    {
        $renderer = new ParamTypeRenderer();
        $params = [
            'slides' => [
                'type' => 'array',
                'label' => '轮播图片',
                'item_schema' => [
                    'image' => ['type' => 'image', 'label' => '图片'],
                    'title' => ['type' => 'string', 'label' => '标题'],
                ],
            ],
        ];
        $config = [
            'slides.0.image' => [
                'type' => 'file-image',
                'usage' => [
                    'version' => 1,
                    'asset_id' => '123e4567-e89b-42d3-a456-426614174000',
                    'locale_code' => 'zh_Hans_CN',
                    'alt' => '统一存储验收图标',
                ],
            ],
            'slides.0.title' => 'Hero Title',
        ];

        $html = $renderer->renderForm(10, $params, $config);

        $this->assertStringContainsString('Hero Title', $html);
        $this->assertStringContainsString('&quot;type&quot;:&quot;file-image&quot;', $html);
        $this->assertStringNotContainsString('暂无项目，点击下方按钮添加', $html);
    }

    public function testImageFieldUsesOnlyTypedMediaManagerSelectionForNewValues(): void
    {
        $renderer = new ParamTypeRenderer();
        $html = $renderer->renderField('background_image', [
            'type' => 'image',
            'label' => '背景图片',
            'media_options' => [
                'default_directory' => 'banner/promo',
            ],
        ], '', 687);

        $this->assertStringContainsString('w-param-image-select w-param-media-image-select', $html);
        $this->assertStringContainsString('data-default-dir="banner/promo"', $html);
        $this->assertStringNotContainsString('w-param-image-upload', $html);
        $this->assertStringNotContainsString('type="file"', $html);
        $this->assertStringContainsString('name="background_image"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringNotContainsString('placeholder="图片URL"', $html);

        $typedHtml = $renderer->renderField('background_image', [
            'type' => 'image',
            'label' => '背景图片',
        ], [
            'type' => 'file-image',
            'usage' => [
                'version' => 1,
                'asset_id' => '123e4567-e89b-42d3-a456-426614174000',
                'locale_code' => 'zh_Hans_CN',
                'alt' => '背景图',
            ],
        ], 687);
        $this->assertStringContainsString(
            '&quot;type&quot;:&quot;file-image&quot;',
            $typedHtml,
        );
        $this->assertStringContainsString(
            '&quot;asset_id&quot;:&quot;123e4567-e89b-42d3-a456-426614174000&quot;',
            $typedHtml,
        );
        $this->assertStringNotContainsString('&quot;disk_code&quot;', $typedHtml);
        $this->assertStringNotContainsString('&quot;object_key&quot;', $typedHtml);
        $this->assertStringNotContainsString('&quot;url&quot;', $typedHtml);

        $fallbackHtml = $renderer->renderField('fallback_image', [
            'type' => 'image',
            'label' => '回退图片',
            'media_options' => 'invalid',
        ], '', 687);
        $this->assertStringContainsString('data-default-dir="banner"', $fallbackHtml);
    }

    public function testClosingMediaManagerWithoutSelectionDoesNotEmitAnImageChange(): void
    {
        $script = file_get_contents(BP . '/app/code/Weline/Widget/view/statics/js/widget-param-types.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('var mediaSelectionChanged = false;', $script);
        $this->assertStringContainsString('mediaSelectionChanged = true;', $script);
        $this->assertStringContainsString('if (!mediaSelectionChanged) return;', $script);
    }

    public function testQuerySelectIsDeclarativeAndKeepsAsyncBehaviourInTheOwnedModule(): void
    {
        $renderer = new ParamTypeRenderer();
        $html = $renderer->renderField('product', [
            'type' => 'query_select',
            'label' => '产品',
            'query_provider' => 'catalog',
            'query_operation' => 'options',
            'value_key' => 'id',
            'label_key' => 'name',
        ], '42', 10);

        $this->assertStringContainsString('data-w-component="widget-query-select"', $html);
        $this->assertStringContainsString('data-current="42"', $html);
        $this->assertStringContainsString('class="w-input w-query-select__search"', $html);
        $this->assertStringContainsString('class="w-select w-query-select__value"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringNotContainsString('<script', $html);

        $script = file_get_contents(BP . '/app/code/Weline/Widget/view/statics/js/widget-param-types.js');
        $this->assertIsString($script);
        $this->assertStringContainsString("UI.define('widget-query-select'", $script);
        $this->assertStringContainsString("Symbol.for('weline.ui.definition.widget-query-select')", $script);
        $this->assertStringContainsString("'weline:ui:ready'", $script);
        $this->assertStringContainsString("Weline.load('api')", $script);
        $this->assertStringContainsString("api.resource(provider)", $script);
        $this->assertStringContainsString('var latestValue = String(root.dataset.current', $script);
        $this->assertStringNotContainsString('fetch(', $script);
    }

    public function testQuerySelectRejectsPrototypeAndInvalidOperationIdentifiers(): void
    {
        $renderer = new ParamTypeRenderer();
        $html = $renderer->renderField('unsafe', [
            'type' => 'query_select',
            'label' => 'Unsafe',
            'query_provider' => '__proto__',
            'query_operation' => 'constructor',
        ], '', 10);

        $this->assertStringContainsString('data-provider=""', $html);
        $this->assertStringContainsString('data-operation=""', $html);
        $this->assertStringNotContainsString('__proto__', $html);
        $this->assertStringNotContainsString('constructor', $html);
    }

    public function testParameterGroupsExposeKeyboardAndStateContracts(): void
    {
        $renderer = new ParamTypeRenderer();
        $html = $renderer->renderForm(10, [
            'title' => ['type' => 'string', 'label' => '标题', 'group' => 'basic'],
            'href' => ['type' => 'url', 'label' => '链接', 'group' => 'link'],
        ], [
            'title' => 'Hero',
            'href' => '/products',
        ]);

        $this->assertStringContainsString('type="button" class="w-param-group-title"', $html);
        $this->assertStringContainsString('data-w-param-group-toggle', $html);
        $this->assertStringContainsString('aria-expanded="true"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertMatchesRegularExpression('/aria-controls="([^"]+)"/', $html);
        $this->assertMatchesRegularExpression('/class="w-param-fields"[^>]+hidden/', $html);
        $this->assertStringNotContainsString('<h5 class="w-param-group-title">', $html);
    }

    public function testColorPresetsOnlyEmitSafeCssColorScalars(): void
    {
        $renderer = new ParamTypeRenderer();
        $html = $renderer->renderField('accent', [
            'type' => 'color',
            'label' => '强调色',
            'presets' => ['#3366ff', 'hsl(220 100% 60%)', 'red; background-image:url(https://invalid.example/x)'],
        ], '#3366ff', 10);

        $this->assertStringContainsString('--w-param-preset-color:#3366ff', $html);
        $this->assertStringContainsString('--w-param-preset-color:hsl(220 100% 60%)', $html);
        $this->assertStringNotContainsString('invalid.example', $html);
        $this->assertStringNotContainsString('background-image', $html);
    }

    public function testArraySchemaJsonCannotTerminateItsDataContainer(): void
    {
        $renderer = new ParamTypeRenderer();
        $html = $renderer->renderField('items', [
            'type' => 'array',
            'label' => 'Items',
            'item_schema' => [
                'title' => [
                    'type' => 'string',
                    'label' => '</script><img src=x onerror=alert(1)>',
                ],
            ],
        ], [], 10);

        $this->assertStringContainsString('type="application/json"', $html);
        $this->assertStringContainsString('\\u003C\\/script\\u003E', $html);
        $this->assertStringNotContainsString('</script><img', $html);

        $script = file_get_contents(BP . '/app/code/Weline/Widget/view/statics/js/widget-param-types.js');
        $this->assertIsString($script);
        $this->assertStringContainsString('function createItemElement(index, item)', $script);
        $this->assertStringNotContainsString('div.innerHTML = buildItemHtml', $script);
    }

    public function testSortableArrayUsesSharedAccessibleReorderComponent(): void
    {
        $renderer = new ParamTypeRenderer();
        $sortable = $renderer->renderField('items', [
            'type' => 'array',
            'label' => 'Items',
            'sortable' => true,
        ], ['One', 'Two'], 10);
        $fixed = $renderer->renderField('fixed_items', [
            'type' => 'array',
            'label' => 'Fixed items',
            'sortable' => false,
        ], ['One', 'Two'], 10);

        $this->assertStringContainsString('data-w-component="reorder-list"', $sortable);
        $this->assertStringContainsString('data-w-reorder-item', $sortable);
        $this->assertStringContainsString('data-w-reorder-handle', $sortable);
        $this->assertStringContainsString('aria-label="', $sortable);
        $this->assertStringNotContainsString('data-w-component="reorder-list"', $fixed);
        $this->assertStringNotContainsString('data-w-reorder-handle', $fixed);

        $script = file_get_contents(BP . '/app/code/Weline/Widget/view/statics/js/widget-param-types.js');
        $this->assertIsString($script);
        $this->assertStringContainsString("'weline:ui:reorder-list:change'", $script);
        $this->assertStringContainsString('notifyArrayValueChanged()', $script);
        $this->assertStringContainsString('function reindexArrayItemIdentity(itemEl, newIndex)', $script);
        $this->assertStringContainsString("node.setAttribute('data-array-index', String(newIndex))", $script);
        $this->assertStringContainsString("node.setAttribute('data-field', newFieldPrefix", $script);
    }

    public function testIconPickerStoresSemanticNamesAndUsesTheSharedSvgIconComponent(): void
    {
        $renderer = new ParamTypeRenderer();
        $html = $renderer->renderField('icon', [
            'type' => 'icon',
            'label' => '图标',
            'allow_custom' => true,
        ], 'settings', 10);

        $this->assertStringContainsString('data-w-component="icon-picker"', $html);
        $this->assertStringContainsString('<w-icon name="settings"', $html);
        $this->assertStringContainsString('data-w-icon-custom', $html);
        $this->assertStringContainsString('data-w-icon-apply', $html);
        $this->assertStringNotContainsString('<i class=', $html);
        $this->assertStringNotContainsString('style="display:none', $html);
    }
}
