<?php

declare(strict_types=1);

namespace Weline\FileManager\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '文件资源多语言元数据')]
#[Index(name: 'uk_file_asset_locale', columns: ['asset_id', 'locale_code'], type: 'UNIQUE', comment: '资源语言唯一')]
#[Index(name: 'idx_file_asset_locale_state', columns: ['locale_code', 'translation_state'], type: 'KEY', comment: '翻译审核检索')]
class FileAssetLocale extends Model
{
    public const schema_table = 'weline_file_asset_locale';
    public const schema_primary_key = 'asset_locale_id';
    public const STATE_DRAFT = 'draft';
    public const STATE_REVIEWED = 'reviewed';
    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_MACHINE = 'machine';
    public const ORIGIN_IMPORT = 'import';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '资源语言 ID')]
    public const schema_fields_ID = 'asset_locale_id';
    #[Col(type: 'varchar', length: 36, nullable: false, comment: '资源 UUID')]
    public const schema_fields_ASSET_ID = 'asset_id';
    #[Col(type: 'varchar', length: 16, nullable: false, comment: '语言代码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '显示名称')]
    public const schema_fields_DISPLAY_NAME = 'display_name';
    #[Col(type: 'varchar', length: 512, nullable: false, default: '', comment: '默认替代文本')]
    public const schema_fields_DEFAULT_ALT = 'default_alt';
    #[Col(type: 'text', nullable: false, comment: '资源描述')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col(type: 'text', nullable: true, comment: '默认说明文字')]
    public const schema_fields_DEFAULT_CAPTION = 'default_caption';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATE_DRAFT, comment: '翻译状态')]
    public const schema_fields_TRANSLATION_STATE = 'translation_state';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::ORIGIN_MANUAL, comment: '翻译来源')]
    public const schema_fields_TRANSLATION_ORIGIN = 'translation_origin';
    #[Col(type: 'datetime', nullable: true, comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col(type: 'datetime', nullable: true, comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ASSET_ID, self::schema_fields_LOCALE_CODE];

    public function getLocaleCode(): string { return (string)($this->getData(self::schema_fields_LOCALE_CODE) ?: ''); }
    public function isReviewed(): bool { return $this->getData(self::schema_fields_TRANSLATION_STATE) === self::STATE_REVIEWED; }

    public function save_before(): void
    {
        parent::save_before();
        $assetId = trim((string)$this->getData(self::schema_fields_ASSET_ID));
        $localeCode = trim((string)$this->getData(self::schema_fields_LOCALE_CODE));
        $displayName = trim((string)$this->getData(self::schema_fields_DISPLAY_NAME));
        $defaultAlt = trim((string)$this->getData(self::schema_fields_DEFAULT_ALT));
        $description = trim((string)$this->getData(self::schema_fields_DESCRIPTION));
        $caption = $this->getData(self::schema_fields_DEFAULT_CAPTION);
        $caption = $caption === null ? null : trim((string)$caption);
        $state = (string)$this->getData(self::schema_fields_TRANSLATION_STATE);
        $origin = (string)$this->getData(self::schema_fields_TRANSLATION_ORIGIN);
        $validUtf8 = preg_match('//u', $displayName . $defaultAlt . $description . ($caption ?? '')) === 1;
        $displayNameLength = $validUtf8 && function_exists('mb_strlen')
            ? mb_strlen($displayName, 'UTF-8')
            : strlen($displayName);
        $defaultAltLength = $validUtf8 && function_exists('mb_strlen')
            ? mb_strlen($defaultAlt, 'UTF-8')
            : strlen($defaultAlt);
        if (
            preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $assetId) !== 1
            || preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/', $localeCode) !== 1
            || !$validUtf8
            || $displayNameLength > 255
            || $defaultAltLength > 512
            || strlen($description) > 65535
            || ($caption !== null && strlen($caption) > 4096)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $displayName . $defaultAlt . $description . ($caption ?? '')) === 1
        ) {
            throw new \InvalidArgumentException((string)__('资源语言元数据无效。'));
        }
        if (!in_array($state, [self::STATE_DRAFT, self::STATE_REVIEWED], true)) {
            throw new \InvalidArgumentException((string)__('资源翻译状态无效。'));
        }
        if (!in_array($origin, [self::ORIGIN_MANUAL, self::ORIGIN_MACHINE, self::ORIGIN_IMPORT], true)) {
            throw new \InvalidArgumentException((string)__('资源翻译来源无效。'));
        }
        if ($state === self::STATE_REVIEWED && ($displayName === '' || $defaultAlt === '' || $description === '')) {
            throw new \InvalidArgumentException((string)__('审核通过的资源语言必须填写名称、默认 alt 和描述。'));
        }
        $this->setData(self::schema_fields_ASSET_ID, $assetId);
        $this->setData(self::schema_fields_LOCALE_CODE, $localeCode);
        $this->setData(self::schema_fields_DISPLAY_NAME, $displayName);
        $this->setData(self::schema_fields_DEFAULT_ALT, $defaultAlt);
        $this->setData(self::schema_fields_DESCRIPTION, $description);
        $this->setData(self::schema_fields_DEFAULT_CAPTION, $caption);
        $now = date('Y-m-d H:i:s');
        if (!$this->getData(self::schema_fields_CREATED_AT)) {
            $this->setData(self::schema_fields_CREATED_AT, $now);
        }
        $this->setData(self::schema_fields_UPDATED_AT, $now);
    }
}
