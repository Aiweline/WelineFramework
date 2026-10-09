<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\View\Template;

final class TemplateCompileOriginScopeTest extends TestCase
{
    public function testCompileDirectoryUsesPlainScopeWithoutCtxHashAndIsolatesOrigin(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        $previousContext = Context::getCurrent();
        $previousGet = $_GET;
        if ($previousContext !== null) {
            Context::leave();
        }
        $context = new Context();
        Context::enter($context);
        try {
            $_GET = [];
            foreach (['area' => 'frontend', 'website_id' => 27, 'website_code' => 'origin_shop', 'user.lang' => 'en_US', 'user.currency' => 'USD'] as $name => $value) {
                WelineEnv::set($name, $value, 'compile origin fixture');
            }
            WelineEnv::set('website_url', 'https://shop.test:9555/store', 'compile origin fixture');
            $template = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor();
            $events = $this->getMockBuilder(EventsManager::class)->disableOriginalConstructor()->onlyMethods(['dispatch'])->getMock();
            (new ReflectionProperty(Template::class, 'eventsManager'))->setValue($template, $events);
            $method = new ReflectionMethod(Template::class, 'stableTemplateCompileDirectory');
            $directory = BP . 'var/origin-fixture-compile/';
            $first = $method->invoke($template, $directory);

            self::assertStringContainsString('frontend_w27_origin_shop_en_US_USD', $first);
            self::assertStringContainsString('_o_shop_test_9555', $first);
            self::assertStringNotContainsString('_ctx_', $first, 'v7 plain scope must not append ctx_ hash directories');

            // Legacy v6 ctx_ leaf must remap to current plain directory (idempotent strip).
            $legacyScope = [
                'compile_scope_schema' => 'context-env-v6-compile-map-lang',
                'area' => 'frontend',
                'website_id' => '27',
                'website_code' => 'origin_shop',
                'lang' => 'en_US',
                'currency' => 'USD',
                'website_url' => 'https://shop.test:9555/store',
                'theme' => 'area:frontend',
                'hooks_registry' => (new ReflectionMethod(Template::class, 'hooksRegistryCompileDigest'))->invoke(null),
            ];
            $legacyScopeKey = substr(hash('sha256', json_encode($legacyScope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32);
            $legacyDirectory = $directory . 'frontend_w27_origin_shop_en_US_USD_ctx_' . $legacyScopeKey . DS;
            self::assertSame($first, $method->invoke($template, $legacyDirectory), 'Cached ctx_ directory must resolve to the current plain format.');
            self::assertSame($first, $method->invoke($template, $first), 'Existing plain scope leaf stays idempotent.');

            WelineEnv::set('website_url', 'https://shop.test:19655/store', 'compile origin fixture');
            $second = $method->invoke($template, $directory);
            self::assertNotSame($first, $second, 'Different origins must not share baked absolute URL directories.');
            self::assertStringContainsString('_o_shop_test_19655', $second);

            $context->set('meta.request_id', 'another-request');
            $context->set('input.uri', '/another-path');
            $context->set('input.server.REQUEST_URI', '/another-path');
            $_GET = ['area' => 'backend', 'website_id' => '99', 'website_code' => 'query_site', 'user.lang' => 'fr_FR', 'user.currency' => 'EUR', 'website.url' => 'https://query.test', 'website_url' => 'https://query.test'];
            $context->set('input.query', $_GET);
            self::assertSame('backend', \w_env_get('area'), 'The fixture uses the real query accessor that caused the regression.');
            self::assertSame('frontend', \w_env('area'));
            self::assertSame($second, $method->invoke($template, $directory), 'Query values, request IDs and request paths cannot replace the frozen compile dimensions.');

            WelineEnv::set('website_url', 'https://shop.test:9555/store', 'compile origin fixture');
            self::assertSame($first, $method->invoke($template, $directory), 'Returning to the original origin reuses its original directory.');

            $oldMappingKey = KeyBuilder::environmentHash([
                'scope' => 'template-file-map',
                'compile_scope_schema' => 'context-env-v6-compile-map-lang',
                'runtime_os' => PHP_OS_FAMILY,
                'runtime_root' => str_replace('\\', '/', rtrim(BP, '/\\')),
                'hooks_registry' => (new ReflectionMethod(Template::class, 'hooksRegistryCompileDigest'))->invoke(null),
            ]);
            $mapping = new ReflectionMethod(Template::class, 'viewEnvironmentCacheSuffix');
            self::assertNotSame($oldMappingKey, $mapping->invoke($template, 'template-file-map'), 'Old path mappings must miss after schema bump.');

            $compileMapKey = new ReflectionMethod(Template::class, 'templateCompileScopeMapKey');
            WelineEnv::set('user.lang', 'en_US', 'compile origin fixture');
            $enMap = $compileMapKey->invoke($template);
            WelineEnv::set('user.lang', 'hi_IN', 'compile origin fixture');
            $hiMap = $compileMapKey->invoke($template);
            self::assertNotSame($enMap, $hiMap, 'Compile path maps must shard by live w_env lang so baked <lang> cannot cross locales.');
            WelineEnv::set('user.lang', 'en_US', 'compile origin fixture');

            // v8：站点地址维 = 文档公开源；站柜登记 url 与请求 Host 不一致时按文档源分区。
            $dimsMethod = new ReflectionMethod(Template::class, 'templateCompileScopeDimensions');
            $request = $this->getMockBuilder(Request::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getBaseHost'])
                ->getMock();
            $request->method('getBaseHost')->willReturn('https://pf14955e2.test.weline.com');
            (new ReflectionProperty(Template::class, 'request'))->setValue($template, $request);
            WelineEnv::set('website_url', 'https://p05113ef3.test.weline.com', 'compile site-address fixture');
            $dims = $dimsMethod->invoke($template);
            self::assertSame('https://pf14955e2.test.weline.com', $dims['site_address']);
            self::assertSame('https://p05113ef3.test.weline.com', $dims['website_url']);
            $addressDir = $method->invoke($template, $directory);
            self::assertStringContainsString('_o_pf14955e2_test_weline_com', $addressDir);
            self::assertStringNotContainsString('_o_p05113ef3_test_weline_com', $addressDir);
        } finally {
            $_GET = $previousGet;
            Context::leave();
            if ($previousContext !== null) {
                Context::enter($previousContext);
            }
        }
    }
}
