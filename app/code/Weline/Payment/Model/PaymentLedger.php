<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Payment ledger table')]
#[Index(name: 'uniq_payment_ledger_code', columns: ['ledger_code'], type: 'UNIQUE')]
#[Index(name: 'idx_payment_ledger_transaction', columns: ['transaction_code'])]
#[Index(name: 'idx_payment_ledger_refund', columns: ['refund_code'])]
#[Index(name: 'idx_payment_ledger_intent', columns: ['intent_code'])]
#[Index(name: 'idx_payment_ledger_attempt', columns: ['attempt_code'])]
#[Index(name: 'idx_payment_ledger_payable', columns: ['payable_type', 'payable_id'])]
#[Index(name: 'idx_payment_ledger_type_created', columns: ['ledger_type', 'created_at'])]
class PaymentLedger extends Model
{
    public const schema_table = 'weline_payment_ledger';
    public const schema_primary_key = 'ledger_id';

    public const TYPE_PAYMENT = 'payment';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const DIRECTION_DEBIT = 'debit';
    public const DIRECTION_CREDIT = 'credit';

    #[Col('bigint', 20, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Ledger ID')]
    public const schema_fields_ID = 'ledger_id';
    #[Col('varchar', 96, nullable: false, comment: 'Stable ledger code')]
    public const schema_fields_LEDGER_CODE = 'ledger_code';
    #[Col('varchar', 48, nullable: false, default: 'payment', comment: 'Ledger type')]
    public const schema_fields_LEDGER_TYPE = 'ledger_type';
    #[Col('varchar', 16, nullable: false, comment: 'Debit or credit direction')]
    public const schema_fields_DIRECTION = 'direction';
    #[Col('decimal', '12,4', nullable: false, default: '0.0000', comment: 'Debit amount')]
    public const schema_fields_DEBIT = 'debit';
    #[Col('decimal', '12,4', nullable: false, default: '0.0000', comment: 'Credit amount')]
    public const schema_fields_CREDIT = 'credit';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Debit amount in minor units')]
    public const schema_fields_DEBIT_MINOR = 'debit_minor';
    #[Col('bigint', 20, nullable: false, default: 0, comment: 'Credit amount in minor units')]
    public const schema_fields_CREDIT_MINOR = 'credit_minor';
    #[Col('varchar', 3, nullable: false, comment: 'Currency code')]
    public const schema_fields_CURRENCY = 'currency';
    #[Col('smallint', 2, nullable: false, default: 2, comment: 'Currency precision')]
    public const schema_fields_PRECISION = 'precision';
    #[Col('varchar', 100, nullable: true, comment: 'Linked transaction code')]
    public const schema_fields_TRANSACTION_CODE = 'transaction_code';
    #[Col('bigint', 20, nullable: true, comment: 'Linked transaction row ID')]
    public const schema_fields_LINKED_TRANSACTION_ID = 'linked_transaction_id';
    #[Col('varchar', 96, nullable: true, comment: 'Linked intent code')]
    public const schema_fields_INTENT_CODE = 'intent_code';
    #[Col('varchar', 96, nullable: true, comment: 'Linked attempt code')]
    public const schema_fields_ATTEMPT_CODE = 'attempt_code';
    #[Col('bigint', 20, nullable: true, comment: 'Linked attempt row ID')]
    public const schema_fields_LINKED_ATTEMPT_ID = 'linked_attempt_id';
    #[Col('varchar', 96, nullable: true, comment: 'Linked refund code')]
    public const schema_fields_REFUND_CODE = 'refund_code';
    #[Col('varchar', 96, nullable: true, comment: 'Payment method code')]
    public const schema_fields_METHOD_CODE = 'method_code';
    #[Col('varchar', 96, nullable: true, comment: 'Provider code')]
    public const schema_fields_PROVIDER_CODE = 'provider_code';
    #[Col('varchar', 128, nullable: true, comment: 'Merchant account')]
    public const schema_fields_MERCHANT_ACCOUNT = 'merchant_account';
    #[Col('varchar', 64, nullable: true, comment: 'Payable type')]
    public const schema_fields_PAYABLE_TYPE = 'payable_type';
    #[Col('varchar', 128, nullable: true, comment: 'Payable ID')]
    public const schema_fields_PAYABLE_ID = 'payable_id';
    #[Col('text', nullable: true, comment: 'Metadata JSON')]
    public const schema_fields_METADATA = 'metadata';
    #[Col('datetime', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['ledger_id'];
    public array $_index_sort_keys = ['ledger_code', 'ledger_type', 'transaction_code', 'refund_code', 'intent_code', 'attempt_code', 'created_at'];

    public function _init(): void
    {
        $this->useMainDbMaster();
    }

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        $value = $this->getData(self::schema_fields_METADATA);
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): static
    {
        return $this->setData(self::schema_fields_METADATA, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
