<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 主题更新代次（Theme Update Batch）。
 *
 * 一条代次 = 一套自洽的运行时产物：主题静态资源命名空间 + 布局实体根 + 校验清单。
 * 代次只在构建期写入自己的目录，切换时由 {@see ThemeUpdatePointer} 单事务改指针，
 * 因此不需要维护模式。与「主题发布版本（ThemeScopeVersion）」是两条独立的轴。
 */
#[Table(comment: '主题更新代次表（静态资源+模板产物的一次构建）')]
#[Index(name: 'uk_theme_update_batch_id', columns: ['batch_id'], type: 'UNIQUE')]
#[Index(name: 'idx_theme_update_batch_owner', columns: ['theme_id', 'area', 'status'])]
final class ThemeUpdateBatch extends Model
{
    public const schema_table = 'theme_update_batch';
    public const schema_primary_key = 'id';

    public const STATUS_BUILDING = 'building';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_CURRENT = 'current';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_FAILED = 'failed';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '自增ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '代次ID：tu-YYYYmmdd-HHMMSS-xxxxxx')]
    public const schema_fields_BATCH_ID = 'batch_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '站点ID；0 = 全站默认')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'int', nullable: false, comment: '主题ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'frontend', comment: 'frontend/backend')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'varchar', length: 128, nullable: false, comment: '相对 pub/static 的静态命名空间')]
    public const schema_fields_STATIC_NS = 'static_ns';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '相对 generated/theme-layout-entities 的根')]
    public const schema_fields_ENTITY_ROOT = 'entity_root';
    #[Col(type: 'varchar', length: 16, nullable: false, default: self::STATUS_BUILDING, comment: 'building/verified/current/superseded/failed')]
    public const schema_fields_STATUS = 'status';
    #[Col(type: 'longtext', nullable: true, comment: 'batch.json 清单（含校验结果）')]
    public const schema_fields_MANIFEST_JSON = 'manifest_json';
    #[Col(type: 'int', nullable: false, default: 0, comment: '产物文件数')]
    public const schema_fields_FILE_COUNT = 'file_count';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '创建时间')]
    public const schema_fields_CREATE_TIME = 'create_time';
    #[Col(type: 'datetime', nullable: true, comment: '校验通过时间')]
    public const schema_fields_VERIFIED_TIME = 'verified_time';
    #[Col(type: 'datetime', nullable: true, comment: '切换为当前代次时间')]
    public const schema_fields_PROMOTED_TIME = 'promoted_time';

    public function getBatchId(): string
    {
        return (string)$this->getData(self::schema_fields_BATCH_ID);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::schema_fields_STATUS);
    }

    /**
     * 允许被切换为线上（promote 目标）：校验通过 / 当前 / 曾被切换过（superseded，可再切回）。
     * building/failed 永远不可上线。
     */
    public function isServable(): bool
    {
        return \in_array(
            $this->getStatus(),
            [self::STATUS_VERIFIED, self::STATUS_CURRENT, self::STATUS_SUPERSEDED],
            true
        );
    }

    /** 回滚目标资格：只要曾通过校验且产物仍在即可（superseded 也算）。 */
    public function isRollbackEligible(): bool
    {
        return \in_array(
            $this->getStatus(),
            [self::STATUS_VERIFIED, self::STATUS_CURRENT, self::STATUS_SUPERSEDED],
            true
        );
    }

    /** @return array<string,mixed> */
    public function getManifest(): array
    {
        $raw = (string)$this->getData(self::schema_fields_MANIFEST_JSON);
        if ($raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** 清单里的校验结论；缺清单视为不可上线。 */
    public function isManifestVerified(): bool
    {
        $manifest = $this->getManifest();
        $verify = $manifest['verify'] ?? null;

        return \is_array($verify) && ($verify['ok'] ?? false) === true;
    }
}
