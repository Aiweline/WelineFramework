<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Model\SearchServingAlias;
use Weline\Search\Model\SearchShardKey;

/**
 * Durable per-Website Search serving alias with compare-and-swap.
 *
 * Production uses ORM persistence and a writer-owned token. The isolated
 * in-memory implementation is available only through forTesting().
 */
final class SearchAliasStore
{
    public const ALIAS_DIRECT = 'direct';
    public const ALIAS_INDEX = 'index';

    private const MAX_CAS_ATTEMPTS = 8;

    /** @var array<int,array{alias:string,generation:int,version:int}>|null */
    private ?array $testingStates = null;
    private bool $forceConflictNextCas = false;

    public function __construct(
        private readonly ?SearchServingAlias $model,
    ) {
    }

    public static function forTesting(): self
    {
        $store = new self(null);
        $store->testingStates = [];

        return $store;
    }

    /** Testing only: next compareAndSwap returns conflict without mutating serving alias. */
    public function forceConflictNextCas(): void
    {
        if ($this->testingStates === null) {
            throw new \LogicException('search_alias_force_conflict_is_test_only');
        }
        $this->forceConflictNextCas = true;
    }

    /** @return array{website_id:int,alias:string,generation:int,version:int} */
    public function state(int $websiteId): array
    {
        SearchShardKey::fromWebsiteId($websiteId);
        if ($this->testingStates !== null) {
            $state = $this->testingStates[$websiteId] ?? [
                'alias' => self::ALIAS_DIRECT,
                'generation' => 0,
                'version' => 0,
            ];

            return ['website_id' => $websiteId] + $state;
        }

        $stored = $this->find($websiteId);
        if ($stored === null) {
            return [
                'website_id' => $websiteId,
                'alias' => self::ALIAS_DIRECT,
                'generation' => 0,
                'version' => 0,
            ];
        }

        return $this->normalize($stored->getData());
    }

    public function activeAlias(int $websiteId = 0): string
    {
        return $this->state($websiteId)['alias'];
    }

    public function activeGeneration(int $websiteId = 0): int
    {
        return $this->state($websiteId)['generation'];
    }

    public function version(int $websiteId = 0): int
    {
        return $this->state($websiteId)['version'];
    }

    /**
     * @return array{ok:bool,reason:string,website_id:int,alias:string,generation:int,version:int}
     */
    public function compareAndSwap(
        int $websiteId,
        string $expectedAlias,
        int $expectedGeneration,
        int $expectedVersion,
        string $nextAlias,
        int $nextGeneration,
    ): array {
        SearchShardKey::fromWebsiteId($websiteId);
        $this->assertAlias($expectedAlias, $expectedGeneration);
        $this->assertAlias($nextAlias, $nextGeneration);
        if ($expectedVersion < 0) {
            throw new \InvalidArgumentException('search_alias_expected_version_invalid');
        }

        if ($this->testingStates !== null) {
            return $this->compareAndSwapTesting(
                $websiteId,
                $expectedAlias,
                $expectedGeneration,
                $expectedVersion,
                $nextAlias,
                $nextGeneration,
            );
        }

        $current = $this->ensure($websiteId);
        $currentState = $this->normalize($current->getData());
        if ($currentState['version'] !== $expectedVersion
            || $currentState['alias'] !== $expectedAlias
            || $currentState['generation'] !== $expectedGeneration
        ) {
            return $this->conflict($currentState);
        }

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $writerToken = \bin2hex(\random_bytes(32));
            $candidate = $this->newModel()
                ->where(SearchServingAlias::schema_fields_WEBSITE_ID, $websiteId)
                ->where(SearchServingAlias::schema_fields_ACTIVE_ALIAS, $expectedAlias)
                ->where(SearchServingAlias::schema_fields_ACTIVE_GENERATION, $expectedGeneration)
                ->where(SearchServingAlias::schema_fields_ALIAS_VERSION, $expectedVersion)
                ->where(
                    SearchServingAlias::schema_fields_CAS_TOKEN,
                    (string)$current->getData(SearchServingAlias::schema_fields_CAS_TOKEN),
                );
            $candidate->getQuery()->update([
                SearchServingAlias::schema_fields_ACTIVE_ALIAS => $nextAlias,
                SearchServingAlias::schema_fields_ACTIVE_GENERATION => $nextGeneration,
                SearchServingAlias::schema_fields_ALIAS_VERSION => $expectedVersion + 1,
                SearchServingAlias::schema_fields_CAS_TOKEN => $writerToken,
                SearchServingAlias::schema_fields_UPDATED_AT => \gmdate('Y-m-d H:i:s'),
            ])->fetch();

            $winner = $this->find($websiteId);
            if ($winner !== null
                && \hash_equals(
                    $writerToken,
                    (string)$winner->getData(SearchServingAlias::schema_fields_CAS_TOKEN),
                )
            ) {
                return ['ok' => true, 'reason' => 'swapped']
                    + $this->normalize($winner->getData());
            }

            $latest = $winner === null
                ? $this->state($websiteId)
                : $this->normalize($winner->getData());

            return $this->conflict($latest);
        }

        return $this->conflict($this->state($websiteId));
    }

    /**
     * @return array{ok:bool,reason:string,website_id:int,alias:string,generation:int,version:int}
     */
    private function compareAndSwapTesting(
        int $websiteId,
        string $expectedAlias,
        int $expectedGeneration,
        int $expectedVersion,
        string $nextAlias,
        int $nextGeneration,
    ): array {
        $current = $this->state($websiteId);
        if ($this->forceConflictNextCas
            || $current['version'] !== $expectedVersion
            || $current['alias'] !== $expectedAlias
            || $current['generation'] !== $expectedGeneration
        ) {
            $this->forceConflictNextCas = false;

            return $this->conflict($current);
        }

        $this->testingStates[$websiteId] = [
            'alias' => $nextAlias,
            'generation' => $nextGeneration,
            'version' => $expectedVersion + 1,
        ];

        return ['ok' => true, 'reason' => 'swapped'] + $this->state($websiteId);
    }

    private function ensure(int $websiteId): SearchServingAlias
    {
        $existing = $this->find($websiteId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $this->newModel()->setData([
                SearchServingAlias::schema_fields_WEBSITE_ID => $websiteId,
                SearchServingAlias::schema_fields_ACTIVE_ALIAS => self::ALIAS_DIRECT,
                SearchServingAlias::schema_fields_ACTIVE_GENERATION => 0,
                SearchServingAlias::schema_fields_ALIAS_VERSION => 0,
                SearchServingAlias::schema_fields_CAS_TOKEN => '',
                SearchServingAlias::schema_fields_UPDATED_AT => \gmdate('Y-m-d H:i:s'),
            ])->save();
        } catch (\Throwable $insertError) {
            $concurrent = $this->find($websiteId);
            if ($concurrent === null) {
                throw $insertError;
            }

            return $concurrent;
        }

        return $this->find($websiteId)
            ?? throw new \RuntimeException('search_alias_create_readback_failed');
    }

    private function find(int $websiteId): ?SearchServingAlias
    {
        $alias = $this->newModel()
            ->where(SearchServingAlias::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();

        return $alias->getId() ? $alias : null;
    }

    private function newModel(): SearchServingAlias
    {
        if ($this->model === null) {
            throw new \LogicException('search_alias_database_model_required');
        }

        return (clone $this->model)->clearData()->clearQuery();
    }

    /** @param array<string,mixed> $data @return array{website_id:int,alias:string,generation:int,version:int} */
    private function normalize(array $data): array
    {
        return [
            'website_id' => (int)($data[SearchServingAlias::schema_fields_WEBSITE_ID] ?? -1),
            'alias' => (string)(
                $data[SearchServingAlias::schema_fields_ACTIVE_ALIAS] ?? self::ALIAS_DIRECT
            ),
            'generation' => (int)(
                $data[SearchServingAlias::schema_fields_ACTIVE_GENERATION] ?? 0
            ),
            'version' => (int)($data[SearchServingAlias::schema_fields_ALIAS_VERSION] ?? 0),
        ];
    }

    /**
     * @param array{website_id:int,alias:string,generation:int,version:int} $state
     * @return array{ok:bool,reason:string,website_id:int,alias:string,generation:int,version:int}
     */
    private function conflict(array $state): array
    {
        return ['ok' => false, 'reason' => 'cas_conflict'] + $state;
    }

    private function assertAlias(string $alias, int $generation): void
    {
        if (!\in_array($alias, [self::ALIAS_DIRECT, self::ALIAS_INDEX], true)
            || $generation < 0
            || ($alias === self::ALIAS_INDEX && $generation < 1)
        ) {
            throw new \InvalidArgumentException('search_alias_state_invalid');
        }
    }
}
