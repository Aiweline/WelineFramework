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
 * Regression: processViewFileCache / shared template-file-map must not reuse a
 * Hindi-baked &lt;lang&gt; compile under an English request when the storefront
 * fence was installed before route localization finished.
 */
final class TemplateCompileMapLangIsolationTest extends TestCase
{
    public function testCompileScopeMapKeyFollowsWenvLangEvenAfterEarlyFence(): void
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
            (new ReflectionProperty(Template::class, 'view_dir'))->setValue($template, BP . 'var/compile-map-lang-fixture/');

            $mapKey = new ReflectionMethod(Template::class, 'templateCompileScopeMapKey');
            $enKey = $mapKey->invoke($template);

            WelineEnv::set('user.lang', 'hi_IN', 'compile map lang fixture');
            // Fence stays en_US (request-local install-once), but compile map must move.
            self::assertSame('en_US', StorefrontCacheKeyContext::current()?->lang);
            $hiKey = $mapKey->invoke($template);

            self::assertNotSame($enKey, $hiKey, 'Baked <lang> path maps must not collide across w_env locales.');
            self::assertSame($hiKey, $mapKey->invoke($template));
        } finally {
            Context::leave();
            if ($previousContext !== null) {
                Context::enter($previousContext);
            }
        }
    }
}
