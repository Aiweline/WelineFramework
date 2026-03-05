<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/22 14:42:04
 */
namespace Weline\I18n\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Countries\Locale\Name;

#[Table(comment: '国家')]
#[Index(name: 'idx_code', columns: ['code'], comment: '国家码索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '状态索引')]
#[Index(name: 'idx_is_install', columns: ['is_install'], comment: '安装状态索引')]
class Countries extends Model
{
    public const schema_table = 'i18n_countries';
    public const schema_primary_key = 'code';
    #[Col('varchar', 10, primaryKey: true, nullable: false, comment: '国家码')]
    public const schema_fields_ID = 'code';
    #[Col('varchar', 10, primaryKey: true, nullable: false, comment: '国家码')]
    public const schema_fields_CODE = 'code';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '启用状态')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否安装')]
    public const schema_fields_IS_INSTALL = 'is_install';
    #[Col('text', nullable: false, comment: '国旗')]
    public const schema_fields_FLAG = 'flag';

    public function getLocaleNameModel(): Name
    {
        return ObjectManager::getInstance(Name::class);
    }
    public function getLocaleModel(): Locale
    {
        return ObjectManager::getInstance(Locale::class);
    }
}
