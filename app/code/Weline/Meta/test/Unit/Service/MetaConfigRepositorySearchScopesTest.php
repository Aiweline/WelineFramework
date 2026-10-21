<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Model\MetaConfig;
use Weline\Meta\Service\Repository\MetaConfigRepository;

final class MetaConfigRepositorySearchScopesTest extends TestCase
{
    public function testEmptyScopeChainReturnsWithoutQuerying(): void
    {
        $model = $this->createMock(MetaConfig::class);
        $model->expects(self::never())->method('newQuery');

        $repository = $this->repository($model);

        self::assertSame([], $repository->searchScopes($this->search(), []));
    }

    public function testInvalidScopeIsRejectedBeforeQuerying(): void
    {
        $model = $this->createMock(MetaConfig::class);
        $model->expects(self::never())->method('newQuery');

        $repository = $this->repository($model);

        $this->expectException(\InvalidArgumentException::class);
        $repository->searchScopes($this->search(), ['default', '   ']);
    }

    public function testOneQueryReadsEveryScopeAndKeepsDeterministicOrder(): void
    {
        $where = [];
        $orders = [];
        $query = $this->createMock(QueryInterface::class);
        $query->method('where')->willReturnCallback(
            function (array|string $field, mixed $value = null, string $condition = '=') use (&$where, $query): QueryInterface {
                $where[] = [$field, $value, $condition];
                return $query;
            },
        );
        $query->method('order')->willReturnCallback(
            function (string $field = '', string $sort = 'DESC') use (&$orders, $query): QueryInterface {
                $orders[] = [$field, $sort];
                return $query;
            },
        );
        $query->method('select')->willReturnSelf();
        $query->expects(self::once())->method('fetchArray')->willReturn([
            $this->row(id: 5, key: 'b', scope: 'theme', locale: 'it_IT', value: 'it'),
            $this->row(id: 3, key: 'b', scope: 'theme', locale: null, value: 'base'),
            $this->row(id: 2, key: 'a', scope: 'default', locale: 'zh_Hans_CN', value: 'zh'),
            $this->row(id: 4, key: 'a', scope: 'outside', locale: 'it_IT', value: 'leak'),
        ]);

        $model = $this->createMock(MetaConfig::class);
        $model->expects(self::once())->method('newQuery')->willReturn($query);

        $records = $this->repository($model)->searchScopes($this->search(), ['theme', 'default']);

        self::assertContains(
            [MetaConfig::schema_fields_SCOPE, ['theme', 'default'], 'IN'],
            $where,
            'The whole scope chain must reach SQL as one IN predicate.',
        );
        self::assertSame(
            [
                [MetaConfig::schema_fields_CONFIG_KEY, 'ASC'],
                [MetaConfig::schema_fields_LOCALE, 'ASC'],
                [MetaConfig::schema_fields_ID, 'ASC'],
            ],
            $orders,
        );
        self::assertSame(
            [
                ['default', 'a', 'zh_Hans_CN'],
                ['theme', 'b', null],
                ['theme', 'b', 'it_IT'],
            ],
            array_map(
                static fn($record): array => [$record->scope, $record->configKey, $record->locale],
                $records,
            ),
        );
    }

    private function repository(MetaConfig $model): MetaConfigRepository
    {
        return new MetaConfigRepository(
            $model,
            $this->createStub(WriteIntentTransactionCoordinatorInterface::class),
        );
    }

    private function search(): MetaConfigSearch
    {
        return new MetaConfigSearch(
            namespace: 'theme.frontend',
            scope: 'theme',
            allLocales: true,
            identifyId: '42',
        );
    }

    /** @return array<string, mixed> */
    private function row(
        int $id,
        string $key,
        string $scope,
        ?string $locale,
        string $value,
    ): array {
        return [
            MetaConfig::schema_fields_ID => $id,
            MetaConfig::schema_fields_NAMESPACE => 'theme.frontend',
            MetaConfig::schema_fields_CONFIG_KEY => $key,
            MetaConfig::schema_fields_CONFIG_VALUE => $value,
            MetaConfig::schema_fields_SCOPE => $scope,
            MetaConfig::schema_fields_LOCALE => $locale,
            MetaConfig::schema_fields_IDENTIFY_ID => '42',
            MetaConfig::schema_fields_META_ID => null,
            MetaConfig::schema_fields_META_IDENTIFY => null,
        ];
    }
}
