<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/12/29 22:34:35
 */
namespace Weline\I18n\Model\Locale;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '地区词典')]
#[Index(name: 'idx_code', columns: ['locale_code'], comment: '区码索引')]
class Dictionary extends Model
{
    public const schema_table = 'i18n_locale_dictionary';
    public const schema_primary_key = 'md5';
    #[Col('varchar', 128, primaryKey: true, nullable: false, comment: 'MD5指纹')]
    public const schema_fields_ID = 'md5';
    #[Col('varchar', 128, primaryKey: true, nullable: false, comment: 'MD5指纹')]
    public const schema_fields_MD5 = 'md5';
    #[Col('text', nullable: false, comment: '词')]
    public const schema_fields_WORD = 'word';
    #[Col('varchar', 12, nullable: false, comment: '地区码')]
    public const schema_fields_LOCALE_CODE = 'locale_code';
    #[Col('text', nullable: false, comment: '翻译')]
    public const schema_fields_TRANSLATE = 'translate';
    #[Col('int', 1, nullable: false, default: 0, comment: '是否AI翻译')]
    public const schema_fields_IS_AI = 'is_ai';
    #[Col('varchar', 128, nullable: true, comment: '来源模块')]
    public const schema_fields_SOURCE_MODULE = 'source_module';
    #[Col('varchar', 32, nullable: true, comment: '导出时间')]
    public const schema_fields_EXPORTED_AT = 'exported_at';
/**
     * 生成统一的MD5指纹
     * 确保所有地方使用相同的算法生成MD5
     * 
     * @param string $word 词汇
     * @param string $locale_code 语言代码
     * @return string MD5指纹
     */
    public static function generateMd5(string $word, string $locale_code): string
    {
        return md5($word . $locale_code);
    }
    /**
     * 根据词汇和语言代码获取MD5
     * 
     * @param string $word 词汇
     * @param string $locale_code 语言代码
     * @return string MD5指纹
     */
    public function getMd5(string $word, string $locale_code): string
    {
        return self::generateMd5($word, $locale_code);
    }
}
