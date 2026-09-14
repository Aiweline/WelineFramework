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
use Weline\Framework\View\Template;

final class TemplateCompileOriginScopeTest extends TestCase
{
    public function testCompileDirectoryUsesEffectiveOriginAndScopeWithoutQueryOrRequestIdentity(): void
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

            // v2 only changed path mappings; its physical directory had no format identity.
            $legacyScope = [
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
            self::assertNotSame($legacyDirectory, $first, 'The old physical directory may contain baked URLs and must not be reused.');
            self::assertSame($first, $method->invoke($template, $legacyDirectory), 'A cached v2 directory must resolve to the current format.');

            // v3 编译产物可能固化个人购物车，目录和路径映射都必须迁移。
            $v3Scope = ['compile_scope_schema' => 'context-env-v3'] + $legacyScope;
            $v3ScopeKey = substr(hash('sha256', json_encode($v3Scope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32);
            $v3Directory = $directory . 'frontend_w27_origin_shop_en_US_USD_ctx_' . $v3ScopeKey . DS;
            self::assertNotSame($v3Directory, $first, 'Old compiled cart summaries must not be reused.');
            self::assertSame($first, $method->invoke($template, $v3Directory));

            WelineEnv::set('website_url', 'https://shop.test:19655/store', 'compile origin fixture');
            $second = $method->invoke($template, $directory);
            self::assertNotSame($first, $second, 'Compiled output can embed absolute URLs and must use the effective origin.');
            self::assertStringContainsString('frontend_w27_origin_shop_en_US_USD_ctx_', $first, 'Scope dimensions must come from the real environment, not query defaults.');
            self::assertSame($second, $method->invoke($template, $second), 'Existing scoped directory suffix stays idempotent.');

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
                'compile_scope_schema' => 'context-env-v3',
                'runtime_os' => PHP_OS_FAMILY,
                'runtime_root' => str_replace('\\', '/', rtrim(BP, '/\\')),
                'hooks_registry' => (new ReflectionMethod(Template::class, 'hooksRegistryCompileDigest'))->invoke(null),
            ]);
            $mapping = new ReflectionMethod(Template::class, 'viewEnvironmentCacheSuffix');
            self::assertNotSame($oldMappingKey, $mapping->invoke($template, 'template-file-map'), 'Old shared path mappings must not point back to directories compiled with the query accessor.');
        } finally {
            $_GET = $previousGet;
            Context::leave();
            if ($previousContext !== null) {
                Context::enter($previousContext);
            }
        }
    }
}
