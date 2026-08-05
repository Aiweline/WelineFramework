<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

/**
 * Thrown when a module version already has an immutable Schema checkpoint
 * that does not match the currently declared Model fingerprints.
 */
final class SchemaCheckpointConflictException extends \RuntimeException
{
    /**
     * @param list<string> $changedTables
     * @param list<string> $addedTables
     * @param list<string> $removedTables
     */
    public function __construct(
        private readonly string $moduleName,
        private readonly string $moduleVersion,
        private readonly ?string $suggestedVersion,
        private readonly array $changedTables,
        private readonly array $addedTables,
        private readonly array $removedTables,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $this->buildDefaultMessage(), $code, $previous);
    }

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    public function getModuleVersion(): string
    {
        return $this->moduleVersion;
    }

    public function getSuggestedVersion(): ?string
    {
        return $this->suggestedVersion;
    }

    /** @return list<string> */
    public function getChangedTables(): array
    {
        return $this->changedTables;
    }

    /** @return list<string> */
    public function getAddedTables(): array
    {
        return $this->addedTables;
    }

    /** @return list<string> */
    public function getRemovedTables(): array
    {
        return $this->removedTables;
    }

    private function buildDefaultMessage(): string
    {
        $diffTables = array_slice(array_values(array_unique(array_merge(
            $this->changedTables,
            $this->addedTables,
            $this->removedTables,
        ))), 0, 20);
        $diffText = $diffTables === [] ? '(checksum only)' : implode(', ', $diffTables);
        $suggest = $this->suggestedVersion !== null && $this->suggestedVersion !== ''
            ? __('请将 etc/module.php version 改为 %{1} 后重跑 setup:upgrade', [$this->suggestedVersion])
            : __('请提升 etc/module.php version 后重跑 setup:upgrade');

        return (string)__(
            "模块 %{1} 版本 %{2} 已存在不同的 Schema checkpoint。\n差异表：%{3}\n%{4}\n排查：php bin/w setup:schema:check -m %{1}",
            [$this->moduleName, $this->moduleVersion, $diffText, $suggest]
        );
    }
}
