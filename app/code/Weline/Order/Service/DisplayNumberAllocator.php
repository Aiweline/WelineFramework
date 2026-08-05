<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\Data\DisplayNumberRef;
use Weline\Order\Model\DisplayNumberRegistry;

/**
 * Allocates kind-qualified display numbers with random_int（DEC-017）.
 * Unique key: (website_id, store_id, number_kind, display_number); max 5 collision retries.
 * DB 模式写 weline_display_number_registry；memory 模式供单测/harness。
 */
final class DisplayNumberAllocator
{
    public const MAX_ATTEMPTS = 5;
    public const ERROR_EXHAUSTED = 'display_number_allocate_exhausted';
    public const ERROR_KIND = 'display_number_kind_invalid';

    /** Digits in generated display number (customer-facing). */
    public const DIGITS = 10;

    /**
     * @var array<string, array{website_id:int,store_id:int,number_kind:string,display_number:string,entity_uuid:string}>
     */
    private array $memory = [];

    private bool $useMemory;

    /** @var (\Closure(): int)|null */
    private $randomInt;

    /**
     * @param (\Closure(): int)|null $randomInt Override for tests
     */
    public function __construct(
        bool $useMemory = false,
        ?callable $randomInt = null,
        private ?DisplayNumberRegistry $registryModel = null,
    ) {
        $this->useMemory = $useMemory;
        $this->randomInt = $randomInt;
    }

    public static function forTesting(?callable $randomInt = null): self
    {
        return new self(useMemory: true, randomInt: $randomInt);
    }

    public function allocate(
        int $websiteId,
        int $storeId,
        string $numberKind,
        string $entityUuid,
    ): DisplayNumberRef {
        $kind = $this->normalizeKind($numberKind);
        $entityUuid = trim($entityUuid);
        if ($entityUuid === '') {
            throw new \InvalidArgumentException(\__('entity_uuid 不能为空'));
        }
        if ($websiteId < 0 || $storeId < 0) {
            throw new \InvalidArgumentException(\__('website_id/store_id 须 >=0'));
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $display = $this->nextDisplayNumber();
            if ($this->claim($websiteId, $storeId, $kind, $display, $entityUuid)) {
                return new DisplayNumberRef($kind, $display, $entityUuid, $websiteId, $storeId);
            }
        }

        throw new OrderFacadeConflictException(
            self::ERROR_EXHAUSTED,
            \__('展示号分配冲突耗尽（最多 %{1} 次）', [self::MAX_ATTEMPTS]),
            ['number_kind' => $kind, 'website_id' => $websiteId, 'store_id' => $storeId],
        );
    }

    /** Seed an existing assignment（tests / replay）. */
    public function seed(
        int $websiteId,
        int $storeId,
        string $numberKind,
        string $displayNumber,
        string $entityUuid,
    ): DisplayNumberRef {
        $kind = $this->normalizeKind($numberKind);
        $displayNumber = trim($displayNumber);
        $entityUuid = trim($entityUuid);
        $this->assertAssignment($websiteId, $storeId, $displayNumber, $entityUuid);
        if ($this->claim($websiteId, $storeId, $kind, $displayNumber, $entityUuid)) {
            return new DisplayNumberRef($kind, $displayNumber, $entityUuid, $websiteId, $storeId);
        }

        throw new OrderFacadeConflictException(
            self::ERROR_EXHAUSTED,
            \__('展示号已被占用'),
            [
                'number_kind' => $kind,
                'display_number' => $displayNumber,
                'website_id' => $websiteId,
                'store_id' => $storeId,
            ],
        );
    }

    public function normalizeKind(string $numberKind): string
    {
        $kind = strtolower(trim($numberKind));
        $allowed = [
            DisplayNumberRegistry::KIND_ORDER,
            DisplayNumberRegistry::KIND_INVOICE,
            DisplayNumberRegistry::KIND_REFUND,
        ];
        if (!in_array($kind, $allowed, true)) {
            throw new OrderFacadeConflictException(
                self::ERROR_KIND,
                \__('非法 number_kind：%{1}', [$numberKind]),
                ['number_kind' => $numberKind],
            );
        }
        return $kind;
    }

    private function nextDisplayNumber(): string
    {
        if ($this->randomInt !== null) {
            $n = (int)($this->randomInt)();
        } else {
            $max = (10 ** self::DIGITS) - 1;
            $n = random_int(0, $max);
        }
        $max = (10 ** self::DIGITS) - 1;
        if ($n < 0 || $n > $max) {
            throw new \UnexpectedValueException(\__('展示号随机源返回值越界'));
        }
        return str_pad((string)$n, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function slotKey(int $websiteId, int $storeId, string $kind, string $display): string
    {
        return $websiteId . ':' . $storeId . ':' . $kind . ':' . $display;
    }

    public function lookup(
        int $websiteId,
        int $storeId,
        string $numberKind,
        string $displayNumber,
    ): ?DisplayNumberRef
    {
        $kind = $this->normalizeKind($numberKind);
        $this->assertAssignment($websiteId, $storeId, $displayNumber);
        $slotKey = $this->slotKey($websiteId, $storeId, $kind, $displayNumber);
        if ($this->useMemory) {
            $row = $this->memory[$slotKey] ?? null;
            if ($row === null) {
                return null;
            }

            return new DisplayNumberRef(
                $kind,
                $displayNumber,
                (string)$row['entity_uuid'],
                $websiteId,
                $storeId,
            );
        }
        $row = $this->registry()->clear()
            ->where(DisplayNumberRegistry::schema_fields_WEBSITE_ID, (int)$websiteId)
            ->where(DisplayNumberRegistry::schema_fields_STORE_ID, (int)$storeId)
            ->where(DisplayNumberRegistry::schema_fields_NUMBER_KIND, $kind)
            ->where(DisplayNumberRegistry::schema_fields_DISPLAY_NUMBER, $displayNumber)
            ->find()
            ->fetch();

        if (!$row instanceof DisplayNumberRegistry || !$row->getId()) {
            return null;
        }

        return new DisplayNumberRef(
            $kind,
            $displayNumber,
            (string)$row->getData(DisplayNumberRegistry::schema_fields_ENTITY_UUID),
            $websiteId,
            $storeId,
        );
    }

    private function claim(
        int $websiteId,
        int $storeId,
        string $kind,
        string $display,
        string $entityUuid,
    ): bool {
        $this->assertAssignment($websiteId, $storeId, $display, $entityUuid);
        $slotKey = $this->slotKey($websiteId, $storeId, $kind, $display);
        if ($this->useMemory) {
            $existing = $this->memory[$slotKey] ?? null;
            if ($existing !== null) {
                return (string)$existing['entity_uuid'] === $entityUuid;
            }
            $this->memory[$slotKey] = [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'number_kind' => $kind,
                'display_number' => $display,
                'entity_uuid' => $entityUuid,
            ];
            return true;
        }

        $registry = $this->registry();
        $data = [
            DisplayNumberRegistry::schema_fields_WEBSITE_ID => $websiteId,
            DisplayNumberRegistry::schema_fields_STORE_ID => $storeId,
            DisplayNumberRegistry::schema_fields_NUMBER_KIND => $kind,
            DisplayNumberRegistry::schema_fields_DISPLAY_NUMBER => $display,
            DisplayNumberRegistry::schema_fields_ENTITY_UUID => $entityUuid,
        ];
        $conflictFields = [
            DisplayNumberRegistry::schema_fields_WEBSITE_ID,
            DisplayNumberRegistry::schema_fields_STORE_ID,
            DisplayNumberRegistry::schema_fields_NUMBER_KIND,
            DisplayNumberRegistry::schema_fields_DISPLAY_NUMBER,
        ];
        $connection = $registry->getConnection()
            ->getConnector()
            ->getWrappedConnection();
        $driver = strtolower($connection->getDriverType());
        $table = $this->quoteIdentifier($registry->getTable(), $driver);
        $columns = array_keys($data);
        $quotedColumns = array_map(
            fn (string $column): string => $this->quoteIdentifier($column, $driver),
            $columns,
        );
        $placeholders = array_map(
            static fn (string $column): string => ':' . $column,
            $columns,
        );
        $sql = 'INSERT INTO ' . $table
            . ' (' . implode(', ', $quotedColumns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';
        if ($driver === 'mysql') {
            $displayColumn = $this->quoteIdentifier(
                DisplayNumberRegistry::schema_fields_DISPLAY_NUMBER,
                $driver,
            );
            $sql .= ' ON DUPLICATE KEY UPDATE '
                . $displayColumn . ' = ' . $displayColumn;
        } else {
            $quotedConflicts = array_map(
                fn (string $column): string => $this->quoteIdentifier($column, $driver),
                $conflictFields,
            );
            $sql .= ' ON CONFLICT (' . implode(', ', $quotedConflicts) . ') DO NOTHING';
        }
        $statement = $connection->prepare($sql);
        if (!$statement->execute($data)) {
            throw new \RuntimeException(\__('展示号原子占位失败'));
        }

        return $this->lookup($websiteId, $storeId, $kind, $display)?->entityUuid === $entityUuid;
    }

    private function quoteIdentifier(string $identifier, string $driver): string
    {
        $quote = $driver === 'mysql' ? '`' : '"';
        $parts = array_filter(
            array_map('trim', explode('.', str_replace(['`', '"'], '', $identifier))),
            static fn (string $part): bool => $part !== '',
        );
        if ($parts === []) {
            throw new \InvalidArgumentException(\__('数据库标识符不能为空'));
        }

        return implode(
            '.',
            array_map(
                static fn (string $part): string => $quote
                    . str_replace($quote, $quote . $quote, $part)
                    . $quote,
                $parts,
            ),
        );
    }

    /** DB 模式：释放本次已占用展示号（写入失败补偿清理）。 */
    public function releaseForEntity(string $entityUuid): void
    {
        $entityUuid = trim($entityUuid);
        if ($entityUuid === '') {
            throw new \InvalidArgumentException(\__('entity_uuid 不能为空'));
        }
        if ($this->useMemory) {
            foreach ($this->memory as $slotKey => $row) {
                if (($row['entity_uuid'] ?? '') === $entityUuid) {
                    unset($this->memory[$slotKey]);
                }
            }
            return;
        }
        $this->registry()->clear()
            ->where(DisplayNumberRegistry::schema_fields_ENTITY_UUID, $entityUuid)
            ->delete();
    }

    private function assertAssignment(
        int $websiteId,
        int $storeId,
        string $displayNumber,
        ?string $entityUuid = null,
    ): void {
        if ($websiteId < 0 || $storeId < 0) {
            throw new \InvalidArgumentException(\__('website_id/store_id 须 >=0'));
        }
        if (\preg_match('/^[0-9]{10}$/D', $displayNumber) !== 1) {
            throw new \InvalidArgumentException(\__('display_number 必须是 10 位数字'));
        }
        if ($entityUuid !== null
            && (trim($entityUuid) === '' || \strlen($entityUuid) > 36)
        ) {
            throw new \InvalidArgumentException(\__('entity_uuid 不能为空且不能超过 36 字符'));
        }
    }

    private function registry(): DisplayNumberRegistry
    {
        // 每次取全新实例，避免共享 Model 残留主键把 insert 变 update。
        $this->registryModel ??= new DisplayNumberRegistry();
        $fresh = clone $this->registryModel;

        return $fresh->clear();
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if (!$this->useMemory) {
            throw new \RuntimeException(\__('DisplayNumberAllocator all 仅 memory 模式'));
        }

        return $this->memory;
    }

    /** @param array<string, array<string, mixed>> $rows */
    public function replaceMemory(array $rows): void
    {
        if (!$this->useMemory) {
            throw new \RuntimeException(\__('DisplayNumberAllocator replaceMemory 仅 memory 模式'));
        }
        $this->memory = $rows;
    }
}
