<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social AI creative draft')]
#[Index(name: 'idx_social_draft_status', columns: ['status'])]
class SocialCreativeDraft extends Model
{
    public const schema_table = 'weline_social_creative_draft';
    public const schema_primary_key = 'draft_id';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Draft ID')]
    public const schema_fields_ID = 'draft_id';
    #[Col('varchar', 190, nullable: false, comment: 'Draft title')]
    public const schema_fields_TITLE = 'title';
    #[Col('text', nullable: true, comment: 'Creative prompt')]
    public const schema_fields_PROMPT = 'prompt';
    #[Col('text', nullable: true, comment: 'Canonical content')]
    public const schema_fields_CONTENT = 'content';
    #[Col('text', nullable: true, comment: 'Platform variants JSON')]
    public const schema_fields_VARIANTS_JSON = 'variants_json';
    #[Col('text', nullable: true, comment: 'Asset metadata JSON')]
    public const schema_fields_ASSETS_JSON = 'assets_json';
    #[Col('varchar', 32, nullable: false, default: 'draft', comment: 'Draft status')]
    public const schema_fields_STATUS = 'status';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', nullable: true, comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['draft_id'];
    public array $_index_sort_keys = ['status'];

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
    public function getVariants(): array
    {
        $decoded = \json_decode((string)$this->getData(self::schema_fields_VARIANTS_JSON), true);
        return \is_array($decoded) ? $decoded : [];
    }

    public function setVariants(array $variants): static
    {
        return $this->setData(self::schema_fields_VARIANTS_JSON, \json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function setAssets(array $assets): static
    {
        return $this->setData(self::schema_fields_ASSETS_JSON, \json_encode($assets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayData(): array
    {
        return [
            'draft_id' => (int)$this->getId(),
            'title' => (string)$this->getData(self::schema_fields_TITLE),
            'prompt' => (string)$this->getData(self::schema_fields_PROMPT),
            'content' => (string)$this->getData(self::schema_fields_CONTENT),
            'variants' => $this->getVariants(),
            'status' => (string)$this->getData(self::schema_fields_STATUS),
            'created_at' => (string)$this->getData(self::schema_fields_CREATED_AT),
            'updated_at' => (string)$this->getData(self::schema_fields_UPDATED_AT),
        ];
    }
}

