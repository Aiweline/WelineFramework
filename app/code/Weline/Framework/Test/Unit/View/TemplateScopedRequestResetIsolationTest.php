<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\FiberOutputBuffer;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Runtime\WlsConcurrency;
use Weline\Framework\View\Template;

final class TemplateScopedRequestResetIsolationTest extends TestCase
{
    private ?Context $previousContext;
    private array $stateManagerState = [];

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        Context::leave();
        Runtime::setMode('wls');
        Template::resetInstance();
        foreach (['resetCallbacks', 'staticResets', 'singletonResets'] as $property) {
            $this->stateManagerState[$property] = (new \ReflectionProperty(StateManager::class, $property))->getValue();
        }
        StateManager::registerFrameworkResets();
        require_once BP . 'app/code/Weline/Server/bin/worker_runtime_common.php';
    }

    protected function tearDown(): void
    {
        Context::leave();
        Template::resetInstance();
        FiberOutputBuffer::uninstall();
        WlsConcurrency::setOtherSuspendedFiberCountProvider(null);
        foreach ($this->stateManagerState as $property => $value) {
            (new \ReflectionProperty(StateManager::class, $property))->setValue(null, $value);
        }
        Runtime::resetModeCache();
        if ($this->previousContext !== null) { Context::enter($this->previousContext); }
    }

    public function testNewWorkerRequestPreservesSuspendedPeerLayoutParameters(): void
    {
        $cart = new \Fiber(static function (): array {
            Context::enter(new Context());
            RequestContext::init();
            try {
                $template = Template::getInstance();
                $template->setData('meta', ['showHeader' => true, 'showFooter' => true, 'class' => 'cart-page']);
                \Fiber::suspend($template);
                $current = Template::getInstance();
                $html = $current->fetchSourceHtml('request-reset-layout.phtml', '<?php $meta=$this->getData("meta") ?? []; ?><?php if ($meta["showHeader"] ?? false): ?><header>HEADER</header><?php endif; ?><main>BODY</main><?php if ($meta["showFooter"] ?? false): ?><footer>FOOTER</footer><?php endif; ?>');
                return [$current, $html];
            } finally {
                Template::resetInstance();
                RequestContext::cleanup();
                Context::leave();
            }
        });
        $peer = new \Fiber(static function (): void {
            wlsSharedFiberRequestContextEnter(null, 'incoming-peer');
            RequestContext::init();
            Template::getInstance()->setData('meta', ['class' => 'completed-peer']);
            Template::resetInstance();
            RequestContext::cleanup();
            Context::leave();
        });

        $original = $cart->start();
        WlsConcurrency::setOtherSuspendedFiberCountProvider(static fn (): int => 1);
        $peer->start();
        $cart->resume();
        [$current, $html] = $cart->getReturn();
        self::assertSame('<header>HEADER</header><main>BODY</main><footer>FOOTER</footer>', $html);
        self::assertSame(\spl_object_id($original), \spl_object_id($current));
    }
}
