<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Context;
use Weline\Framework\Http\ResponseObservabilityPolicy;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\WlsRuntime;
use Weline\Server\Security\WorkerPolicyDecision;
use Weline\Server\Service\WorkerFullPageCacheFastPath;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('PUB') || \define('PUB', BP . 'pub' . DS);
\defined('VAR_DIR') || \define('VAR_DIR', BP . 'var' . DS);
require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

final class WorkerFullPageCacheFastPathTest extends TestCase
{
    private WorkerReceiptSqliteAuthority $authority;
    private \Weline\Framework\Cache\StorefrontCacheKeyContextResolver $receiptResolver;

    private array $originalIsolatedServices = [];

    protected function setUp(): void
    {
        $isolatedServices = [
            \Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface::class
                => $this->createStub(\Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface::class),
            \Weline\Framework\Event\EventsManager::class
                => $this->createStub(\Weline\Framework\Event\EventsManager::class),
        ];
        foreach ($isolatedServices as $class => $service) {
            $this->originalIsolatedServices[$class] = \Weline\Framework\Manager\ObjectManager::_getInstance($class);
            \Weline\Framework\Manager\ObjectManager::setInstance($class, $service);
        }

        $this->authority = new WorkerReceiptSqliteAuthority();
        $this->receiptResolver = new \Weline\Framework\Cache\StorefrontCacheKeyContextResolver(
            $this->authority, new \Weline\Framework\Cache\Namespace\NamespacePath(),
        );
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('worker-receipt-fixture');
        RequestContext::installScopeIdentity(ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL));
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');
        $this->receiptResolver->freezeCurrent();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalIsolatedServices as $class => $service) {
            if ($service !== null) {
                \Weline\Framework\Manager\ObjectManager::setInstance($class, $service);
            } else {
                \Weline\Framework\Manager\ObjectManager::removeInstance($class);
            }
        }

        if (Context::hasCurrent()) { RequestContext::cleanup(); Context::leave(); }
        FullPageCacheCoordinator::clearProcessCache();
    }

    private function coordinator(?CachePoolInterface $pool = null): FullPageCacheCoordinator
    {
        return new FullPageCacheCoordinator(cachePool: $pool, storefrontCacheKeyContextResolver: $this->receiptResolver);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function exactHomepageReceipt(string $cacheKey, array $overrides = []): array
    {
        $context = StorefrontCacheKeyContext::current();
        self::assertInstanceOf(StorefrontCacheKeyContext::class, $context);

        return $overrides + [
            'version' => 2,
            'full_uri' => 'https://example.test/',
            'method' => 'GET',
            'cookie_header' => '',
            'identity_digest' => \hash('sha256', $cacheKey),
            'cache_key' => $cacheKey,
            'scope_identity' => $context->scopeIdentity->toArray(),
            'namespace_fingerprint' => $context->namespaceFingerprint,
            'lang' => $context->lang,
            'default_locale' => $context->defaultLocale,
            'translation_locales' => $context->translationLocales,
        ];
    }

    public function testMissedTranslationBroadcastRejectsWarmReceiptAtNextAuthorityClock(): void
    {
        $authority = new WorkerReceiptSqliteAuthority();
        $resolver = new \Weline\Framework\Cache\StorefrontCacheKeyContextResolver(
            $authority,
            new \Weline\Framework\Cache\Namespace\NamespacePath(),
        );
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
        RequestContext::setId('receipt-prime');
        RequestContext::installScopeIdentity(ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL));
        RequestContext::setWelineUserLang('zh_Hans_CN');
        RequestContext::setWelineUserCurrency('CNY');
        $resolver->freezeCurrent();
        $coordinator = new FullPageCacheCoordinator(storefrontCacheKeyContextResolver: $resolver);
        $cacheKey = '0123456789abcdef';
        $receipt = (new \ReflectionMethod($coordinator, 'buildInternalHomepageWarmupReceipt'))->invoke(
            $coordinator, 'https://example.test/', ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'], $cacheKey,
        );
        self::assertIsArray($receipt);
        (new \ReflectionMethod($coordinator, 'setProcessCachedPayload'))->invoke($coordinator, $cacheKey, [
            KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
            KeyBuilder::UNIFIED_CACHE_FPC_KEY => '<html><body>old translation</body></html>',
            KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => ['Content-Type: text/html; charset=utf-8'],
            'fpc_variant' => ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
            'fpc_html_urls_validated' => true,
            'fpc_expires_at' => microtime(true) + 60,
        ]);
        $runtime = new WlsRuntime();
        (new \ReflectionProperty($runtime, 'homepageCacheWarmupReceipt'))->setValue($runtime, $receipt);
        RequestContext::cleanup();
        Context::leave();
        $decision = WorkerPolicyDecision::allow('127.0.0.1', 'GET', 'HTTP/1.1', '/', '/',
            ['host' => 'example.test', 'accept' => 'text/html'], '', str_repeat('a', 64), false,
            WorkerPolicyDecision::CACHE_FPC_PROCESS_L1);
        $fastPath = new WorkerFullPageCacheFastPath($coordinator, $runtime, false);
        try {
            $before = $authority->clockReads;
            self::assertIsArray($fastPath->lookup($decision, 'https'));
            $assetDecision = WorkerPolicyDecision::allow('127.0.0.1', 'GET', 'HTTP/1.1', '/assets/app.js', '/assets/app.js',
                ['host' => 'example.test', 'accept' => '*/*'], '', str_repeat('b', 64), false,
                WorkerPolicyDecision::CACHE_FPC_PROCESS_L1);
            self::assertNull($fastPath->lookup($assetDecision, 'https'));
            self::assertSame($before + 1, $authority->clockReads, '没有首页回执的资源不读取数据库版本。');
            $authority->bump('website/another/catalog');
            self::assertIsArray($fastPath->lookup($decision, 'https'), '无关网站变更不能清掉当前首页。');
            $authority->bump('global/i18n'); // 模拟另一 Worker 提交，刻意不发送广播。
            self::assertNull($fastPath->lookup($decision, 'https'), '旧预热回执不能越过下一请求的权威版本检查。');
            self::assertSame($before + 3, $authority->clockReads, '每个早期请求只检查一次 @clock。');
            self::assertFalse(Context::hasCurrent(), '早期临时 Context 必须释放。');
        } finally {
            FullPageCacheCoordinator::clearProcessCache();
            if (Context::hasCurrent()) { RequestContext::cleanup(); Context::leave(); }
        }
    }

    public function testLegacyReceiptAndUncommittedReceiptCannotBeShared(): void
    {
        $coordinator = $this->coordinator(new WorkerFastPathCountingCachePool());
        $builder = new \ReflectionMethod($coordinator, 'buildInternalHomepageWarmupReceipt');
        $receipt = $builder->invoke($coordinator, 'https://example.test/', [], '0123456789abcdef');
        self::assertIsArray($receipt);
        $legacy = $receipt;
        $legacy['version'] = 1;
        unset($legacy['scope_identity'], $legacy['namespace_fingerprint']);
        self::assertFalse($coordinator->warmProcessCacheForInternalReceipt($legacy));
        self::assertNull($coordinator->getFormattedProcessCachedResponseForInternalReceipt($legacy));

        defined('IS_WIN') || define('IS_WIN', PHP_OS_FAMILY === 'Windows');
        defined('PHP_CS') || define('PHP_CS', false);
        $path = tempnam(sys_get_temp_dir(), 'weline-fpc-receipt-txn-');
        $connector = new \Weline\Framework\Database\Connection\Adapter\Sqlite\Connector(
            new \Weline\Framework\Database\DbManager\ConfigProvider([
                'type' => 'sqlite', 'database' => '', 'path' => $path, 'persistent' => false,
            ]),
        );
        try {
            $connector->beginTransaction();
            self::assertSame(1, \Weline\Framework\Database\TransactionContext::activeTransactionConnectionCount());
            self::assertNull($builder->invoke($coordinator, 'https://example.test/', [], '0123456789abcdef'));
            self::assertFalse($coordinator->warmProcessCacheForInternalReceipt($receipt));
            $connector->rollBack();
            self::assertIsArray($builder->invoke($coordinator, 'https://example.test/', [], '0123456789abcdef'));
        } finally {
            \Weline\Framework\Database\TransactionContext::reset();
            $connector->close();
            unlink($path);
        }
    }

    public function testAuthorizationAlwaysBypassesRawWorkerFpc(): void
    {
        $reflection = new \ReflectionClass(WorkerFullPageCacheFastPath::class);
        $fastPath = $reflection->newInstanceWithoutConstructor();
        $mustBypass = $reflection->getMethod('mustBypass');

        self::assertTrue($mustBypass->invoke($fastPath, ['authorization' => 'Bearer private-token']));
        self::assertFalse($mustBypass->invoke($fastPath, ['authorization' => '']));
        self::assertFalse($mustBypass->invoke($fastPath, []));
        self::assertTrue($mustBypass->invoke($fastPath, ['x-wls-fpc-bypass' => '1'], '/'));
        self::assertTrue($mustBypass->invoke($fastPath, ['cookie' => 'weline_preview_token=abc'], '/'));
        self::assertTrue($mustBypass->invoke($fastPath, [], '/?editor_mode=1'));
        self::assertFalse($mustBypass->invoke($fastPath, ['cache-control' => 'no-cache'], '/'));
        self::assertTrue($mustBypass->invoke($fastPath, ['cache-control' => 'no-store'], '/'));
    }

    public function testLocalizedHomepageUsesOnlyItsExactUnifiedProcessReceipt(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        $pool = new WorkerFastPathCountingCachePool();

        try {
            $coordinator = $this->coordinator($pool);
            $cacheKey = '13579bdf2468ace0';
            $fullUri = 'https://example.test/CNY/zh_Hans_CN/';
            $body = '<html><body>' . \str_repeat('localized-process-receipt', 128) . '</body></html>';
            $setPayload = new \ReflectionMethod($coordinator, 'setProcessCachedPayload');
            $setPayload->invoke($coordinator, $cacheKey, [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
                KeyBuilder::UNIFIED_CACHE_FPC_KEY => $body,
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => ['Content-Type: text/html; charset=utf-8'],
                'fpc_variant' => ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                'fpc_html_urls_validated' => true,
                'fpc_expires_at' => \microtime(true) + 60.0,
            ]);
            $register = new \ReflectionMethod($coordinator, 'registerLocalizedHomepageProcessReceipt');
            $register->invoke(
                $coordinator,
                $fullUri,
                ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                $cacheKey,
            );

            $decision = static function (string $target, array $extraHeaders = []): WorkerPolicyDecision {
                return WorkerPolicyDecision::allow(
                    '127.0.0.1',
                    'GET',
                    'HTTP/1.1',
                    $target,
                    $target,
                    $extraHeaders + [
                        'host' => 'example.test',
                        'accept' => 'text/html',
                        'accept-encoding' => 'gzip',
                        'connection' => 'keep-alive',
                    ],
                    '',
                    \str_repeat('f', 64),
                    false,
                    WorkerPolicyDecision::CACHE_FPC_PROCESS_L1
                        | WorkerPolicyDecision::CACHE_FPC_SHARED_L2,
                );
            };
            $fastPath = new WorkerFullPageCacheFastPath($coordinator, null, true);

            $originalContext = Context::getCurrent();
            $hit = $fastPath->lookup($decision('/CNY/zh_Hans_CN/'), 'https');
            self::assertSame($originalContext, Context::getCurrent(), '早期核验结束恢复调用者 Context。');
            self::assertIsArray($hit);
            self::assertSame('process-formatted', $hit['source']);
            self::assertStringContainsString('localized-process-receipt', \gzdecode(
                \substr($hit['response'], (int)\strpos($hit['response'], "\r\n\r\n") + 4),
            ));
            self::assertStringContainsString("X-Weline-Fpc: HIT\r\n", $hit['response']);
            if (ResponseObservabilityPolicy::performanceBreakdownEnabled()) {
                self::assertStringContainsString("X-Wls-Performance-Urlparser: 0\r\n", $hit['response']);
            }
            self::assertSame(0, $pool->getCalls, 'An exact localized receipt must remain Process-L1-only.');

            self::assertNull($fastPath->lookup($decision('/zh_Hans_CN/CNY/'), 'https'));
            self::assertSame(
                0,
                $pool->getCalls,
                'A different visitor-facing prefix order must return to Framework without Shared reconstruction.',
            );
            self::assertNull($fastPath->lookup($decision(
                '/CNY/zh_Hans_CN/',
                ['cookie' => 'WELINE_USER_CURRENCY=CNY'],
            ), 'https'));
            self::assertSame(0, $pool->getCalls, 'Cookie-bearing requests must return to Framework.');
        } finally {
            FullPageCacheCoordinator::clearProcessCache();
        }
    }

    public function testNaturalRootHomepageHitEstablishesFastPathWithoutReadyWarmup(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        $pool = new WorkerFastPathCountingCachePool();

        try {
            $coordinator = $this->coordinator($pool);
            $cacheKey = '13579bdf2468ace0';
            $fullUri = 'https://example.test/';
            $body = '<html><body>' . \str_repeat('localized-process-receipt', 128) . '</body></html>';
            $setPayload = new \ReflectionMethod($coordinator, 'setProcessCachedPayload');
            $setPayload->invoke($coordinator, $cacheKey, [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
                KeyBuilder::UNIFIED_CACHE_FPC_KEY => $body,
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => ['Content-Type: text/html; charset=utf-8'],
                'fpc_variant' => ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                'fpc_html_urls_validated' => true,
                'fpc_expires_at' => \microtime(true) + 60.0,
            ]);
            $register = new \ReflectionMethod($coordinator, 'registerRootHomepageProcessReceipt');
            $register->invoke(
                $coordinator,
                $fullUri,
                ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                $cacheKey,
            );

            $decision = static function (string $target, array $extraHeaders = []): WorkerPolicyDecision {
                return WorkerPolicyDecision::allow(
                    '127.0.0.1',
                    'GET',
                    'HTTP/1.1',
                    $target,
                    $target,
                    $extraHeaders + [
                        'host' => 'example.test',
                        'accept' => 'text/html',
                        'accept-encoding' => 'gzip',
                        'connection' => 'keep-alive',
                    ],
                    '',
                    \str_repeat('f', 64),
                    false,
                    WorkerPolicyDecision::CACHE_FPC_PROCESS_L1
                        | WorkerPolicyDecision::CACHE_FPC_SHARED_L2,
                );
            };
            $fastPath = new WorkerFullPageCacheFastPath($coordinator, new WlsRuntime(), true);

            $hit = $fastPath->lookup($decision('/'), 'https');
            self::assertIsArray($hit);
            self::assertSame('process-formatted', $hit['source']);
            self::assertStringContainsString('localized-process-receipt', \gzdecode(
                \substr($hit['response'], (int)\strpos($hit['response'], "\r\n\r\n") + 4),
            ));
            self::assertStringContainsString("X-Weline-Fpc: HIT\r\n", $hit['response']);
            if (ResponseObservabilityPolicy::performanceBreakdownEnabled()) {
                self::assertStringContainsString("X-Wls-Performance-Urlparser: 0\r\n", $hit['response']);
            }
            self::assertSame(0, $pool->getCalls, 'An exact localized receipt must remain Process-L1-only.');

            self::assertNull($fastPath->lookup($decision('/zh_Hans_CN/CNY/'), 'https'));
            self::assertSame(
                0,
                $pool->getCalls,
                'A different visitor-facing prefix order must return to Framework without Shared reconstruction.',
            );
            self::assertNull($fastPath->lookup($decision(
                '/',
                ['cookie' => 'WELINE_USER_CURRENCY=CNY'],
            ), 'https'));
            self::assertSame(0, $pool->getCalls, 'Cookie-bearing requests must return to Framework.');
            self::assertNull($fastPath->lookup($decision('/?page=2'), 'https'));
            self::assertNull($fastPath->lookup($decision('/', ['host' => 'other.test']), 'https'));
            FullPageCacheCoordinator::clearProcessCache();
            self::assertNull($fastPath->lookup($decision('/'), 'https'));
        } finally {
            FullPageCacheCoordinator::clearProcessCache();
        }
    }

    public function testAnonymousRootWithoutReceiptFallsBackToFormattedFullUriPath(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        $pool = new WorkerFastPathCountingCachePool();

        try {
            $coordinator = $this->coordinator($pool);
            $cacheKey = '24680ace13579bdf';
            // Receipt stored with explicit default HTTPS port; live Host omits :443.
            $receiptUri = 'https://example.test:443/';
            $body = '<html><body>' . \str_repeat('root-port-alias-formatted', 80) . '</body></html>';
            $setPayload = new \ReflectionMethod($coordinator, 'setProcessCachedPayload');
            $setPayload->invoke($coordinator, $cacheKey, [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
                KeyBuilder::UNIFIED_CACHE_FPC_KEY => $body,
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => ['Content-Type: text/html; charset=utf-8'],
                'fpc_variant' => ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                'fpc_html_urls_validated' => true,
                'fpc_expires_at' => \microtime(true) + 60.0,
            ]);
            $register = new \ReflectionMethod($coordinator, 'registerRootHomepageProcessReceipt');
            $register->invoke(
                $coordinator,
                $receiptUri,
                ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                $cacheKey,
            );

            $decision = WorkerPolicyDecision::allow(
                '127.0.0.1',
                'GET',
                'HTTP/1.1',
                '/',
                '/',
                [
                    'host' => 'example.test',
                    'accept' => 'text/html',
                    'accept-encoding' => 'gzip',
                    'connection' => 'keep-alive',
                ],
                '',
                \str_repeat('f', 64),
                false,
                WorkerPolicyDecision::CACHE_FPC_PROCESS_L1
                    | WorkerPolicyDecision::CACHE_FPC_SHARED_L2,
            );
            $fastPath = new WorkerFullPageCacheFastPath($coordinator, new WlsRuntime(), true);
            $hit = $fastPath->lookup($decision, 'https');
            self::assertIsArray($hit);
            self::assertSame('process-formatted', $hit['source']);
            self::assertStringContainsString('root-port-alias-formatted', \gzdecode(
                \substr($hit['response'], (int)\strpos($hit['response'], "\r\n\r\n") + 4),
            ));
            self::assertStringContainsString("X-Weline-Fpc: HIT\r\n", $hit['response']);
            if (ResponseObservabilityPolicy::performanceBreakdownEnabled()) {
                self::assertMatchesRegularExpression('/X-Wls-Performance-Fpc-Source:\\s*process-formatted/i', $hit['response']);
            } else {
                self::assertSame('process-formatted', $hit['source']);
            }
        } finally {
            FullPageCacheCoordinator::clearProcessCache();
        }
    }

    public function testAnonymousHomepageConsumesTheExactReadyReceiptWithoutEnteringRouter(): void
    {
        FullPageCacheCoordinator::clearProcessCache();

        try {
            $cacheKey = '0123456789abcdef';
            $body = '<html><body>' . \str_repeat('cached-homepage-', 128) . '</body></html>';
            $receipt = $this->exactHomepageReceipt($cacheKey);

            $runtime = new WlsRuntime();
            $runtimeReceipt = new \ReflectionProperty($runtime, 'homepageCacheWarmupReceipt');
            $runtimeReceipt->setValue($runtime, $receipt);

            $coordinator = $this->coordinator();
            $setProcessPayload = new \ReflectionMethod($coordinator, 'setProcessCachedPayload');
            $setProcessPayload->invoke($coordinator, $cacheKey, [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
                KeyBuilder::UNIFIED_CACHE_FPC_KEY => $body,
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => [
                    'Content-Type: text/html; charset=utf-8',
                    'Cache-Control: public, max-age=60',
                ],
                'fpc_variant' => [
                    'lang' => 'zh_Hans_CN',
                    'currency' => 'CNY',
                ],
                'fpc_html_urls_validated' => true,
                'fpc_expires_at' => \microtime(true) + 60.0,
            ]);

            $decision = WorkerPolicyDecision::allow(
                '127.0.0.1',
                'GET',
                'HTTP/1.1',
                '/',
                '/',
                [
                    'host' => 'example.test',
                    'accept' => 'text/html',
                    'accept-encoding' => 'gzip',
                    'connection' => 'keep-alive',
                ],
                '',
                \str_repeat('a', 64),
                false,
                WorkerPolicyDecision::CACHE_FPC_PROCESS_L1
                    | WorkerPolicyDecision::CACHE_FPC_SHARED_L2,
            );

            $resolvedReceipt = $runtime->resolveHomepageFastPathReceipt('https://example.test/');
            self::assertSame($receipt, $resolvedReceipt);
            $direct = $coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                $resolvedReceipt,
                true,
                'GET',
                'text/html',
                'gzip',
            );
            self::assertIsArray($direct);

            $result = (new WorkerFullPageCacheFastPath($coordinator, $runtime, true))->lookup($decision, 'https');

            self::assertIsArray($result);
            self::assertSame('process-formatted', $result['source']);
            self::assertStringContainsString("X-Weline-Fpc: HIT\r\n", $result['response']);
            if (ResponseObservabilityPolicy::performanceBreakdownEnabled()) {
                self::assertStringContainsString("X-Wls-Performance-Urlparser: 0\r\n", $result['response']);
                self::assertStringContainsString("X-Wls-Performance-Urlparserapply: 0\r\n", $result['response']);
            }
            self::assertStringContainsString("Content-Encoding: gzip\r\n", $result['response']);
            self::assertStringContainsString("Connection: keep-alive\r\n", $result['response']);

            $headDecision = WorkerPolicyDecision::allow(
                '127.0.0.1',
                'HEAD',
                'HTTP/1.1',
                '/',
                '/',
                [
                    'host' => 'example.test',
                    'accept' => 'text/html',
                    'accept-encoding' => 'gzip',
                    'connection' => 'close',
                ],
                '',
                \str_repeat('b', 64),
                false,
                WorkerPolicyDecision::CACHE_FPC_PROCESS_L1,
            );
            $head = (new WorkerFullPageCacheFastPath($coordinator, $runtime, true))->lookup(
                $headDecision,
                'https',
            );
            self::assertIsArray($head);
            self::assertSame('process', $head['source']);
            self::assertStringContainsString("Content-Encoding: gzip\r\n", $head['response']);
            self::assertStringEndsWith("\r\n\r\n", $head['response']);

            $nonHtmlDecision = WorkerPolicyDecision::allow(
                '127.0.0.1',
                'GET',
                'HTTP/1.1',
                '/',
                '/',
                ['host' => 'example.test', 'accept' => 'application/json'],
                '',
                \str_repeat('c', 64),
                false,
                WorkerPolicyDecision::CACHE_FPC_PROCESS_L1,
            );
            self::assertNull((new WorkerFullPageCacheFastPath($coordinator, $runtime, true))->lookup(
                $nonHtmlDecision,
                'https',
            ));
        } finally {
            FullPageCacheCoordinator::clearProcessCache();
        }
    }

    public function testReadyReceiptRemainsBoundToTheAnonymousRootOrigin(): void
    {
        $cacheKey = 'fedcba9876543210';
        $receipt = [
            'version' => 2,
            'full_uri' => 'https://example.test/',
            'method' => 'GET',
            'cookie_header' => '',
            'identity_digest' => \hash('sha256', $cacheKey),
            'cache_key' => $cacheKey,
            'scope_identity' => StorefrontCacheKeyContext::current()->scopeIdentity->toArray(),
            'namespace_fingerprint' => StorefrontCacheKeyContext::current()->namespaceFingerprint,
        ];
        $runtime = new WlsRuntime();
        $runtimeReceipt = new \ReflectionProperty($runtime, 'homepageCacheWarmupReceipt');
        $runtimeReceipt->setValue($runtime, $receipt);

        self::assertSame($receipt, $runtime->resolveHomepageFastPathReceipt('https://example.test/'));
        self::assertNull($runtime->resolveHomepageFastPathReceipt(
            'https://example.test/',
            'private=value',
        ));
        self::assertNull($runtime->resolveHomepageFastPathReceipt('https://example.test/catalog'));
        self::assertNull($runtime->resolveHomepageFastPathReceipt('https://other.example.test/'));
        self::assertNull($runtime->resolveHomepageFastPathReceipt('http://example.test/'));
        self::assertSame($receipt, $runtime->resolveHomepageFastPathReceipt('https://example.test:443/'));
        self::assertNull($runtime->resolveHomepageFastPathReceipt('https://example.test:8443/'));

        $portReceipt = $receipt;
        $portReceipt['full_uri'] = 'https://example.test:8443/';
        $runtimeReceipt->setValue($runtime, $portReceipt);
        self::assertSame(
            $portReceipt,
            $runtime->resolveHomepageFastPathReceipt('https://example.test:8443/'),
        );
        self::assertNull($runtime->resolveHomepageFastPathReceipt('https://example.test/'));
    }

    public function testReceiptFailureNeverFallsThroughToGenericOrSharedCache(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        $registryFile = \tempnam(\sys_get_temp_dir(), 'wls-provider-registry-');
        self::assertIsString($registryFile);
        self::assertNotFalse(\file_put_contents(
            $registryFile,
            "<?php return ['format' => 1, 'order' => [], 'modules' => []];\n",
        ));

        $pool = new WorkerFastPathCountingCachePool();
        try {
            if (Context::hasCurrent()) {
                Context::leave();
            }
            Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'wls']]));
            RequestContext::setId('worker-receipt-shared-fence');
            $identity = ScopeIdentity::channel(
                0,
                'default',
                'default',
                'default',
                ScopeIdentity::MODE_NORMAL,
            );
            RequestContext::installScopeIdentity($identity);
            StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
                $identity,
                'zh_Hans_CN',
                'CNY',
                \str_repeat('d', 64),
                \str_repeat('d', 64),
                true,
            ));

            $coordinator = new FullPageCacheCoordinator(
                null,
                $pool,
                null,
                null,
                null,
                new RuntimeProviderResolver(new ServiceProviderRegistry($registryFile)),
            );
            self::assertNull($coordinator->getFormattedCachedResponseForFullUri(
                'https://example.test/',
                'GET',
                'text/html',
                'gzip',
                '',
                true,
                false,
            ));
            self::assertGreaterThan(0, $pool->getCalls, 'The fixture must expose the generic Shared lookup.');
            $pool->getCalls = 0;

            $runtime = new WlsRuntime();
            $runtimeReceipt = new \ReflectionProperty($runtime, 'homepageCacheWarmupReceipt');
            $runtimeReceipt->setValue($runtime, [
                'version' => 2,
                'full_uri' => 'https://other.example.test/',
                'method' => 'GET',
                'cookie_header' => '',
                'identity_digest' => \hash('sha256', 'aaaaaaaaaaaaaaaa'),
                'cache_key' => 'aaaaaaaaaaaaaaaa',
                'scope_identity' => StorefrontCacheKeyContext::current()->scopeIdentity->toArray(),
                'namespace_fingerprint' => StorefrontCacheKeyContext::current()->namespaceFingerprint,
            ]);
            $decision = WorkerPolicyDecision::allow(
                '127.0.0.1',
                'GET',
                'HTTP/1.1',
                '/',
                '/',
                ['host' => 'example.test', 'accept' => 'text/html', 'accept-encoding' => 'gzip'],
                '',
                \str_repeat('e', 64),
                false,
                WorkerPolicyDecision::CACHE_FPC_PROCESS_L1
                    | WorkerPolicyDecision::CACHE_FPC_SHARED_L2,
            );

            self::assertNull((new WorkerFullPageCacheFastPath($coordinator, $runtime, true))->lookup(
                $decision,
                'https',
            ));
            self::assertSame(0, $pool->getCalls, 'A failed READY receipt must return to Framework without Shared L2.');

            $runtimeReceipt->setValue($runtime, [
                'version' => 2,
                'full_uri' => 'https://example.test/',
                'method' => 'GET',
                'cookie_header' => '',
                'identity_digest' => \hash('sha256', 'bbbbbbbbbbbbbbbb'),
                'cache_key' => 'bbbbbbbbbbbbbbbb',
                'scope_identity' => StorefrontCacheKeyContext::current()->scopeIdentity->toArray(),
                'namespace_fingerprint' => StorefrontCacheKeyContext::current()->namespaceFingerprint,
            ]);
            $pool->getCalls = 0;
            self::assertNull((new WorkerFullPageCacheFastPath($coordinator, $runtime, true))->lookup(
                $decision,
                'https',
            ));
            self::assertSame(
                0,
                $pool->getCalls,
                'An exact receipt whose Process L1 entry is absent must not probe Shared L2.',
            );
        } finally {
            RequestContext::cleanup();
            if (Context::hasCurrent()) {
                Context::leave();
            }
            @\unlink($registryFile);
            FullPageCacheCoordinator::clearProcessCache();
        }
    }

    public function testReplacingAProcessPayloadInvalidatesFormattedBytesAndBoundsTheirTtl(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        try {
            $coordinator = $this->coordinator();
            $cacheKey = '1234567890abcdef';
            $receipt = $this->exactHomepageReceipt($cacheKey);
            $expiresAt = \microtime(true) + 30.0;
            $setPayload = new \ReflectionMethod($coordinator, 'setProcessCachedPayload');
            $payload = static fn(string $marker): array => [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
                KeyBuilder::UNIFIED_CACHE_FPC_KEY => '<html><body>' . \str_repeat($marker, 1024) . '</body></html>',
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => ['Content-Type: text/html; charset=utf-8'],
                'fpc_variant' => ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                'fpc_html_urls_validated' => true,
                'fpc_expires_at' => $expiresAt,
            ];

            $setPayload->invoke($coordinator, $cacheKey, $payload('payload-A'));
            self::assertIsArray($coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                $receipt,
                true,
                'GET',
                'text/html',
                'gzip',
            ));

            $setPayload->invoke($coordinator, $cacheKey, $payload('payload-B'));
            $updated = $coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                $receipt,
                true,
                'GET',
                'text/html',
                'gzip',
            );
            self::assertIsArray($updated);
            $bodyOffset = \strpos($updated['response'], "\r\n\r\n");
            self::assertNotFalse($bodyOffset);
            $decoded = \gzdecode(\substr($updated['response'], $bodyOffset + 4));
            self::assertIsString($decoded);
            self::assertStringContainsString('payload-B', $decoded);
            self::assertStringNotContainsString('payload-A', $decoded);

            $formattedExpires = new \ReflectionProperty(
                FullPageCacheCoordinator::class,
                'processFormattedFpcExpiresAt',
            );
            $expirations = $formattedExpires->getValue();
            self::assertIsArray($expirations);
            self::assertNotEmpty($expirations);
            self::assertLessThanOrEqual($expiresAt, \max($expirations));
        } finally {
            FullPageCacheCoordinator::clearProcessCache();
        }
    }

    public function testExactReceiptRejectsAnExplicitHtmlQualityOfZero(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        try {
            $coordinator = $this->coordinator();
            $cacheKey = '0f0e0d0c0b0a0908';
            $receipt = $this->exactHomepageReceipt($cacheKey);
            $setPayload = new \ReflectionMethod($coordinator, 'setProcessCachedPayload');
            $setPayload->invoke($coordinator, $cacheKey, [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
                KeyBuilder::UNIFIED_CACHE_FPC_KEY => '<html><body>quality</body></html>',
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => ['Content-Type: text/html; charset=utf-8'],
                'fpc_variant' => ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                'fpc_html_urls_validated' => true,
                'fpc_expires_at' => \microtime(true) + 30.0,
            ]);

            self::assertNull($coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                $receipt,
                true,
                'GET',
                'text/html;q=0, */*;q=1',
            ));
            self::assertNull($coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                $receipt,
                true,
                'GET',
                'application/xhtml+xml;q=0, application/json',
            ));
            self::assertIsArray($coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                $receipt,
                true,
                'GET',
                'text/html;q=0.1',
            ));
        } finally {
            FullPageCacheCoordinator::clearProcessCache();
        }
    }

    public function testExactReceiptDoesNotCompressWhenGzipQualityIsZero(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        try {
            $coordinator = $this->coordinator();
            $cacheKey = '1029384756abcdef';
            $receipt = $this->exactHomepageReceipt($cacheKey);
            $setPayload = new \ReflectionMethod($coordinator, 'setProcessCachedPayload');
            $setPayload->invoke($coordinator, $cacheKey, [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
                KeyBuilder::UNIFIED_CACHE_FPC_KEY => '<html><body>' . \str_repeat('identity-body', 256) . '</body></html>',
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => ['Content-Type: text/html; charset=utf-8'],
                'fpc_variant' => ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                'fpc_html_urls_validated' => true,
                'fpc_expires_at' => \microtime(true) + 30.0,
            ]);

            $result = $coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                $receipt,
                true,
                'GET',
                'text/html',
                'br;q=0, gzip;q=0',
            );
            self::assertIsArray($result);
            self::assertStringNotContainsString("Content-Encoding: gzip\r\n", $result['response']);
            self::assertStringNotContainsString("Content-Encoding: br\r\n", $result['response']);
            self::assertStringContainsString('identity-body', $result['response']);
        } finally {
            FullPageCacheCoordinator::clearProcessCache();
        }
    }

    public function testExactReceiptServesBrotliWhenAcceptsBrAndPayloadIsBrotliOnly(): void
    {
        if (!\function_exists('brotli_compress') || !\function_exists('brotli_uncompress')) {
            self::markTestSkipped('brotli extension is not loaded');
        }

        FullPageCacheCoordinator::clearProcessCache();
        try {
            $coordinator = $this->coordinator();
            $cacheKey = 'abcdef1029384756';
            $plain = '<html><body>' . \str_repeat('br-only-fastpath', 128) . '</body></html>';
            $br = \brotli_compress($plain, \Weline\Framework\Http\ContentEncodingNegotiator::BROTLI_QUALITY);
            self::assertIsString($br);
            $receipt = $this->exactHomepageReceipt($cacheKey);
            $setPayload = new \ReflectionMethod($coordinator, 'setProcessCachedPayload');
            $setPayload->invoke($coordinator, $cacheKey, [
                KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
                'fpc_br_b64' => \base64_encode($br),
                KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => ['Content-Type: text/html; charset=utf-8'],
                'fpc_variant' => ['lang' => 'zh_Hans_CN', 'currency' => 'CNY'],
                'fpc_html_urls_validated' => true,
                'fpc_expires_at' => \microtime(true) + 30.0,
            ]);

            $result = $coordinator->getFormattedProcessCachedResponseForInternalReceipt(
                $receipt,
                true,
                'GET',
                'text/html',
                'br, gzip',
            );
            self::assertIsArray($result);
            self::assertSame('process-formatted', $result['source']);
            self::assertStringContainsString("Content-Encoding: br\r\n", $result['response']);
            $wire = \substr($result['response'], (int)\strpos($result['response'], "\r\n\r\n") + 4);
            self::assertSame($plain, \brotli_uncompress($wire));
        } finally {
            FullPageCacheCoordinator::clearProcessCache();
        }
    }
}

final class WorkerFastPathCountingCachePool implements CachePoolInterface
{
    public int $getCalls = 0;

    public function get(string $key): mixed
    {
        ++$this->getCalls;
        return null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function getIdentity(): string
    {
        return 'router';
    }

    public function getTip(): string
    {
        return 'worker-fastpath-counting-test';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        return \array_fill_keys($keys, null);
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        return true;
    }

    public function getStats(): array
    {
        return [
            'identity' => 'router',
            'hits' => 0,
            'misses' => $this->getCalls,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }

    public function getCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
    ): mixed {
        return $this->get($key);
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
    ): bool {
        return true;
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
    ): bool {
        return true;
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
    ): bool {
        return false;
    }
}

/** SQLite 权威版本与真实请求快照；不通过广播更新进程向量。 */
final class WorkerReceiptSqliteAuthority implements \Weline\Framework\Cache\Contract\NamespaceGenerationInterface
{
    public int $clockReads = 0;
    private \PDO $db;
    private \Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot $snapshot;

    public function __construct()
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE versions (namespace TEXT PRIMARY KEY, generation INTEGER NOT NULL)');
        $this->snapshot = new \Weline\Framework\Cache\Namespace\NamespaceGenerationSnapshot();
    }

    public function fingerprint(array $namespaces): string
    {
        $paths = new \Weline\Framework\Cache\Namespace\NamespacePath();
        $vector = $this->snapshot->resolve($paths->expandAncestors($namespaces), function (array $names): array {
            if ($names === ['@clock']) { ++$this->clockReads; }
            $query = $this->db->prepare('SELECT namespace, generation FROM versions WHERE namespace IN ('
                . implode(',', array_fill(0, count($names), '?')) . ')');
            $query->execute($names);
            return array_map('intval', $query->fetchAll(\PDO::FETCH_KEY_PAIR));
        });
        return (new \Weline\Framework\Cache\Namespace\NamespaceKeyDecorator())->fingerprint($vector['generations']);
    }

    public function bump(string $namespace): array { return $this->bumpMany([$namespace]); }
    public function bumpMany(array $namespaces): array
    {
        $this->db->beginTransaction();
        $query = $this->db->prepare('INSERT INTO versions(namespace, generation) VALUES (?, 1) ON CONFLICT(namespace) DO UPDATE SET generation = generation + 1');
        foreach ([...array_unique($namespaces), '@clock'] as $name) { $query->execute([$name]); }
        $this->db->commit();
        return [];
    }
}
