<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;

/**
 * 多语言切换的并发（Fiber）用例：验证模型/上下文在并发下不串语言。
 *
 * 覆盖三条真实并发面：
 * 1. 两个 Fiber 交错切换语言，各自读回自己的语言（上下文隔离）；
 * 2. 同一类在两个 Fiber 里经 ObjectManager 取到的实例互相独立（实例隔离）；
 * 3. 两个请求上下文里同一模型实例各自 holder 语言不同，模型数据不串。
 */
final class ModelLanguageConcurrencyTest extends TestCase
{
    private ?Context $mainContext = null;

    /**
     * WLS 为每个请求 Fiber 建独立 Context（RequestContext::init() 在 Fiber 内执行）。
     * 生产不含“Fiber 内无 Context 回落到主通道”的路径，本用例也不允许退化成该路径。
     */
    private static function seedFiberContext(): void
    {
        // 必须在 Fiber 内 enter（Context::enter 按 Fiber::getCurrent() 落桶），
        // 不能在主栈 enter 后再 leave。
        Context::enter(new Context());
        RequestContext::set('__probe_seeded__', 1);
    }

    protected function setUp(): void
    {
        Runtime::setMode('wls');
        if (Context::hasCurrent()) {
            Context::leave();
        }
        RequestContext::cleanup();
        ObjectManager::clearInstances();
        $this->mainContext = new Context();
        Context::enter($this->mainContext);
    }

    protected function tearDown(): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        RequestContext::cleanup();
        ObjectManager::clearInstances();
        Runtime::resetModeCache();
        $this->mainContext = null;
    }

    public function testLanguageOverrideDoesNotLeakAcrossConcurrentFibers(): void
    {
        $seen = [];

        $makeFiber = static function (string $lang) use (&$seen): \Fiber {
            return new \Fiber(static function () use ($lang, &$seen): void {
                self::seedFiberContext();
                WelineEnv::setLang($lang);
                \Fiber::suspend();
                $seen[$lang] = WelineEnv::getLang();
            });
        };

        $fiberZh = $makeFiber('zh_Hans_CN');
        $fiberEn = $makeFiber('en_US');

        $fiberZh->start();
        $fiberEn->start();
        $fiberZh->resume();
        $fiberEn->resume();

        self::assertSame('zh_Hans_CN', $seen['zh_Hans_CN'] ?? null);
        self::assertSame('en_US', $seen['en_US'] ?? null);
    }

    public function testModelInstanceIsFiberScopedUnderObjectManager(): void
    {
        $instanceA = null;
        $instanceB = null;

        $fiberA = new \Fiber(static function () use (&$instanceA): void {
            $instanceA = ObjectManager::getInstance(ConcurrentLocaleProbeModel::class);
            \Fiber::suspend();
        });
        $fiberB = new \Fiber(static function () use (&$instanceB): void {
            $instanceB = ObjectManager::getInstance(ConcurrentLocaleProbeModel::class);
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();

        self::assertInstanceOf(ConcurrentLocaleProbeModel::class, $instanceA);
        self::assertInstanceOf(ConcurrentLocaleProbeModel::class, $instanceB);
        self::assertNotSame($instanceA, $instanceB, 'ObjectManager 必须在不同 Fiber 内提供独立模型实例');
    }

    public function testConcurrentLocaleSwitchKeepsModelRowsSeparated(): void
    {
        $resultA = null;
        $resultB = null;

        $fiberA = new \Fiber(static function () use (&$resultA): void {
            self::seedFiberContext();
            WelineEnv::setLang('zh_Hans_CN');
            $model = ObjectManager::getInstance(ConcurrentLocaleProbeModel::class);
            $model->fillFromRow(['id' => 1, 'title' => '中文标题']);
            \Fiber::suspend();
            $resultA = [$model->getData('title'), WelineEnv::getLang()];
        });

        $fiberB = new \Fiber(static function () use (&$resultB): void {
            self::seedFiberContext();
            WelineEnv::setLang('en_US');
            $model = ObjectManager::getInstance(ConcurrentLocaleProbeModel::class);
            $model->fillFromRow(['id' => 1, 'title' => 'English title']);
            $resultB = [$model->getData('title'), WelineEnv::getLang()];
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();

        self::assertSame(['中文标题', 'zh_Hans_CN'], $resultA);
        self::assertSame(['English title', 'en_US'], $resultB);
    }
}
