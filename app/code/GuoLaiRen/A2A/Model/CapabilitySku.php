<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'A2A capability SKU catalog')]
#[Index(name: 'uk_a2a_capability_sku_code', columns: [self::schema_fields_CODE], type: 'UNIQUE', comment: 'Capability SKU code')]
#[Index(name: 'idx_a2a_capability_sku_tier', columns: [self::schema_fields_TIER_STATE], type: 'KEY', comment: 'Capability reputation tier')]
#[Index(name: 'idx_a2a_capability_sku_supply_type', columns: [self::schema_fields_SUPPLY_TYPE], type: 'KEY', comment: 'Capability supply type')]
class CapabilitySku extends Model
{
    public const schema_table = 'guolairen_a2a_capability_sku';
    public const schema_primary_key = 'capability_sku_id';

    public const STATUS_ACTIVE = 'active';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Capability SKU ID')]
    public const schema_fields_ID = 'capability_sku_id';

    #[Col(type: 'varchar', length: 80, nullable: false, comment: 'Capability SKU code')]
    public const schema_fields_CODE = 'code';

    #[Col(type: 'varchar', length: 255, nullable: false, comment: 'Capability SKU title')]
    public const schema_fields_TITLE = 'title';

    #[Col(type: 'varchar', length: 160, nullable: false, default: '', comment: 'Provider display name')]
    public const schema_fields_PROVIDER = 'provider';

    #[Col(type: 'varchar', length: 80, nullable: false, default: '', comment: 'Supply type')]
    public const schema_fields_SUPPLY_TYPE = 'supply_type';

    #[Col(type: 'varchar', length: 40, nullable: false, default: '', comment: 'Reputation tier label')]
    public const schema_fields_TIER = 'tier';

    #[Col(type: 'varchar', length: 24, nullable: false, default: '', comment: 'Reputation tier state')]
    public const schema_fields_TIER_STATE = 'tier_state';

    #[Col(type: 'varchar', length: 80, nullable: false, default: '', comment: 'Scarcity label')]
    public const schema_fields_RARITY = 'rarity';

    #[Col(type: 'decimal', length: '12,2', nullable: false, default: '0.00', comment: 'Capability SKU price amount')]
    public const schema_fields_PRICE_AMOUNT = 'price_amount';

    #[Col(type: 'varchar', length: 8, nullable: false, default: 'USD', comment: 'Currency code')]
    public const schema_fields_CURRENCY_CODE = 'currency_code';

    #[Col(type: 'varchar', length: 80, nullable: false, default: '', comment: 'Pricing unit')]
    public const schema_fields_UNIT = 'unit';

    #[Col(type: 'int', nullable: false, default: 0, comment: 'Historical purchase count')]
    public const schema_fields_PURCHASES = 'purchases';

    #[Col(type: 'text', nullable: true, comment: 'Capability summary')]
    public const schema_fields_SUMMARY = 'summary';

    #[Col(type: 'longtext', nullable: true, comment: 'Marketing tags JSON')]
    public const schema_fields_TAGS_JSON = 'tags_json';

    #[Col(type: 'longtext', nullable: true, comment: 'Trust tags JSON')]
    public const schema_fields_TRUST_JSON = 'trust_json';

    #[Col(type: 'varchar', length: 24, nullable: false, default: self::STATUS_ACTIVE, comment: 'Capability SKU status')]
    public const schema_fields_STATUS = 'status';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATE_TIME = 'create_time';

    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function save_before(): void
    {
        parent::save_before();

        $now = \date('Y-m-d H:i:s');
        $this->setData(self::schema_fields_UPDATE_TIME, $now);
        if (!$this->getId()) {
            $this->setData(self::schema_fields_CREATE_TIME, $now);
        }

        $this->setData(self::schema_fields_CODE, \strtolower(\trim((string)$this->getData(self::schema_fields_CODE))));
        $this->setData(self::schema_fields_CURRENCY_CODE, \strtoupper((string)($this->getData(self::schema_fields_CURRENCY_CODE) ?: 'USD')));
    }

    public function getId(mixed $default = 0): int
    {
        return (int)($this->getData(self::schema_fields_ID) ?: $default);
    }

    public function getCode(): string
    {
        return (string)($this->getData(self::schema_fields_CODE) ?: '');
    }

    public function getPriceAmount(): float
    {
        return (float)($this->getData(self::schema_fields_PRICE_AMOUNT) ?: 0);
    }

    /**
     * @return list<string>
     */
    public function getTagsArray(): array
    {
        return $this->decodeStringList(self::schema_fields_TAGS_JSON);
    }

    /**
     * @param list<string> $tags
     */
    public function setTagsArray(array $tags): static
    {
        return $this->setData(self::schema_fields_TAGS_JSON, $this->encodeStringList($tags));
    }

    /**
     * @return list<string>
     */
    public function getTrustArray(): array
    {
        return $this->decodeStringList(self::schema_fields_TRUST_JSON);
    }

    /**
     * @param list<string> $trust
     */
    public function setTrustArray(array $trust): static
    {
        return $this->setData(self::schema_fields_TRUST_JSON, $this->encodeStringList($trust));
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(string $field): array
    {
        $raw = $this->getData($field);
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        return \array_values(\array_filter(
            \array_map(static fn(mixed $item): string => \trim((string)$item), $decoded),
            static fn(string $item): bool => $item !== ''
        ));
    }

    /**
     * @param list<string> $items
     */
    private function encodeStringList(array $items): string
    {
        $items = \array_values(\array_filter(
            \array_map(static fn(mixed $item): string => \trim((string)$item), $items),
            static fn(string $item): bool => $item !== ''
        ));

        return \json_encode($items, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
