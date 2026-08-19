<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Context;
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
    public function testAuthorizationAlwaysBypassesRawWorkerFpc(): void
    {
        $reflection = new \ReflectionClass(WorkerFullPageCacheFastPath::class);
        $fastPath = $reflection->newInstanceWithoutConstructor();
        $mustBypass = $reflection->getMethod('mustBypass');

        self::assertTrue($mustBypass->invoke($fastPath, ['authorization' => 'Bearer private-token']));
        self::assertFalse($mustBypass->invoke($fastPath, ['authorization' => '']));
        self::assertFalse($mustBypass->invoke($fastPath, []));
    }

    public function testLocalizedHomepageUsesOnlyItsExactUnifiedProcessReceipt(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        $pool = new WorkerFastPathCountingCachePool();

        try {
            $coordinator = new FullPageCacheCoordinator(null, $pool);
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

            $hit = $fastPath->lookup($decision('/CNY/zh_Hans_CN/'), 'https');
            self::assertIsArray($hit);
            self::assertSame('process-formatted', $hit['source']);
            self::assertStringContainsString('localized-process-receipt', \gzdecode(
                \substr($hit['response'], (int)\strpos($hit['response'], "\r\n\r\n") + 4),
            ));
            self::assertStringContainsString("X-Wls-Performance-Urlparser: 0\r\n", $hit['response']);
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

    public function testAnonymousHomepageConsumesTheExactReadyReceiptWithoutEnteringRouter(): void
    {
        FullPageCacheCoordinator::clearProcessCache();

        try {
            $cacheKey = '0123456789abcdef';
            $body = '<html><body>' . \str_repeat('cached-homepage-', 128) . '</body></html>';
            $receipt = [
                'version' => 1,
                'full_uri' => 'https://example.test/',
                'method' => 'GET',
                'cookie_header' => 'WELINE_USER_LANG=zh_Hans_CN; WELINE_USER_CURRENCY=CNY',
                'identity_digest' => \hash('sha256', $cacheKey),
                'cache_key' => $cacheKey,
            ];

            $runtime = new WlsRuntime();
            $runtimeReceipt = new \ReflectionProperty($runtime, 'homepageCacheWarmupReceipt');
            $runtimeReceipt->setValue($runtime, $receipt);

            $coordinator = new FullPageCacheCoordinator();
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
            self::assertStringContainsString("X-Wls-Performance-Urlparser: 0\r\n", $result['response']);
            self::assertStringContainsString("X-Wls-Performance-Urlparserapply: 0\r\n", $result['response']);
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
            'version' => 1,
            'full_uri' => 'https://example.test/',
            'method' => 'GET',
            'cookie_header' => 'WELINE_USER_LANG=zh_Hans_CN; WELINE_USER_CURRENCY=CNY',
            'identity_digest' => \hash('sha256', $cacheKey),
            'cache_key' => $cacheKey,
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
                'version' => 1,
                'full_uri' => 'https://other.example.test/',
                'method' => 'GET',
                'cookie_header' => 'WELINE_USER_LANG=zh_Hans_CN; WELINE_USER_CURRENCY=CNY',
                'identity_digest' => \hash('sha256', 'aaaaaaaaaaaaaaaa'),
                'cache_key' => 'aaaaaaaaaaaaaaaa',
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
                'version' => 1,
                'full_uri' => 'https://example.test/',
                'method' => 'GET',
                'cookie_header' => 'WELINE_USER_LANG=zh_Hans_CN; WELINE_USER_CURRENCY=CNY',
                'identity_digest' => \hash('sha256', 'bbbbbbbbbbbbbbbb'),
                'cache_key' => 'bbbbbbbbbbbbbbbb',
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
            $coordinator = new FullPageCacheCoordinator();
            $cacheKey = '1234567890abcdef';
            $receipt = [
                'version' => 1,
                'full_uri' => 'https://example.test/',
                'method' => 'GET',
                'cookie_header' => 'WELINE_USER_LANG=zh_Hans_CN; WELINE_USER_CURRENCY=CNY',
                'identity_digest' => \hash('sha256', $cacheKey),
                'cache_key' => $cacheKey,
            ];
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
            $coordinator = new FullPageCacheCoordinator();
            $cacheKey = '0f0e0d0c0b0a0908';
            $receipt = [
                'version' => 1,
                'full_uri' => 'https://example.test/',
                'method' => 'GET',
                'cookie_header' => 'WELINE_USER_LANG=zh_Hans_CN; WELINE_USER_CURRENCY=CNY',
                'identity_digest' => \hash('sha256', $cacheKey),
                'cache_key' => $cacheKey,
            ];
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
            $coordinator = new FullPageCacheCoordinator();
            $cacheKey = '1029384756abcdef';
            $receipt = [
                'version' => 1,
                'full_uri' => 'https://example.test/',
                'method' => 'GET',
                'cookie_header' => 'WELINE_USER_LANG=zh_Hans_CN; WELINE_USER_CURRENCY=CNY',
                'identity_digest' => \hash('sha256', $cacheKey),
                'cache_key' => $cacheKey,
            ];
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
                'gzip;q=0, *;q=1',
            );
            self::assertIsArray($result);
            self::assertStringNotContainsString("Content-Encoding: gzip\r\n", $result['response']);
            self::assertStringContainsString('identity-body', $result['response']);
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
