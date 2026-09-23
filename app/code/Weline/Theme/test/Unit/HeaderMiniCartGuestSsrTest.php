<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Phrase\EventDictionary;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Theme\Helper\HeaderCommerceData;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HeaderMiniCartGuestSsrTest extends TestCase
{
    protected function setUp(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId('mini-cart-ssr');
        State::setRequestLanguageOverride('en_US');
        WelineEnv::set('user.currency', 'USD');
        ObjectManager::setInstance(EventsManager::class, $this->getMockBuilder(EventsManager::class)
            ->disableOriginalConstructor()->onlyMethods(['dispatch'])->getMock());
        // 使用真实 Phrase 独占词典边界，使本例只验证购物车而不依赖词典数据库。
        (new \ReflectionMethod(EventDictionary::class, 'setStoredState'))->invoke(null, [
            'locale' => 'en_US', 'active' => true, 'mode' => EventDictionary::MODE_EXCLUSIVE,
            'owner' => 'mini-cart-test', 'hash' => 'mini-cart-test', 'words' => [], 'keyed_words' => [], 'layers' => [],
        ]);
        $cache = new StorefrontScopeHotCache();
        ObjectManager::setInstance(StorefrontScopeHotCache::class, $cache);
        // 已登录或访客的真实请求备忘都可能在编译前填好，不能进入共享片段。
        $cache->rememberForRequest('theme.header.cart_summary', 'live|20', static fn(): array => [
            'available' => true, 'is_demo' => false, 'is_empty' => false,
            'cart_count' => 2, 'subtotal' => 182.0, 'subtotal_formatted' => '$182.00',
            'currency' => 'USD', 'items' => [['name' => 'private-cart-line', 'qty' => 2, 'price' => 91]],
        ]);
        self::assertSame(2, HeaderCommerceData::resolveCartSummary(false, 20)['cart_count']);
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        Context::leave();
    }

    public function testStorefrontSharedSsrIgnoresAnAlreadyResolvedPrivateCart(): void
    {
        $this->assertNeutralLiveMarkup($this->render(false));
    }

    public function testEditorSharedSsrRemainsLiveAndNeutralWithoutDemoCart(): void
    {
        $this->assertNeutralLiveMarkup($this->render(true));
    }

    public function testFreshSharedSsrDoesNotExecuteThePrivateCartQuery(): void
    {
        // 删除已填充的请求备忘，验证首次编译也不进入个人查询。
        RequestContext::remove('framework.cache.request_memo.v1:' . hash('sha256', serialize(['theme.header.cart_summary', 'live|20'])));
        $calls = 0;
        $query = $this->getMockBuilder(FrameworkQueryService::class)
            ->disableOriginalConstructor()->onlyMethods(['execute'])->getMock();
        $query->method('execute')->willReturnCallback(static function () use (&$calls): array {
            ++$calls;
            return ['success' => true, 'data' => ['cart_count' => 2, 'subtotal' => 182, 'currency' => 'USD', 'items' => [['name' => 'private-cart-line']]]];
        });
        ObjectManager::setInstance(FrameworkQueryService::class, $query);
        $html = $this->render(false);
        self::assertSame(0, $calls, 'Shared SSR must not even query private cart data.');
        $this->assertNeutralLiveMarkup($html);
    }

    private function assertNeutralLiveMarkup(string $html): void
    {
        self::assertStringContainsString('data-cart-count="0"', $html);
        // WO-BUILD-OPS-02-HOME：空车共享 SSR 不得露出 $0.00 价签噪声
        self::assertStringContainsString('data-cart-subtotal=""', $html);
        self::assertStringNotContainsString('$0.00', $html);
        self::assertStringNotContainsString('$182.00', $html);
        self::assertStringNotContainsString('private-cart-line', $html);
        self::assertStringContainsString('data-demo-chrome="0"', $html);
        self::assertStringContainsString('data-preview-mode="0"', $html);
        self::assertStringContainsString('data-weline-load="miniCartIcon,miniCartExtras"', $html);
        self::assertStringContainsString('data-mini-cart-items', $html);
        self::assertStringContainsString('data-editor-interactive', $html);
    }

    private function render(bool $preview): string
    {
        // 仅替代模板宿主提供的配置与静态地址，执行完整生产 PHP 模板。
        $host = new class($preview) {
            public function __construct(private bool $preview) {}
            public function getData(string $key): mixed { return $key === 'preview_mode' ? $this->preview : null; }
            public function getStaticUrl(string $path): string { return '/static/placeholder.png'; }
            public function render(string $file): string {
                ob_start();
                try { require $file; return (string)ob_get_contents(); }
                finally { ob_end_clean(); }
            }
        };
        return $host->render(BP . 'app/code/Weline/Theme/view/theme/frontend/widgets/header/mini-cart-icon/default.phtml');
    }
}
