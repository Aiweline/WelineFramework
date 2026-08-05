<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Api\PaymentEffectOutboxProcessorInterface;
use Weline\Payment\Model\PaymentOutbox;

/**
 * Locks and completes Payment outbox effects on the default connector.
 */
final class PaymentEffectOutboxProcessor implements PaymentEffectOutboxProcessorInterface
{
    public const ERROR_NOT_FOUND = 'payment_effect_outbox_not_found';
    public const ERROR_NOT_PENDING = 'payment_effect_outbox_not_pending';
    public const ERROR_HANDLER_FAILED = 'payment_effect_handler_failed';

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public function pendingCodes(array $effectTypes, int $limit = 20): array
    {
        $effectTypes = array_values(array_unique(array_filter(
            array_map(static fn (mixed $type): string => trim((string)$type), $effectTypes),
            static fn (string $type): bool => $type !== '',
        )));
        if ($effectTypes === []) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $rows = $this->newModel(PaymentOutbox::class)
            ->where(PaymentOutbox::schema_fields_STATUS, PaymentOutbox::STATUS_PENDING)
            ->where(PaymentOutbox::schema_fields_EFFECT_TYPE, $effectTypes, 'IN')
            ->order(PaymentOutbox::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        $codes = [];
        foreach ($rows as $row) {
            $code = trim((string)($row[PaymentOutbox::schema_fields_OUTBOX_CODE] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function process(string $outboxCode, callable $handler): array
    {
        $outboxCode = trim($outboxCode);
        if ($outboxCode === '') {
            throw new \InvalidArgumentException(self::ERROR_NOT_FOUND);
        }

        $prototype = $this->newModel(PaymentOutbox::class);
        $connection = $prototype->getConnection();

        return $this->transactions->runWrite(
            $connection,
            function () use ($prototype, $outboxCode, $handler): array {
                $outbox = $this->loadByCode($prototype, $outboxCode, true);
                if (!$outbox->getId()) {
                    throw new \RuntimeException(self::ERROR_NOT_FOUND);
                }

                $status = (string)$outbox->getData(PaymentOutbox::schema_fields_STATUS);
                if ($status === PaymentOutbox::STATUS_DONE) {
                    return [
                        'ok' => true,
                        'replayed' => true,
                        'effect' => $this->record($outbox)->toArray(),
                    ];
                }
                if ($status !== PaymentOutbox::STATUS_PENDING) {
                    throw new \RuntimeException(self::ERROR_NOT_PENDING . ':' . $status);
                }

                $record = $this->record($outbox);
                $result = $handler($record);
                if (!\is_array($result) || empty($result['ok'])) {
                    throw new \RuntimeException(
                        (string)($result['error_code'] ?? self::ERROR_HANDLER_FAILED),
                    );
                }

                $outbox->setData(PaymentOutbox::schema_fields_STATUS, PaymentOutbox::STATUS_DONE)
                    ->setData(PaymentOutbox::schema_fields_PROCESSED_AT, date('Y-m-d H:i:s'))
                    ->save();

                return [
                    'ok' => true,
                    'replayed' => false,
                    'effect' => $record->toArray(),
                    'result' => $result,
                ];
            },
        );
    }

    private function loadByCode(
        PaymentOutbox $prototype,
        string $outboxCode,
        bool $lockingRead,
    ): PaymentOutbox {
        /** @var PaymentOutbox $outbox */
        $outbox = clone $prototype;
        $outbox->clearData()->clearQuery()
            ->where(PaymentOutbox::schema_fields_OUTBOX_CODE, $outboxCode);
        if ($lockingRead && !$this->isSqlite($outbox)) {
            $outbox->additional('FOR UPDATE');
        }
        $outbox->find()->fetch();

        return $outbox;
    }

    private function record(PaymentOutbox $outbox): PaymentEffectRecord
    {
        $payload = json_decode(
            (string)$outbox->getData(PaymentOutbox::schema_fields_PAYLOAD_JSON),
            true,
        );
        if (!\is_array($payload)) {
            throw new \RuntimeException('payment_effect_payload_invalid');
        }

        return new PaymentEffectRecord(
            outboxCode: (string)$outbox->getData(PaymentOutbox::schema_fields_OUTBOX_CODE),
            effectKey: (string)$outbox->getData(PaymentOutbox::schema_fields_EFFECT_KEY),
            intentCode: (string)$outbox->getData(PaymentOutbox::schema_fields_INTENT_CODE),
            attemptCode: (string)$outbox->getData(PaymentOutbox::schema_fields_ATTEMPT_CODE),
            effectType: (string)$outbox->getData(PaymentOutbox::schema_fields_EFFECT_TYPE),
            payableType: (string)($payload['payable_type'] ?? ''),
            payableId: (string)($payload['payable_id'] ?? ''),
            schemaVersion: (string)($payload['schema_version'] ?? ''),
        );
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    private function newModel(string $class): Model
    {
        return $this->objectManager->getInstance($class, [], false);
    }

    private function isSqlite(Model $model): bool
    {
        return strtolower((string)$model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }
}
