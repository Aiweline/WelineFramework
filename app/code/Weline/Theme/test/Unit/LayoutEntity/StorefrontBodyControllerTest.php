<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

final class StorefrontBodyControllerTest extends TestCase
{
    use ResolvedPhtmlFixture;

    public static function controllers(): array
    {
        return [
            'cart' => [CartBodyControllerProbe::class, 'Weline_Cart::templates/frontend/cart/index.phtml', 'cart'],
            'checkout' => [CheckoutBodyControllerProbe::class, 'Weline_Checkout::frontend/checkout/index.phtml', 'checkout'],
            'checkout frontend' => [CheckoutFrontendBodyControllerProbe::class, 'Weline_Checkout::frontend/checkout/index.phtml', 'checkout'],
        ];
    }

    #[DataProvider('controllers')]
    public function testIndexAssignsBusinessDataAndDispatchesOneLayoutFetch(string $class, string $body, string $type): void
    {
        $controller = new $class();
        self::assertSame('LAYOUT-EVENT-RESULT', $controller->index());
        self::assertSame([$body], $controller->layoutCalls);
        self::assertSame([], $controller->plainCalls);
        self::assertTrue($controller->assigned['meta']['showHeader']);
        self::assertTrue($controller->assigned['meta']['showFooter']);
        self::assertArrayNotHasKey('content', $controller->assigned['meta']);
        self::assertSame([], $controller->assigned[$type === 'cart' ? 'items' : 'checkout_items']);
        if ($type === 'cart') { self::assertTrue($controller->assigned['cart']['is_empty']); }
        else { self::assertSame('USD', $controller->assigned['checkout_currency']); }
    }

    public function testPlainFrontendTemplateOnlyMergesDataAndRendersItsSource(): void
    {
        $template = \Weline\Framework\View\Template::getInstance();
        $template->pinSource('plain-controller-source.phtml', '<b><?= htmlspecialchars($this->getData("value")) ?></b>');
        $retired = new class {
            public int $calls = 0;
            public function ensure(string $html): string { ++$this->calls; return 'RETIRED-HEALER-' . $html; }
        };
        ObjectManager::setInstance(\Weline\Theme\Service\StorefrontSsrChromeHealer::class, $retired);
        $controller = new class($template) extends \Weline\Framework\App\Controller\FrontendController {
            public function __construct(private \Weline\Framework\View\Template $sourceTemplate) {}
            protected function getTemplate(): \Weline\Framework\View\Template { return $this->sourceTemplate; }
            public function render(): string { return $this->template('plain-controller-source.phtml', ['value' => 'ASSIGNED']); }
        };
        self::assertSame('<b>ASSIGNED</b>', $controller->render());
        self::assertSame(0, $retired->calls);
    }
}

trait BodyControllerRenderProbe
{
    public array $assigned = [];
    public array $layoutCalls = [];
    public array $plainCalls = [];
    public function __construct() { $this->request = ObjectManager::getInstance(Request::class); }
    protected function assign(array|string $tpl_var, mixed $value = null): static
    {
        $this->assigned = array_replace($this->assigned, is_array($tpl_var) ? $tpl_var : [$tpl_var => $value]);
        return $this;
    }
    protected function fetch(string $fileName = '', array $data = []): mixed
    {
        $this->layoutCalls[] = $fileName;
        return 'LAYOUT-EVENT-RESULT';
    }
    protected function template(string $fileName, array $data = []): string
    {
        $this->plainCalls[] = $fileName;
        return 'PLAIN-RESULT';
    }
}

final class CartBodyControllerProbe extends \Weline\Cart\Controller\Index { use BodyControllerRenderProbe; }
final class CheckoutBodyControllerProbe extends \Weline\Checkout\Controller\Index { use BodyControllerRenderProbe; }
final class CheckoutFrontendBodyControllerProbe extends \Weline\Checkout\Controller\Frontend\Checkout { use BodyControllerRenderProbe; }
