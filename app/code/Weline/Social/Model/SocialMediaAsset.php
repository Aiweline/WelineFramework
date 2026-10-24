<?php

declare(strict_types=1);

namespace Weline\Social\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: 'Social media asset')]
#[Index(name: 'uniq_social_asset_checksum', columns: ['website_id', 'checksum'], type: 'UNIQUE')]
class SocialMediaAsset extends Model
{
    public const schema_table = 'weline_social_media_asset';
    public const schema_primary_key = 'asset_id';

    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_AUDIO = 'audio';

    #[Col('int', 0, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Asset ID')]
    public const schema_fields_ID = 'asset_id';
    #[Col('int', 0, nullable: false, comment: 'Website ID')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col('varchar', 16, nullable: false, comment: 'Asset kind')]
    public const schema_fields_KIND = 'kind';
    #[Col('varchar', 512, nullable: false, comment: 'Storage path')]
    public const schema_fields_PATH = 'path';
    #[Col('varchar', 100, nullable: true, comment: 'MIME type')]
    public const schema_fields_MIME = 'mime';
    #[Col('int', 0, nullable: true, default: 0, comment: 'Width px')]
    public const schema_fields_WIDTH = 'width';
    #[Col('int', 0, nullable: true, default: 0, comment: 'Height px')]
    public const schema_fields_HEIGHT = 'height';
    #[Col('int', 0, nullable: true, default: 0, comment: 'Duration ms')]
    public const schema_fields_DURATION_MS = 'duration_ms';
    #[Col('varchar', 64, nullable: false, comment: 'Content checksum')]
    public const schema_fields_CHECKSUM = 'checksum';
    #[Col('varchar', 32, nullable: false, default: 'site', comment: 'Asset source')]
    public const schema_fields_SOURCE = 'source';
    #[Col('varchar', 512, nullable: true, comment: 'Source URL')]
    public const schema_fields_SOURCE_URL = 'source_url';
    #[Col('varchar', 64, nullable: true, comment: 'License code')]
    public const schema_fields_LICENSE = 'license';
    #[Col('varchar', 255, nullable: true, comment: 'Attribution text')]
    public const schema_fields_ATTRIBUTION = 'attribution';
    #[Col('datetime', nullable: true, comment: 'License confirmed at')]
    public const schema_fields_LICENSE_CONFIRMED_AT = 'license_confirmed_at';
    #[Col('datetime', nullable: true, comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    public array $_unit_primary_keys = ['asset_id'];
    public array $_index_sort_keys = ['website_id', 'kind', 'checksum'];

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
    public function toArrayData(): array
    {
        return [
            'asset_id' => (int)$this->getId(),
            'website_id' => (int)$this->getData(self::schema_fields_WEBSITE_ID),
            'kind' => (string)$this->getData(self::schema_fields_KIND),
            'path' => (string)$this->getData(self::schema_fields_PATH),
            'mime' => (string)$this->getData(self::schema_fields_MIME),
            'width' => (int)$this->getData(self::schema_fields_WIDTH),
            'height' => (int)$this->getData(self::schema_fields_HEIGHT),
            'duration_ms' => (int)$this->getData(self::schema_fields_DURATION_MS),
            'checksum' => (string)$this->getData(self::schema_fields_CHECKSUM),
            'source' => (string)$this->getData(self::schema_fields_SOURCE),
            'source_url' => (string)$this->getData(self::schema_fields_SOURCE_URL),
            'license' => (string)$this->getData(self::schema_fields_LICENSE),
            'attribution' => (string)$this->getData(self::schema_fields_ATTRIBUTION),
            'license_confirmed_at' => (string)$this->getData(self::schema_fields_LICENSE_CONFIRMED_AT),
            'created_at' => (string)$this->getData(self::schema_fields_CREATED_AT),
        ];
    }
}
