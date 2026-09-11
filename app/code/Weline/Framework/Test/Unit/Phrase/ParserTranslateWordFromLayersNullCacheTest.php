<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\Context;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Transaction\TransactionState;
use Weline\Framework\Phrase\Parser;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeInterface;

/**
 * 活跃事务中 localCache 返回临时空数组；不得用 raw cache 的 array_key_exists
 * 再去下标 localCache（会得到 null，违反 :string）。主题编辑器 remove-widget 曾触发。
 */
final class ParserTranslateWordFromLayersNullCacheTest extends TestCase
{
    private const TX_STATES_KEY = 'framework.database.transaction_states';

    protected function setUp(): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::init();
        Runtime::setMode(RuntimeInterface::MODE_WLS);
        Parser::clearWorkerCaches();
        RequestContext::set(self::TX_STATES_KEY, []);
    }

    protected function tearDown(): void
    {
        Parser::clearWorkerCaches();
        RequestContext::set(self::TX_STATES_KEY, []);
        RequestContext::setId(null);
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }

    public function testStaleWorkerWordCacheDoesNotReturnNullWhenFingerprintUnavailable(): void
    {
        $cacheKey = 'uncached|Weline_Test|删除成功';
        $property = new ReflectionProperty(Parser::class, 'workerTranslatedWordsCache');
        $property->setValue(null, [$cacheKey => 'Deleted OK']);

        // Active transaction → fingerprint() === null → ephemeral localCache.
        $query = $this->createStub(QueryInterface::class);
        RequestContext::set(self::TX_STATES_KEY, [
            'probe' => new TransactionState($query, 1, false),
        ]);

        $layers = [
            'cache_key' => 'uncached|Weline_Test',
            'lang' => 'zh_Hans_CN',
            'modules' => ['Weline_Theme'],
            'module_words' => ['Weline_Theme' => []],
            'locale_words' => [],
            'global_words' => [],
        ];

        $translate = new ReflectionMethod(Parser::class, 'translateWordFromLayers');
        $result = $translate->invoke(null, '删除成功', $layers);

        self::assertIsString($result);
        self::assertSame('删除成功', $result);
    }
}
