<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locals;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

class ActiveLocaleCodeProviderTest extends TestCase
{
    private array $instances;

    protected function setUp(): void
    {
        $this->instances = \Weline\Framework\Manager\ObjectManager::getInstances();
        \Weline\Framework\Runtime\RequestContext::init();
        ActiveLocaleCodeProvider::clearProcessCache();
        $authority = $this->createStub(\Weline\Framework\Cache\Contract\NamespaceGenerationInterface::class);
        $authority->method('fingerprint')->willReturn('locale-provider-fixture');
        \Weline\Framework\Manager\ObjectManager::setInstance(\Weline\Framework\Cache\Contract\NamespaceGenerationInterface::class, $authority);
    }

    protected function tearDown(): void
    {
        ActiveLocaleCodeProvider::clearProcessCache();
        \Weline\Framework\Runtime\RequestContext::cleanup();
        (new \ReflectionProperty(\Weline\Framework\Manager\ObjectManager::class, 'instances'))->setValue(null, $this->instances);
    }

    public function testMergesLocaleInstallRegistryWithLocalsRowsViaUnion(): void
    {
        $locale = $this->mockUnionModel(Locale::class, 'w_i18n_locale', [
            ['code' => 'zh_Hans_CN'],
            ['code' => 'en_US'],
            ['code' => 'ja_JP'],
            ['code' => 'zh_Hans_CN'], // locals-side duplicate after UNION ALL
            ['unexpected' => 'ignored'],
        ], expectQueryTimes: 1);
        $locals = $this->mockUnionCompanion(Locals::class, 'w_i18n_locals');

        $provider = new ActiveLocaleCodeProvider($locals, $locale);

        self::assertSame(
            [
                'zh_Hans_CN' => true,
                'zh_hans_cn' => true,
                'en_US' => true,
                'en_us' => true,
                'ja_JP' => true,
                'ja_jp' => true,
            ],
            $provider->getInstalledActiveCodeMap()
        );
        self::assertSame(['zh_Hans_CN', 'en_US', 'ja_JP'], $provider->getInstalledActiveCodes());
    }

    public function testResetClearsMemoizedCodesViaUnion(): void
    {
        $locale = $this->getMockBuilder(Locale::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTable', 'getConnection'])
            ->getMock();
        $locale->method('getTable')->willReturn('w_i18n_locale');

        $locals = $this->mockUnionCompanion(Locals::class, 'w_i18n_locals');

        $batches = [
            [['code' => 'zh_Hans_CN']],
            [['code' => 'en_US'], ['code' => 'zh_Hans_CN']],
        ];
        $connector = new class($batches) {
            public int $call = 0;

            public function __construct(private array $batches)
            {
            }

            public function query(string $sql): object
            {
                \PHPUnit\Framework\Assert::assertStringContainsString('UNION ALL', $sql);
                $rows = $this->batches[$this->call] ?? [];
                ++$this->call;

                return new class($rows) {
                    public function __construct(private array $rows)
                    {
                    }

                    public function fetchIterator(): \Generator
                    {
                        yield from $this->rows;
                    }
                };
            }
        };
        $connection = new class($connector) {
            public function __construct(private object $connector)
            {
            }

            public function getConnector(): object
            {
                return $this->connector;
            }
        };
        $locale->method('getConnection')->willReturn($connection);

        $provider = new ActiveLocaleCodeProvider($locals, $locale);
        self::assertSame(['zh_Hans_CN'], $provider->getInstalledActiveCodes());
        $provider->reset();
        self::assertSame(['en_US', 'zh_Hans_CN'], $provider->getInstalledActiveCodes());
        self::assertSame(2, $connector->call);
    }

    public function testFallsBackToDualFetchArrayWhenUnionUnavailable(): void
    {
        $locale = $this->getMockBuilder(Locale::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTable', 'getConnection'])
            ->addMethods(['clearQuery', 'where', 'select', 'fetchArray'])
            ->getMock();
        $locale->method('getTable')->willReturn('');
        $locale->expects($this->once())->method('clearQuery')->willReturnSelf();
        $locale->expects($this->exactly(2))->method('where')->willReturnSelf();
        $locale->expects($this->once())->method('select')->with('code')->willReturnSelf();
        $locale->expects($this->once())->method('fetchArray')->willReturn([
            ['code' => 'en_US'],
            ['code' => 'zh_Hans_CN'],
        ]);

        $locals = $this->getMockBuilder(Locals::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTable'])
            ->addMethods(['clearQuery', 'where', 'select', 'fetchArray'])
            ->getMock();
        $locals->method('getTable')->willReturn('w_i18n_locals');
        $locals->expects($this->once())->method('clearQuery')->willReturnSelf();
        $locals->expects($this->exactly(2))->method('where')->willReturnSelf();
        $locals->expects($this->once())->method('select')->with('code')->willReturnSelf();
        $locals->expects($this->once())->method('fetchArray')->willReturn([
            ['code' => 'ja_JP'],
        ]);

        $provider = new ActiveLocaleCodeProvider($locals, $locale);
        self::assertSame(['en_US', 'zh_Hans_CN', 'ja_JP'], $provider->getInstalledActiveCodes());
    }

    /**
     * @param class-string $class
     * @param list<array<string, mixed>> $unionRows
     */
    private function mockUnionModel(string $class, string $table, array $unionRows, int $expectQueryTimes = 1): object
    {
        $model = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTable', 'getConnection'])
            ->getMock();
        $model->method('getTable')->willReturn($table);

        $queryCount = 0;
        $connector = new class($unionRows, $queryCount, $expectQueryTimes) {
            public function __construct(
                private array $rows,
                private int &$queryCount,
                private int $expectQueryTimes,
            ) {
            }

            public function query(string $sql): object
            {
                ++$this->queryCount;
                \PHPUnit\Framework\Assert::assertLessThanOrEqual($this->expectQueryTimes, $this->queryCount);
                \PHPUnit\Framework\Assert::assertStringContainsString('UNION ALL', $sql);
                \PHPUnit\Framework\Assert::assertStringContainsString('w_i18n_locale', $sql);
                \PHPUnit\Framework\Assert::assertStringContainsString('w_i18n_locals', $sql);

                return new class($this->rows) {
                    public function __construct(private array $rows)
                    {
                    }

                    public function fetchIterator(): \Generator
                    {
                        yield from $this->rows;
                    }
                };
            }
        };
        $connection = new class($connector) {
            public function __construct(private object $connector)
            {
            }

            public function getConnector(): object
            {
                return $this->connector;
            }
        };
        $model->method('getConnection')->willReturn($connection);

        return $model;
    }

    /**
     * @param class-string $class
     */
    private function mockUnionCompanion(string $class, string $table): object
    {
        $model = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTable'])
            ->getMock();
        $model->method('getTable')->willReturn($table);

        return $model;
    }
}
