<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/21 22:05:23
 */
namespace Weline\I18n\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
#[Table(comment: '地区/语言包')]
#[Index(name: 'idx_code_target', columns: ['code', 'target_code'], type: 'UNIQUE', comment: '区码+目标区码唯一索引')]
#[Index(name: 'idx_name', columns: ['name'], comment: '名字索引')]
#[Index(name: 'idx_is_active', columns: ['is_active'], comment: '状态索引')]
#[Index(name: 'idx_is_install', columns: ['is_install'], comment: '安装索引')]
class Locals extends Model
{
    public const schema_table = 'i18n_locals';
    public const schema_primary_keys = ['code', 'target_code'];
    #[Col('varchar', 10, nullable: false, comment: '地方代码')]
    public const schema_fields_ID = 'code';
    #[Col('varchar', 10, nullable: false, comment: '地方代码')]
    public const schema_fields_CODE = 'code';
    #[Col('varchar', 10, nullable: false, comment: '展示的地方代码')]
    public const schema_fields_TARGET_CODE = 'target_code';
    #[Col('varchar', 128, nullable: false, comment: '展示的地方代码对应地方代码名称')]
    public const schema_fields_NAME = 'name';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '启用状态')]
    public const schema_fields_IS_ACTIVE = 'is_active';
    #[Col('smallint', 1, nullable: false, default: 0, comment: '是否安装')]
    public const schema_fields_IS_INSTALL = 'is_install';
    #[Col('text', comment: 'svg国旗')]
    public const schema_fields_FLAG = 'flag';
    public array $_unit_primary_keys = ['code', 'target_code'];


    /**
     * 保存后清除语言缓存
     * 当语言数据更新时，清除缓存的语言列表，确保下次请求时重新加载最新数据
     */
    public function save_after()
    {
        parent::save_after();
        // 清除语言缓存
        try {
            w_cache('i18n')->clear();
            \Weline\Framework\Http\Url::bumpWebsiteParserSitesVersion();
        } catch (\Throwable $e) {
            // 缓存清除失败，静默处理
        }
    }
}
