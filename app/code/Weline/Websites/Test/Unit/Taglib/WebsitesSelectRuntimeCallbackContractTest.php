<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Template;
use Weline\Websites\Taglib\BuildSite;
use Weline\Websites\Taglib\ChannelSelect;
use Weline\Websites\Taglib\DomainSelect;
use Weline\Websites\Taglib\RegistrarSelect;
use Weline\Websites\Taglib\SearchableCodeSelect;
use Weline\Websites\Taglib\StoreSelect;
use Weline\Websites\Taglib\WebsiteForm;
use Weline\Websites\Taglib\WebsiteSelect;

final class WebsitesSelectRuntimeCallbackContractTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    private static function selectClasses(): array
    {
        return [
            WebsiteSelect::class,
            StoreSelect::class,
            ChannelSelect::class,
            RegistrarSelect::class,
            DomainSelect::class,
            WebsiteForm::class,
            BuildSite::class,
        ];
    }

    public function testSiblingSelectTaglibsExposeRuntimeCallback(): void
    {
        foreach (self::selectClasses() as $class) {
            self::assertTrue(
                \method_exists($class, 'runtimeCallback'),
                $class . ' must expose runtimeCallback',
            );
        }
    }

    public function testStoreSelectRuntimeEmitsHtmlWithoutPhpSource(): void
    {
        $bag = [
            'storeValue' => 'main',
            'storeOptions' => \json_encode([
                ['value' => 'main', 'label' => 'Main Store', 'meta' => 'main'],
            ], JSON_UNESCAPED_UNICODE) ?: '[]',
        ];
        $template = $this->mockTemplate($bag);

        $html = StoreSelect::runtimeCallback()(
            $template,
            'tag-self-close',
            [
                'id' => 'runtime-store-select',
                'name' => 'store_code',
                'value' => 'storeValue',
                'options' => 'storeOptions',
                'allow-empty' => 'true',
                'empty-label' => 'Website 层',
            ],
            '',
        );

        self::assertStringContainsString('data-component="store-select"', $html);
        self::assertStringContainsString('runtime-store-select_wrapper', $html);
        self::assertStringContainsString('Main Store', $html);
        self::assertStringNotContainsString('<?php', $html);
        self::assertStringNotContainsString('Weline_Taglib_resolve', $html);
        self::assertStringNotContainsString('$Taglib__', $html);
    }

    public function testChannelSelectRuntimeEmitsHtmlWithoutPhpSource(): void
    {
        $bag = [
            'channelValue' => 'web',
            'channelOptions' => \json_encode([
                ['value' => 'web', 'label' => 'Web Channel', 'meta' => 'web'],
            ], JSON_UNESCAPED_UNICODE) ?: '[]',
        ];
        $template = $this->mockTemplate($bag);

        $html = ChannelSelect::runtimeCallback()(
            $template,
            'tag-self-close',
            [
                'id' => 'runtime-channel-select',
                'name' => 'channel_code',
                'value' => 'channelValue',
                'options' => 'channelOptions',
            ],
            '',
        );

        self::assertStringContainsString('data-component="channel-select"', $html);
        self::assertStringContainsString('Web Channel', $html);
        self::assertStringNotContainsString('<?php', $html);
        self::assertStringNotContainsString('$Taglib__', $html);
    }

    public function testRegistrarSelectRuntimeEmitsHtmlWithoutPhpSource(): void
    {
        $bag = [
            'registrarValue' => 'godaddy',
            'registrarOptions' => \json_encode([
                ['value' => 'godaddy', 'label' => 'GoDaddy', 'group' => 'US'],
            ], JSON_UNESCAPED_UNICODE) ?: '[]',
        ];
        $template = $this->mockTemplate($bag);

        $html = RegistrarSelect::runtimeCallback()(
            $template,
            'tag-self-close',
            [
                'id' => 'runtime-registrar-select',
                'name' => 'registrar_id',
                'value' => 'registrarValue',
                'options' => 'registrarOptions',
                'empty-label' => '请选择服务商',
            ],
            '',
        );

        self::assertStringContainsString('runtime-registrar-select_wrapper', $html);
        self::assertStringNotContainsString('<?php', $html);
        self::assertStringNotContainsString('$Taglib__', $html);
    }

    public function testDomainSelectBuildMarkupEmitsHtmlWithoutPhpSource(): void
    {
        $html = DomainSelect::buildMarkup([
            'id' => 'runtime-domain-select',
            'name' => 'domain',
            'value' => '',
            'display' => '请选择域名',
            'multiple' => 'false',
            'site-ready-only' => 'true',
            'url' => 'websites/backend/api/domain-pool',
        ]);

        self::assertStringContainsString('runtime-domain-select_wrapper', $html);
        self::assertStringContainsString('请选择域名', $html);
        self::assertStringNotContainsString('<?php', $html);
        self::assertStringNotContainsString('$Taglib__', $html);
        self::assertStringNotContainsString('Weline_Taglib_resolve', $html);
    }

    public function testDomainSelectRuntimeCallbackResolvesScopeAttrs(): void
    {
        $bag = [
            'domainValue' => 'example.com',
            'domainDisplay' => 'example.com',
        ];
        $template = $this->mockTemplate($bag);

        $html = DomainSelect::runtimeCallback()(
            $template,
            'tag-self-close',
            [
                'id' => 'rt-domain',
                'name' => 'domain',
                'value' => 'domainValue',
                'display' => 'domainDisplay',
            ],
            '',
        );

        self::assertStringContainsString('rt-domain_wrapper', $html);
        self::assertStringContainsString('example.com', $html);
        self::assertStringNotContainsString('<?php', $html);
    }

    public function testSearchableCodeSelectCompileStillPrefacesAttributeResolverPhp(): void
    {
        $php = SearchableCodeSelect::render([
            'id' => 'compile-store',
            'name' => 'store_code',
            'class' => '',
            'style' => '',
            'form' => '',
            'on-change' => '',
            'allow-empty' => true,
            'clearable' => true,
            'component' => 'store-select',
            'api-ns' => 'WelineStoreSelect',
            'default-empty-label' => 'Website 层',
            'default-placeholder' => '搜索',
            'not-found' => '未找到',
            'attributes' => [
                'id' => 'compile-store',
                'value' => 'storeValue',
                'options' => 'storeOptions',
            ],
        ]);

        self::assertStringContainsString('<?php', $php);
        self::assertStringContainsString('SearchableCodeSelect::buildMarkup', $php);
    }

    public function testBuildSiteExposesRuntimeCallback(): void
    {
        self::assertTrue(\method_exists(BuildSite::class, 'runtimeCallback'));
        self::assertTrue(\method_exists(BuildSite::class, 'buildMarkup'));
    }

    public function testWebsiteFormExposesRuntimeCallback(): void
    {
        self::assertTrue(\method_exists(WebsiteForm::class, 'runtimeCallback'));
        self::assertTrue(\method_exists(WebsiteForm::class, 'buildMarkup'));
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function mockTemplate(array $bag): Template
    {
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

        return $template;
    }
}
