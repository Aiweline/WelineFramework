<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\View\Template;

final class TemplateScopedInstanceOrphanPurgeTest extends TestCase
{
    public function testRequestCleanupReleasesItsTemplateWithoutALaterGlobalReset(): void
    {
        $previousContext = Context::getCurrent();
        Runtime::setMode('wls');
        try {
            $fiber = new \Fiber(static function (): void {
                Context::enter(new Context());
                RequestContext::init();
                try {
                    $template = Template::getInstance();
                    $reference = \WeakReference::create($template);
                    unset($template);
                    RequestContext::cleanup();
                    \gc_collect_cycles();
                    self::assertTrue($reference->get() === null, '结束请求应释放自己的 Template 实例。');
                } finally {
                    Template::resetInstance();
                    Context::leave();
                }
            });

            $fiber->start();
        } finally {
            Context::leave();
            Template::resetInstance();
            Runtime::resetModeCache();
            if ($previousContext !== null) { Context::enter($previousContext); }
        }
    }
}
