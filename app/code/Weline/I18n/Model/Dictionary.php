<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/29 20:17:16
 */
namespace Weline\I18n\Model;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'i18n词典')]
#[Index(name: 'unq_word', columns: ['word'], type: 'UNIQUE', comment: '词唯一索引')]
#[Index(name: 'idx_module', columns: ['module'], comment: '模组索引')]
#[Index(name: 'idx_is_backend', columns: ['is_backend'], comment: '前后端标识索引')]
class Dictionary extends Model
{
    public const schema_table = 'i18n_dictionary';
    public const schema_primary_key = 'word';
    #[Col('text', primaryKey: true, nullable: false, unique: true, comment: '词')]
    public const schema_fields_ID = 'word';
    #[Col('text', nullable: false, comment: '词')]
    public const schema_fields_WORD = 'word';
    #[Col('int', 1, nullable: false, default: 0, comment: '是否后端：0前端，1后端')]
    public const schema_fields_IS_BACKEND = 'is_backend';
    #[Col('varchar', 255, comment: '模组名')]
    public const schema_fields_MODULE = 'module';
}
