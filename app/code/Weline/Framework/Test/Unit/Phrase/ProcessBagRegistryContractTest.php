<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\Framework\Runtime\RequestContext;

/**
 * Ch5 门禁：进程袋身份必须显式命名；空名不得登记；登记表有界。
 */
final class ProcessBagRegistryContractTest extends TestCase
{
    private const TX_STATES_KEY = 'framework.database.transaction_states';

    protected function setUp(): void
    {
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setId(null);
        RequestContext::set(self::TX_STATES_KEY, []);
    }

    protected function tearDown(): void
    {
        RequestContext::set(self::TX_STATES_KEY, []);
        RequestContext::setId(null);
        if (Context::hasCurrent()) {
            Context::leave();
        }
    }

    public function testEmptyNameNeverRegistersAProcessBag(): void
    {
        $before = DictionaryCacheNamespace::registeredBagNames();
        $bag = [];

        $words = &DictionaryCacheNamespace::processBag('', $bag, 8, ['zh_Hans_CN']);
        $words['k'] = 'v';

        self::assertSame($before, DictionaryCacheNamespace::registeredBagNames());
        self::assertSame(['k' => 'v'], $bag);
    }

    public function testEveryRegisteredNameIsExplicitAndNamespaced(): void
    {
        $bag = [];
        DictionaryCacheNamespace::processBag('phrase.contract.named', $bag, 8, ['zh_Hans_CN']);

        foreach (DictionaryCacheNamespace::registeredBagNames() as $name) {
            self::assertMatchesRegularExpression(
                '/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*$/',
                $name,
                '进程袋名必须显式且可读：' . $name,
            );
            self::assertLessThanOrEqual(64, \strlen($name));
        }
    }

    public function testRegistryStaysBounded(): void
    {
        self::assertLessThanOrEqual(
            40,
            \count(DictionaryCacheNamespace::registeredBagNames()),
            '登记表超过 40 个命名袋时，需在计划文档登记豁免并复核。',
        );
    }
}
