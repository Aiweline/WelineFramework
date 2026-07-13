<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface as SqlTableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * Public EAV entity definition for custom payment-method attributes.
 *
 * This model deliberately maps to the existing payment method table so EAV
 * values remain keyed by method_id while all EAV ORM details stay in Weline_Eav.
 */
class PaymentMethodAttributeEntity extends Model implements EntityDefinitionInterface
{
    public const entity_code = 'payment_method';
    public const entity_name = 'Payment Method';
    public const eav_entity_id_field_type = SqlTableInterface::column_type_INTEGER;
    public const eav_entity_id_field_length = 11;

    public const schema_table = PaymentMethod::schema_table;
    public const schema_primary_key = PaymentMethod::schema_primary_key;
    public const schema_fields_ID = PaymentMethod::schema_fields_ID;
    public const schema_fields_CODE = PaymentMethod::schema_fields_CODE;
    public const schema_fields_NAME = PaymentMethod::schema_fields_NAME;

    public string $table = self::schema_table;
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [
        self::schema_fields_ID,
        self::schema_fields_CODE,
    ];

    private ?EntityAttributeStoreInterface $attributeStore = null;

    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviders,
        array $data = [],
    ) {
        parent::__construct($data);
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        $this->syncAttributeSequence();
        $this->store()->provisionValueTables($this, $setup);
    }

    public function syncAttributeSequence(): void
    {
        $this->store()->syncAttributeSequence();
    }

    public function getEntityCode(): string
    {
        return self::entity_code;
    }

    public function getEntityName(): string
    {
        return self::entity_name;
    }

    public function getEntityFieldIdType(): string
    {
        return self::eav_entity_id_field_type;
    }

    public function getEntityFieldIdLength(): int
    {
        return self::eav_entity_id_field_length;
    }

    private function store(): EntityAttributeStoreInterface
    {
        if ($this->attributeStore instanceof EntityAttributeStoreInterface) {
            return $this->attributeStore;
        }

        $store = $this->runtimeProviders->resolve(EntityAttributeStoreInterface::class);
        if (!$store instanceof EntityAttributeStoreInterface) {
            throw new \RuntimeException('payment_method_eav_provider_unavailable');
        }

        return $this->attributeStore = $store;
    }
}
