<?php

declare(strict_types=1);

namespace Weline\Payment\Model;

use Weline\Eav\EavModel;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Model\EavAttribute\Type\Value;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface as DdlTableInterface;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface as SqlTableInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * EAV facade for custom payment-method attributes.
 *
 * This model deliberately maps to the existing payment method table so EAV
 * values are keyed by method_id while the business PaymentMethod model stays
 * unchanged.
 */
class PaymentMethodAttributeEntity extends EavModel
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

        $typeModel = ObjectManager::getInstance(Type::class);
        $types = $typeModel->clear()->select()->fetch()->getItems();

        foreach ($types as $type) {
            if (!$type instanceof Type) {
                continue;
            }

            $typeCode = trim($type->getCode());
            $fieldType = trim($type->getFieldType());
            if ($typeCode === '' || $fieldType === '') {
                continue;
            }

            $tableName = $setup->getTable('eav_' . self::entity_code . '_' . $typeCode);
            if ($setup->tableExist($tableName)) {
                continue;
            }

            $table = $setup->createTable('支付方式EAV ' . $typeCode . ' 类型数据表', $tableName);
            $table
                ->addColumn(
                    Value::schema_fields_value_id,
                    DdlTableInterface::column_type_BIGINT,
                    18,
                    'primary key auto_increment',
                    '属性值ID'
                )
                ->addColumn(
                    Value::schema_fields_attribute_id,
                    DdlTableInterface::column_type_INTEGER,
                    11,
                    'not null',
                    '属性ID'
                )
                ->addColumn(
                    Value::schema_fields_entity_id,
                    self::eav_entity_id_field_type,
                    self::eav_entity_id_field_length,
                    'not null',
                    '支付方式ID'
                )
                ->addColumn(
                    Value::schema_fields_value,
                    $fieldType,
                    $type->getFieldLength(),
                    'not null',
                    '属性值'
                );

            if ($type->getIsSwatch()) {
                $table->addColumn(Type::schema_fields_is_swatch, DdlTableInterface::column_type_BOOLEAN, 0, 'default 0', '是否有样本');
                if ($type->hasSwatchImage()) {
                    $table->addColumn(Type::schema_fields_swatch_image, DdlTableInterface::column_type_VARCHAR, 255, '', '样本图片');
                }
                if ($type->hasSwatchColor()) {
                    $table->addColumn(Type::schema_fields_swatch_color, DdlTableInterface::column_type_VARCHAR, 255, '', '样本颜色');
                }
                if ($type->hasSwatchText()) {
                    $table->addColumn(Type::schema_fields_swatch_text, DdlTableInterface::column_type_VARCHAR, 255, '', '样本文本');
                }
            }

            $table
                ->addIndex(DdlTableInterface::index_type_KEY, $tableName . '_idx_ATTRIBUTE_ID', Value::schema_fields_attribute_id)
                ->addIndex(DdlTableInterface::index_type_KEY, $tableName . '_idx_ENTITY_ID', Value::schema_fields_entity_id)
                ->create();
        }
    }

    public function syncAttributeSequence(): void
    {
        $connector = $this->getConnection()->getConnector();
        $connectorClass = $connector::class;
        if (!str_contains(strtolower($connectorClass), 'pgsql')) {
            return;
        }

        /** @var EavAttribute $attribute */
        $attribute = ObjectManager::getInstance(EavAttribute::class);
        $table = $attribute->getTable();
        $idField = EavAttribute::schema_fields_ID;
        $tableLiteral = str_replace("'", "''", str_replace('"', '', $table));
        $idLiteral = str_replace("'", "''", $idField);
        $quotedTable = $this->quoteQualifiedIdentifier($table);
        $quotedId = '"' . str_replace('"', '""', $idField) . '"';

        $connector->query(
            "SELECT setval(pg_get_serial_sequence('{$tableLiteral}', '{$idLiteral}'), "
            . "GREATEST(COALESCE((SELECT MAX({$quotedId}) FROM {$quotedTable}), 1), 1), true)"
        )->fetch();
    }

    private function quoteQualifiedIdentifier(string $identifier): string
    {
        $parts = array_filter(
            array_map(static fn(string $part): string => trim($part, "\" \t\n\r\0\x0B"), explode('.', $identifier)),
            static fn(string $part): bool => $part !== ''
        );

        return implode('.', array_map(
            static fn(string $part): string => '"' . str_replace('"', '""', $part) . '"',
            $parts
        ));
    }
}
