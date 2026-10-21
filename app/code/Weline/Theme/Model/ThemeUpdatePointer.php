<?php

declare(strict_types=1);

namespace Weline\Theme\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

/**
 * 主题更新代次指针：每个 (website_id, theme_id, area) 只有一行。
 *
 * 切换代次 = 单事务更新 current/previous/epoch；epoch 单调递增，作为 FPC key 与
 * Worker 感知代次（≤10s 生效，与 {@see \Weline\Framework\Deploy\StaticBuildId} 对齐）。
 */
#[Table(comment: '主题更新代次指针（当前生效代次）')]
#[Index(name: 'uk_theme_update_pointer_owner', columns: ['website_id', 'theme_id', 'area'], type: 'UNIQUE')]
final class ThemeUpdatePointer extends Model
{
    public const schema_table = 'theme_update_pointer';
    public const schema_primary_key = 'pointer_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '自增ID')]
    public const schema_fields_ID = 'pointer_id';
    #[Col(type: 'int', nullable: false, default: 0, comment: '站点ID；0 = 全站默认')]
    public const schema_fields_WEBSITE_ID = 'website_id';
    #[Col(type: 'int', nullable: false, comment: '主题ID')]
    public const schema_fields_THEME_ID = 'theme_id';
    #[Col(type: 'varchar', length: 16, nullable: false, default: 'frontend', comment: 'frontend/backend')]
    public const schema_fields_AREA = 'area';
    #[Col(type: 'varchar', length: 64, nullable: false, comment: '当前生效代次ID')]
    public const schema_fields_CURRENT_BATCH_ID = 'current_batch_id';
    #[Col(type: 'varchar', length: 64, nullable: true, comment: '上一个代次ID（回滚目标）')]
    public const schema_fields_PREVIOUS_BATCH_ID = 'previous_batch_id';
    #[Col(type: 'bigint', nullable: false, default: 1, comment: '单调递增切换代次')]
    public const schema_fields_EPOCH = 'epoch';
    #[Col(type: 'datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: '更新时间')]
    public const schema_fields_UPDATE_TIME = 'update_time';

    public function getCurrentBatchId(): string
    {
        return (string)$this->getData(self::schema_fields_CURRENT_BATCH_ID);
    }

    public function getPreviousBatchId(): string
    {
        return (string)$this->getData(self::schema_fields_PREVIOUS_BATCH_ID);
    }

    public function getEpoch(): int
    {
        return (int)$this->getData(self::schema_fields_EPOCH);
    }

    /** @return array{current:string,previous:string,epoch:int} */
    public function toState(): array
    {
        return [
            'current' => $this->getCurrentBatchId(),
            'previous' => $this->getPreviousBatchId(),
            'epoch' => $this->getEpoch(),
        ];
    }
}
