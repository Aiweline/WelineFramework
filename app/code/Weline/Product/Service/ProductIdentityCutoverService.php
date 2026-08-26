<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Throwable;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\ProductIdentityCutoverPolicyInterface;
use Weline\Product\Model\ProductIdentityCutoverState;

/**
 * Durable, optimistic state machine for the legacy -> Product/Offer V2 cutover.
 */
final class ProductIdentityCutoverService implements ProductIdentityCutoverPolicyInterface
{
    private const STATE_KEY = 'product_identity_v2';

    /** @var (\Closure(): ProductIdentityCutoverState)|null */
    private readonly mixed $stateFactory;

    /** @param (\Closure(): ProductIdentityCutoverState)|null $stateFactory */
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        ?callable $stateFactory = null,
    ) {
        $this->stateFactory = $stateFactory;
    }

    public function mode(): string
    {
        return (string)$this->current()['mode'];
    }

    public function legacyWritesAllowed(): bool
    {
        return $this->mode() !== self::MODE_V2_AUTHORITATIVE;
    }

    /**
     * Missing state/table means the pre-upgrade legacy mode. Mutating cutover
     * operations remain strict and will still fail until setup installs the table.
     *
     * @return array<string, int|string|bool|null>
     */
    public function current(): array
    {
        try {
            $row = $this->load();
            return $row === null ? $this->defaultSnapshot(false) : $this->snapshot($row);
        } catch (Throwable) {
            return $this->defaultSnapshot(false);
        }
    }

    /** @return array<string, int|string|bool|null> */
    public function markMigrated(string $sourceDigest): array
    {
        $sourceDigest = $this->digest($sourceDigest);

        return $this->transactions->run(
            $this->connectionFactory,
            function () use ($sourceDigest): array {
                $row = $this->load();
                if ($row === null) {
                    $now = date('Y-m-d H:i:s');
                    $this->newState()->clear()->setData([
                        ProductIdentityCutoverState::schema_fields_STATE_KEY => self::STATE_KEY,
                        ProductIdentityCutoverState::schema_fields_MODE => self::MODE_DUAL_READ,
                        ProductIdentityCutoverState::schema_fields_VERSION => 1,
                        ProductIdentityCutoverState::schema_fields_SOURCE_DIGEST => $sourceDigest,
                        ProductIdentityCutoverState::schema_fields_VERIFIED_DIGEST => '',
                        ProductIdentityCutoverState::schema_fields_VERIFIED_COUNT => 0,
                        ProductIdentityCutoverState::schema_fields_VERIFICATION_ERROR_COUNT => 0,
                        ProductIdentityCutoverState::schema_fields_VERIFIED_AT => null,
                        ProductIdentityCutoverState::schema_fields_SWITCHED_AT => null,
                        ProductIdentityCutoverState::schema_fields_CREATED_AT => $now,
                        ProductIdentityCutoverState::schema_fields_UPDATED_AT => $now,
                    ])->save();
                    return $this->snapshot($this->requireState());
                }

                $current = $this->snapshot($row);
                if ($current['mode'] === self::MODE_V2_AUTHORITATIVE) {
                    if (hash_equals((string)$current['source_digest'], $sourceDigest)) {
                        return $current;
                    }
                    throw new ProductV2ConflictException(
                        'product_v2_cutover_source_changed',
                        __('V2 权威切换后检测到旧身份源变化，请先回滚并重新迁移'),
                    );
                }
                if ($current['mode'] === self::MODE_DUAL_READ
                    && hash_equals((string)$current['source_digest'], $sourceDigest)
                ) {
                    return $current;
                }

                return $this->update(
                    $row,
                    (int)$current['version'],
                    [
                        ProductIdentityCutoverState::schema_fields_MODE => self::MODE_DUAL_READ,
                        ProductIdentityCutoverState::schema_fields_SOURCE_DIGEST => $sourceDigest,
                        ProductIdentityCutoverState::schema_fields_VERIFIED_DIGEST => '',
                        ProductIdentityCutoverState::schema_fields_VERIFIED_COUNT => 0,
                        ProductIdentityCutoverState::schema_fields_VERIFICATION_ERROR_COUNT => 0,
                        ProductIdentityCutoverState::schema_fields_VERIFIED_AT => null,
                    ],
                );
            },
        );
    }

    /** @return array<string, int|string|bool|null> */
    public function recordVerification(
        string $sourceDigest,
        bool $success,
        int $verifiedCount,
        int $errorCount,
    ): array {
        $sourceDigest = $this->digest($sourceDigest);
        if ($verifiedCount < 0 || $errorCount < 0) {
            throw new \InvalidArgumentException('product_v2_verification_count_invalid');
        }
        $success = $success && $errorCount === 0;

        return $this->transactions->run(
            $this->connectionFactory,
            function () use ($sourceDigest, $success, $verifiedCount, $errorCount): array {
                $row = $this->requireState();
                $current = $this->snapshot($row);
                if ($current['mode'] === self::MODE_LEGACY) {
                    throw new ProductV2ConflictException(
                        'product_v2_migration_apply_required',
                        __('请先在登记的非生产 clone 上执行 V2 身份迁移'),
                    );
                }
                return $this->update(
                    $row,
                    (int)$current['version'],
                    [
                        ProductIdentityCutoverState::schema_fields_SOURCE_DIGEST => $sourceDigest,
                        ProductIdentityCutoverState::schema_fields_VERIFIED_DIGEST
                            => $success ? $sourceDigest : '',
                        ProductIdentityCutoverState::schema_fields_VERIFIED_COUNT
                            => $success ? $verifiedCount : 0,
                        ProductIdentityCutoverState::schema_fields_VERIFICATION_ERROR_COUNT
                            => $errorCount,
                        ProductIdentityCutoverState::schema_fields_VERIFIED_AT
                            => $success ? date('Y-m-d H:i:s') : null,
                    ],
                );
            },
        );
    }

    /** @return array<string, int|string|bool|null> */
    public function cutover(string $currentSourceDigest, int $expectedVersion): array
    {
        $currentSourceDigest = $this->digest($currentSourceDigest);

        return $this->transactions->run(
            $this->connectionFactory,
            function () use ($currentSourceDigest, $expectedVersion): array {
                $row = $this->requireState();
                $current = $this->snapshot($row);
                $this->assertVersion($current, $expectedVersion);

                if (!hash_equals((string)$current['source_digest'], $currentSourceDigest)) {
                    throw new ProductV2ConflictException(
                        'product_v2_cutover_source_changed',
                        __('旧身份源已变化，请重新执行迁移验证'),
                    );
                }
                if ((string)$current['verified_digest'] === ''
                    || !hash_equals((string)$current['verified_digest'], $currentSourceDigest)
                    || (int)$current['verification_error_count'] !== 0
                ) {
                    throw new ProductV2ConflictException(
                        'product_v2_cutover_not_verified',
                        __('当前旧身份源尚未通过完整 V2 验证'),
                    );
                }
                if ($current['mode'] === self::MODE_V2_AUTHORITATIVE) {
                    return $current;
                }
                if ($current['mode'] !== self::MODE_DUAL_READ) {
                    throw new ProductV2ConflictException(
                        'product_v2_cutover_mode_invalid',
                        __('只有 dual_read 状态可以切换为 V2 权威'),
                        ['mode' => $current['mode']],
                    );
                }

                return $this->update(
                    $row,
                    $expectedVersion,
                    [
                        ProductIdentityCutoverState::schema_fields_MODE
                            => self::MODE_V2_AUTHORITATIVE,
                        ProductIdentityCutoverState::schema_fields_SWITCHED_AT
                            => date('Y-m-d H:i:s'),
                    ],
                );
            },
        );
    }

    /** @return array<string, int|string|bool|null> */
    public function rollback(
        int $expectedVersion,
        string $targetMode = self::MODE_DUAL_READ,
    ): array {
        if (!in_array($targetMode, [self::MODE_LEGACY, self::MODE_DUAL_READ], true)) {
            throw new \InvalidArgumentException('product_v2_rollback_mode_invalid');
        }

        return $this->transactions->run(
            $this->connectionFactory,
            function () use ($expectedVersion, $targetMode): array {
                $row = $this->requireState();
                $current = $this->snapshot($row);
                $this->assertVersion($current, $expectedVersion);
                if ($current['mode'] === $targetMode) {
                    return $current;
                }
                return $this->update(
                    $row,
                    $expectedVersion,
                    [
                        ProductIdentityCutoverState::schema_fields_MODE => $targetMode,
                        ProductIdentityCutoverState::schema_fields_VERIFIED_DIGEST => '',
                        ProductIdentityCutoverState::schema_fields_VERIFIED_COUNT => 0,
                        ProductIdentityCutoverState::schema_fields_VERIFICATION_ERROR_COUNT => 0,
                        ProductIdentityCutoverState::schema_fields_VERIFIED_AT => null,
                    ],
                );
            },
        );
    }

    /**
     * @param array<string, int|string|bool|null> $current
     */
    private function assertVersion(array $current, int $expectedVersion): void
    {
        $actual = (int)$current['version'];
        if ($expectedVersion < 0 || $actual !== $expectedVersion) {
            throw new ProductV2ConflictException(
                'product_v2_cutover_version_conflict',
                __('V2 切换状态版本已变化，请刷新后重试'),
                ['expected_version' => $expectedVersion, 'actual_version' => $actual],
            );
        }
    }

    /**
     * @param array<string, mixed> $updates
     * @return array<string, int|string|bool|null>
     */
    private function update(
        ProductIdentityCutoverState $row,
        int $expectedVersion,
        array $updates,
    ): array {
        $nextVersion = $expectedVersion + 1;
        $updates[ProductIdentityCutoverState::schema_fields_VERSION] = $nextVersion;
        $updates[ProductIdentityCutoverState::schema_fields_UPDATED_AT] = date('Y-m-d H:i:s');

        $this->newState()->clear()->getQuery()
            ->where(ProductIdentityCutoverState::schema_fields_ID, (int)$row->getId())
            ->where(ProductIdentityCutoverState::schema_fields_VERSION, $expectedVersion)
            ->update($updates)
            ->fetch();

        $current = $this->requireState();
        if ((int)$current->getData(ProductIdentityCutoverState::schema_fields_VERSION)
            !== $nextVersion
        ) {
            throw new ProductV2ConflictException(
                'product_v2_cutover_version_conflict',
                __('V2 切换状态版本已变化，请刷新后重试'),
                ['expected_version' => $expectedVersion],
            );
        }
        foreach ([
            ProductIdentityCutoverState::schema_fields_MODE,
            ProductIdentityCutoverState::schema_fields_SOURCE_DIGEST,
            ProductIdentityCutoverState::schema_fields_VERIFIED_DIGEST,
        ] as $field) {
            if (array_key_exists($field, $updates)
                && (string)$current->getData($field) !== (string)$updates[$field]
            ) {
                throw new ProductV2ConflictException(
                    'product_v2_cutover_version_conflict',
                    __('V2 切换状态被并发修改，请刷新后重试'),
                    ['field' => $field],
                );
            }
        }
        return $this->snapshot($current);
    }

    private function digest(string $digest): string
    {
        $digest = strtolower(trim($digest));
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new \InvalidArgumentException('product_v2_source_digest_invalid');
        }
        return $digest;
    }

    private function load(): ?ProductIdentityCutoverState
    {
        $row = $this->newState()->clear()
            ->where(ProductIdentityCutoverState::schema_fields_STATE_KEY, self::STATE_KEY)
            ->find()
            ->fetch();
        return $row->getId() ? $row : null;
    }

    private function requireState(): ProductIdentityCutoverState
    {
        return $this->load() ?? throw new ProductV2ConflictException(
            'product_v2_migration_apply_required',
            __('请先在登记的非生产 clone 上执行 V2 身份迁移'),
        );
    }

    /** @return array<string, int|string|bool|null> */
    private function snapshot(ProductIdentityCutoverState $row): array
    {
        $mode = (string)$row->getData(ProductIdentityCutoverState::schema_fields_MODE);
        if (!in_array($mode, [
            self::MODE_LEGACY,
            self::MODE_DUAL_READ,
            self::MODE_V2_AUTHORITATIVE,
        ], true)) {
            // Corrupt state fails closed: V2 reads remain preferred and legacy writes stop.
            $mode = self::MODE_V2_AUTHORITATIVE;
        }
        return [
            'state_available' => true,
            'mode' => $mode,
            'version' => (int)$row->getData(ProductIdentityCutoverState::schema_fields_VERSION),
            'source_digest' => (string)$row->getData(
                ProductIdentityCutoverState::schema_fields_SOURCE_DIGEST,
            ),
            'verified_digest' => (string)$row->getData(
                ProductIdentityCutoverState::schema_fields_VERIFIED_DIGEST,
            ),
            'verified_count' => (int)$row->getData(
                ProductIdentityCutoverState::schema_fields_VERIFIED_COUNT,
            ),
            'verification_error_count' => (int)$row->getData(
                ProductIdentityCutoverState::schema_fields_VERIFICATION_ERROR_COUNT,
            ),
            'verified_at' => $this->nullableString(
                $row->getData(ProductIdentityCutoverState::schema_fields_VERIFIED_AT),
            ),
            'switched_at' => $this->nullableString(
                $row->getData(ProductIdentityCutoverState::schema_fields_SWITCHED_AT),
            ),
            'updated_at' => $this->nullableString(
                $row->getData(ProductIdentityCutoverState::schema_fields_UPDATED_AT),
            ),
        ];
    }

    /** @return array<string, int|string|bool|null> */
    private function defaultSnapshot(bool $available): array
    {
        return [
            'state_available' => $available,
            'mode' => self::MODE_LEGACY,
            'version' => 0,
            'source_digest' => '',
            'verified_digest' => '',
            'verified_count' => 0,
            'verification_error_count' => 0,
            'verified_at' => null,
            'switched_at' => null,
            'updated_at' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function newState(): ProductIdentityCutoverState
    {
        if ($this->stateFactory !== null) {
            return ($this->stateFactory)();
        }
        return ObjectManager::make(ProductIdentityCutoverState::class);
    }
}
