<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;

final class AsyncEventConfigurationContractTest extends TestCore
{
    public function testResourceChangedDeclaresVersionedAsyncContractMetadata(): void
    {
        $config = ObjectManager::getInstance(XmlReader::class)->read();
        $observer = null;
        foreach ($config as $events) {
            foreach ((array)($events['Weline_Framework::resource_changed'] ?? []) as $candidate) {
                if (($candidate['name'] ?? '') === 'cache_namespace') {
                    $observer = $candidate;
                    break 2;
                }
            }
        }

        self::assertIsArray($observer);
        self::assertSame('sync', $observer['delivery']);
        self::assertSame('critical', $observer['failure']);
        self::assertSame(1, $observer['event_schema_version']);
        self::assertSame(
            'Weline\\Framework\\Event\\ResourceChange\\ResourceChangePayloadMapper',
            $observer['event_async_mapper'],
        );
        self::assertSame('resource_change.v1', $observer['event_data_contract']);
    }

    #[DataProvider('validPolicies')]
    public function testObserverPolicyAcceptsOnlySupportedCombinations(array $policy): void
    {
        $this->invokePolicyValidation($policy);
        self::addToAssertionCount(1);
    }

    #[DataProvider('invalidPolicies')]
    public function testObserverPolicyRejectsAmbiguousOrUnsafeCombinations(array $policy): void
    {
        $this->expectException(EventConfigurationException::class);
        $this->invokePolicyValidation($policy);
    }

    public static function validPolicies(): iterable
    {
        yield 'sync critical' => [[
            'delivery' => 'sync', 'failure' => 'critical',
            'retry_explicit' => false, 'coalesce_explicit' => false, 'timeout_explicit' => false,
        ]];
        yield 'sync isolated' => [[
            'delivery' => 'sync', 'failure' => 'isolated',
            'retry_explicit' => false, 'coalesce_explicit' => false, 'timeout_explicit' => false,
        ]];
        yield 'async standard latest' => [[
            'delivery' => 'async', 'failure_explicit' => false,
            'retry' => 'standard', 'coalesce' => 'latest', 'timeout' => 3600,
        ]];
        yield 'async no retry' => [[
            'delivery' => 'async', 'failure_explicit' => false,
            'retry' => 'none', 'coalesce' => 'none', 'timeout' => 1,
        ]];
    }

    public static function invalidPolicies(): iterable
    {
        yield 'unknown delivery' => [[
            'delivery' => 'deferred', 'failure' => 'critical',
        ]];
        yield 'sync retry declaration' => [[
            'delivery' => 'sync', 'failure' => 'critical',
            'retry_explicit' => true, 'coalesce_explicit' => false, 'timeout_explicit' => false,
        ]];
        yield 'sync invalid failure' => [[
            'delivery' => 'sync', 'failure' => 'ignored',
            'retry_explicit' => false, 'coalesce_explicit' => false, 'timeout_explicit' => false,
        ]];
        yield 'async failure declaration' => [[
            'delivery' => 'async', 'failure_explicit' => true,
            'retry' => 'standard', 'coalesce' => 'none', 'timeout' => 30,
        ]];
        yield 'async invalid retry' => [[
            'delivery' => 'async', 'failure_explicit' => false,
            'retry' => 'forever', 'coalesce' => 'none', 'timeout' => 30,
        ]];
        yield 'async invalid coalesce' => [[
            'delivery' => 'async', 'failure_explicit' => false,
            'retry' => 'standard', 'coalesce' => 'append', 'timeout' => 30,
        ]];
        yield 'async zero timeout' => [[
            'delivery' => 'async', 'failure_explicit' => false,
            'retry' => 'standard', 'coalesce' => 'none', 'timeout' => 0,
        ]];
        yield 'async excessive timeout' => [[
            'delivery' => 'async', 'failure_explicit' => false,
            'retry' => 'standard', 'coalesce' => 'none', 'timeout' => 3601,
        ]];
    }

    private function invokePolicyValidation(array $policy): void
    {
        $reader = (new \ReflectionClass(XmlReader::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(XmlReader::class, 'validateObserverPolicy');
        $method->invoke($reader, 'Weline_Test::contract', $policy);
    }
}
