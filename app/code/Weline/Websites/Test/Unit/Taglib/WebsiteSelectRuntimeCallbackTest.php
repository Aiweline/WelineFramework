<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;
use Weline\Websites\Taglib\WebsiteSelect;

final class WebsiteSelectRuntimeCallbackTest extends TestCase
{
    public function testRuntimeCallbackEmitsMarkupWithoutPhpSource(): void
    {
        $bag = [
            'websiteSelectValue' => '0',
            'websiteSelectDisplay' => 'Default Site',
            'websiteSelectOptionsJson' => json_encode(
                [['value' => '0', 'label' => 'Default Site', 'meta' => 'default']],
                JSON_UNESCAPED_UNICODE,
            ) ?: '[]',
        ];
        $template = $this->createMock(Template::class);
        $template->method('getData')->willReturnCallback(
            static function (string $key = '', $index = null) use (&$bag): mixed {
                unset($index);
                if ($key === '') {
                    return $bag;
                }

                return $bag[$key] ?? null;
            },
        );

        $html = WebsiteSelect::runtimeCallback()(
            $template,
            'tag-self-close',
            [
                'id' => 'runtime-website-select',
                'name' => 'website_id',
                'value' => 'websiteSelectValue',
                'display' => 'websiteSelectDisplay',
                'options' => 'websiteSelectOptionsJson',
                'multiple' => 'false',
                'empty-label' => '请选择站点',
                'placeholder' => '搜索站点',
                'class' => 'w-test-website-select',
            ],
            '',
        );

        self::assertStringContainsString('data-component="website-select"', $html);
        self::assertStringContainsString('runtime-website-select_wrapper', $html);
        self::assertStringContainsString('Default Site', $html);
        self::assertStringContainsString('value="0"', $html);
        self::assertStringNotContainsString('<?php', $html);
        self::assertStringNotContainsString('Weline_Taglib_resolve', $html);
        self::assertStringNotContainsString('$Taglib__', $html);
    }

    public function testCompileCallbackStillPrefacesAttributeResolverPhp(): void
    {
        $html = WebsiteSelect::callback()(
            'tag-self-close',
            [],
            [''],
            [
                'id' => 'compile-website-select',
                'name' => 'website_ids',
                'value' => 'websiteSelectValue',
                'options' => 'websiteSelectOptionsJson',
            ],
        );

        self::assertStringContainsString('<?php', $html);
        self::assertStringContainsString('Weline_Taglib_resolve', $html);
        self::assertStringContainsString('WebsiteSelect::buildMarkup', $html);
    }

    public function testClassExposesRuntimeCallback(): void
    {
        self::assertTrue(method_exists(WebsiteSelect::class, 'runtimeCallback'));
    }
}
