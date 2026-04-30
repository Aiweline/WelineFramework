<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class ContractMetaBuilder
{
    /**
     * @param callable(array<string, mixed>):string|null $idFactory
     * @param callable():string|null $clock
     */
    public function __construct(
        private readonly ?ContractType $contractType = null,
        private readonly mixed $idFactory = null,
        private readonly mixed $clock = null
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function build(
        string $type,
        ?string $stage = null,
        string $status = ContractType::STATUS_DRAFT,
        string $creator = 'pagebuilder',
        string $adapterType = 'json_strict',
        array $context = []
    ): array {
        $typeService = $this->contractType ?? new ContractType();
        $resolvedStage = $stage !== null && $stage !== '' ? $stage : $typeService->defaultStageForType($type);
        $seed = [
            'version' => ContractType::VERSION_V1,
            'type' => $type,
            'stage' => $resolvedStage,
            'creator' => $creator,
            'adapter_type' => $adapterType,
        ] + $context;

        $id = $this->buildId($seed);

        return [
            'id' => $id,
            'contract_id' => $id,
            'version' => ContractType::VERSION_V1,
            'type' => $type,
            'stage' => $resolvedStage,
            'status' => $status,
            'creator' => $creator,
            'adapter_type' => $adapterType,
            'created_at' => $this->now(),
        ];
    }

    /**
     * @param array<string, mixed> $seed
     */
    private function buildId(array $seed): string
    {
        if (\is_callable($this->idFactory)) {
            return (string)\call_user_func($this->idFactory, $seed);
        }

        return 'contract_' . \substr(\sha1((string)\json_encode($seed, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)), 0, 20);
    }

    private function now(): string
    {
        if (\is_callable($this->clock)) {
            return (string)\call_user_func($this->clock);
        }

        return \gmdate('c');
    }
}
