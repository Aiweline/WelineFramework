<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Throwable;
use Weline\B2B\Model\B2BQuoteToken;
use Weline\B2B\Model\B2BQuoteTokenRecord;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;

/** Durable immutable token store with transactional one-time consumption. */
final class B2BQuoteTokenStore
{
    public const RESULT_CONSUMED = 'consumed';
    public const RESULT_NOT_FOUND = 'not_found';
    public const RESULT_NOT_OPEN = 'not_open';
    public const RESULT_EXPIRED = 'expired';
    public const ERROR_IMMUTABLE = 'b2b_quote_token_immutable';

    /** @var array<string, B2BQuoteToken>|null */
    private ?array $rows = null;

    /** @var (\Closure(): B2BQuoteTokenRecord)|null */
    private readonly ?\Closure $recordFactory;

    /**
     * @param (callable(): B2BQuoteTokenRecord)|null $recordFactory
     */
    public function __construct(
        ?callable $recordFactory = null,
        private ?DatabaseTransactionRunnerInterface $transactions = null,
        bool $useMemory = false,
    ) {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->rows = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    public function isMemory(): bool
    {
        return $this->rows !== null;
    }

    public function connection(): ConnectionFactory
    {
        return $this->newRecord()->getConnection();
    }

    public function put(B2BQuoteToken $token): void
    {
        if ($this->rows !== null) {
            if (isset($this->rows[$token->tokenId])) {
                throw $this->immutable($token->tokenId);
            }
            $this->rows[$token->tokenId] = $token;
            return;
        }

        if ($this->findModel($token->tokenId) !== null) {
            throw $this->immutable($token->tokenId);
        }
        try {
            $this->newRecord()->clear()->setData($this->recordData($token))->save();
        } catch (Throwable $exception) {
            if ($this->findModel($token->tokenId) !== null) {
                throw $this->immutable($token->tokenId, $exception);
            }
            throw $exception;
        }
    }

    public function get(string $tokenId): ?B2BQuoteToken
    {
        $tokenId = trim($tokenId);
        if ($tokenId === '') {
            return null;
        }
        if ($this->rows !== null) {
            return $this->rows[$tokenId] ?? null;
        }
        $model = $this->findModel($tokenId);
        return $model !== null ? $this->hydrate($model->getData()) : null;
    }

    /**
     * Atomically persists the snapshot callback and consumes the token.
     *
     * @param callable(B2BQuoteToken): void $snapshotWriter
     */
    public function consumeWith(
        string $tokenId,
        string $orderRef,
        int $nowEpoch,
        callable $snapshotWriter,
    ): string {
        if ($this->rows !== null) {
            $token = $this->rows[$tokenId] ?? null;
            if ($token === null) {
                return self::RESULT_NOT_FOUND;
            }
            if ($token->status() !== B2BQuoteToken::STATUS_OPEN) {
                return self::RESULT_NOT_OPEN;
            }
            if ($token->isExpired($nowEpoch)) {
                return self::RESULT_EXPIRED;
            }
            $snapshotWriter($token);
            $token->markConsumed($orderRef);
            $this->rows[$tokenId] = $token;
            return self::RESULT_CONSUMED;
        }

        return $this->transactionRunner()->run(
            $this->connection(),
            function () use ($tokenId, $orderRef, $nowEpoch, $snapshotWriter): string {
                $model = $this->findModel($tokenId, true);
                if ($model === null) {
                    return self::RESULT_NOT_FOUND;
                }
                $token = $this->hydrate($model->getData());
                if ($token->status() !== B2BQuoteToken::STATUS_OPEN) {
                    return self::RESULT_NOT_OPEN;
                }
                if ($token->isExpired($nowEpoch)) {
                    return self::RESULT_EXPIRED;
                }

                $snapshotWriter($token);
                $token->markConsumed($orderRef);
                $model
                    ->setData(B2BQuoteTokenRecord::schema_fields_STATUS, $token->status())
                    ->setData(B2BQuoteTokenRecord::schema_fields_CONSUMED_ORDER_REF, $orderRef)
                    ->setData(B2BQuoteTokenRecord::schema_fields_CONSUMED_AT_EPOCH, $nowEpoch)
                    ->save();
                return self::RESULT_CONSUMED;
            },
        );
    }

    public function count(): int
    {
        if ($this->rows !== null) {
            return count($this->rows);
        }
        return count($this->newRecord()->clear()->select()->fetchArray());
    }

    /** @return array<string,mixed> */
    private function recordData(B2BQuoteToken $token): array
    {
        return [
            B2BQuoteTokenRecord::schema_fields_TOKEN_ID => $token->tokenId,
            B2BQuoteTokenRecord::schema_fields_CUSTOMER_ID => $token->customerId,
            B2BQuoteTokenRecord::schema_fields_WEBSITE_ID => $token->websiteId,
            B2BQuoteTokenRecord::schema_fields_SKU => $token->sku,
            B2BQuoteTokenRecord::schema_fields_RETAIL_AMOUNT_MINOR => $token->retailAmountMinor,
            B2BQuoteTokenRecord::schema_fields_AMOUNT_MINOR => $token->amountMinor,
            B2BQuoteTokenRecord::schema_fields_SOURCE => $token->source,
            B2BQuoteTokenRecord::schema_fields_GROUP_ID => $token->groupId,
            B2BQuoteTokenRecord::schema_fields_PRICE_LIST_ID => $token->priceListId,
            B2BQuoteTokenRecord::schema_fields_VERSION => $token->version,
            B2BQuoteTokenRecord::schema_fields_CHANNEL_ID => $token->channelId,
            B2BQuoteTokenRecord::schema_fields_RULE_STACK_JSON => json_encode(
                $token->ruleStack,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            B2BQuoteTokenRecord::schema_fields_FINGERPRINT => $token->fingerprint,
            B2BQuoteTokenRecord::schema_fields_ISSUED_AT_EPOCH => $token->issuedAtEpoch,
            B2BQuoteTokenRecord::schema_fields_EXPIRES_AT_EPOCH => $token->expiresAtEpoch,
            B2BQuoteTokenRecord::schema_fields_STATUS => $token->status(),
            B2BQuoteTokenRecord::schema_fields_CONSUMED_ORDER_REF => $token->consumedOrderRef(),
            B2BQuoteTokenRecord::schema_fields_CONSUMED_AT_EPOCH => null,
            B2BQuoteTokenRecord::schema_fields_CREATED_AT => gmdate('Y-m-d H:i:s', $token->issuedAtEpoch),
        ];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): B2BQuoteToken
    {
        $rules = json_decode(
            (string)($row[B2BQuoteTokenRecord::schema_fields_RULE_STACK_JSON] ?? '[]'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        return new B2BQuoteToken(
            tokenId: (string)$row[B2BQuoteTokenRecord::schema_fields_TOKEN_ID],
            customerId: (string)$row[B2BQuoteTokenRecord::schema_fields_CUSTOMER_ID],
            websiteId: (int)$row[B2BQuoteTokenRecord::schema_fields_WEBSITE_ID],
            sku: (string)$row[B2BQuoteTokenRecord::schema_fields_SKU],
            retailAmountMinor: (int)$row[B2BQuoteTokenRecord::schema_fields_RETAIL_AMOUNT_MINOR],
            amountMinor: (int)$row[B2BQuoteTokenRecord::schema_fields_AMOUNT_MINOR],
            source: (string)$row[B2BQuoteTokenRecord::schema_fields_SOURCE],
            groupId: $this->optionalString($row, B2BQuoteTokenRecord::schema_fields_GROUP_ID),
            priceListId: $this->optionalString($row, B2BQuoteTokenRecord::schema_fields_PRICE_LIST_ID),
            version: $this->optionalInt($row, B2BQuoteTokenRecord::schema_fields_VERSION),
            channelId: $this->optionalString($row, B2BQuoteTokenRecord::schema_fields_CHANNEL_ID),
            ruleStack: is_array($rules) ? array_values($rules) : [],
            fingerprint: (string)$row[B2BQuoteTokenRecord::schema_fields_FINGERPRINT],
            issuedAtEpoch: (int)$row[B2BQuoteTokenRecord::schema_fields_ISSUED_AT_EPOCH],
            expiresAtEpoch: (int)$row[B2BQuoteTokenRecord::schema_fields_EXPIRES_AT_EPOCH],
            status: (string)$row[B2BQuoteTokenRecord::schema_fields_STATUS],
            consumedOrderRef: $this->optionalString(
                $row,
                B2BQuoteTokenRecord::schema_fields_CONSUMED_ORDER_REF,
            ),
        );
    }

    private function findModel(
        string $tokenId,
        bool $lockingRead = false,
    ): ?B2BQuoteTokenRecord
    {
        $model = $this->newRecord();
        $model->clear()
            ->where(B2BQuoteTokenRecord::schema_fields_TOKEN_ID, trim($tokenId));
        if ($lockingRead && $this->supportsForUpdate($model)) {
            $model->additional('FOR UPDATE');
        }
        $model->find()->fetch();
        return $model->getId() ? $model : null;
    }

    private function supportsForUpdate(B2BQuoteTokenRecord $model): bool
    {
        $type = strtolower((string)$model->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());

        return in_array(
            $type,
            ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'],
            true,
        );
    }

    private function immutable(string $tokenId, ?Throwable $previous = null): B2BConflictException
    {
        return new B2BConflictException(
            self::ERROR_IMMUTABLE,
            __('B2B quote token 不可覆盖：%{1}', [$tokenId]),
            ['token_id' => $tokenId],
            0,
            $previous,
        );
    }

    private function transactionRunner(): DatabaseTransactionRunnerInterface
    {
        $runner = $this->transactions
            ?? ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
        if (!$runner instanceof DatabaseTransactionRunnerInterface) {
            throw new \LogicException('DatabaseTransactionRunnerInterface is unavailable');
        }
        return $runner;
    }

    private function newRecord(): B2BQuoteTokenRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(B2BQuoteTokenRecord::class, [], false);
    }

    /** @param array<string,mixed> $row */
    private function optionalString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        return $value !== null && $value !== '' ? (string)$value : null;
    }

    /** @param array<string,mixed> $row */
    private function optionalInt(array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;
        return $value !== null && $value !== '' ? (int)$value : null;
    }
}
