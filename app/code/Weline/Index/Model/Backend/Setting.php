<?php

declare(strict_types=1);

namespace Weline\Index\Model\Backend;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

#[Table(comment: '设置表')]
class Setting extends Model
{
    public const schema_table = 'weline_index_backend_setting';
    public const schema_primary_key = 'settings_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: '设置ID')]
    public const schema_fields_ID = 'settings_id';
    #[Col(type: 'varchar', length: 255, nullable: false, unique: true, comment: '键')]
    public const schema_fields_KEY = 'key';
    #[Col(type: 'varchar', length: 255, nullable: false, unique: true, comment: '配置名')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'varchar', length: 255, nullable: false, default: '', comment: '值')]
    public const schema_fields_VALUE = 'value';
    #[Col(type: 'varchar', length: 255, nullable: false, default: 'header', comment: '位置')]
    public const schema_fields_POSITION = 'position';

    /** 表结构由 SchemaDiffStage 负责 */
    public function setup(ModelSetup $setup, Context $context): void {}
    public function upgrade(ModelSetup $setup, Context $context): void {}

    /** 种子数据由 Index/Setup/Install::seedDefaultSettings 安装 */
    public function install(ModelSetup $setup, Context $context): void {}
}
