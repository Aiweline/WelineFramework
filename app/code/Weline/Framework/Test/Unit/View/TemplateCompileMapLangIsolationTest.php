<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\View\Template;

/**
 * Regression guard for the v12 contract: locale must NOT partition compile
 * scope. Artifacts are lang-free (translations run at runtime via __()/
 * InlinePhraseMarker), so flipping w_env user.lang must reuse the same
 * compile map key — this is what collapses N_locales copies into one.
 */
final class TemplateCompileMapLangIsolationTest extends TestCase
{
    public function testCompileScopeMapKeyIgnoresWenvLangAfterEarlyFence(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        $previousContext = Context::getCurrent();
        if ($previousContext !== null) {
            Context::leave();
        }
        $context = new Context();
        Context::enter($context);
        try {
            foreach ([
                'area' => 'frontend',
                'website_id' => 0,
                'website_code' => 'default',
                'user.lang' => 'en_US',
                'user.currency' => 'USD',
            ] as $name => $value) {
                WelineEnv::set($name, $value, 'compile map lang fixture');
            }

            // Install fence while lang is still en_US, then flip w_env to hi_IN.
            $fence = StorefrontCacheKeyContext::currentOrRequestFence('compile-map-lang-fixture');
            self::assertSame('en_US', $fence->lang);

            $template = (new ReflectionClass(Template::class))->newInstanceWithoutConstructor();
            $events = $this->getMockBuilder(EventsManager::class)->disableOriginalConstructor()->onlyMethods(['dispatch'])->getMock();
            (new ReflectionProperty(Template::class, 'eventsManager'))->setValue($template, $events);
            (new ReflectionProperty(Template::class, 'view_dir'))->setValue($template, VAR_DIR . 'compile-map-lang-fixture/');

            $mapKey = new ReflectionMethod(Template::class, 'templateCompileScopeMapKey');
            $enKey = $mapKey->invoke($template);

            WelineEnv::set('user.lang', 'hi_IN', 'compile map lang fixture');
            // Fence stays en_US (request-local install-once); compile map must NOT move either — v12 is lang-free.
            self::assertSame('en_US', StorefrontCacheKeyContext::current()?->lang);
            $hiKey = $mapKey->invoke($template);

            self::assertSame($enKey, $hiKey, 'v12: one compiled artifact serves all locales; lang must not shard the compile map.');
            self::assertSame($hiKey, $mapKey->invoke($template));
        } finally {
            Context::leave();
            if ($previousContext !== null) {
                Context::enter($previousContext);
            }
        }
    }
}
