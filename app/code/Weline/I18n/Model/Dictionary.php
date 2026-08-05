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

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: 'i18n词典')]
#[Index(name: 'idx_module', columns: ['module'], comment: '模组索引')]
#[Index(name: 'idx_is_backend', columns: ['is_backend'], comment: '前后端标识索引')]
class Dictionary extends Model
{
    public const MAX_WORD_LENGTH = 512;
    public const schema_table = 'i18n_dictionary';
    public const schema_primary_key = 'word';
    #[Col('varchar', self::MAX_WORD_LENGTH, primaryKey: true, nullable: false, comment: '词')]
    public const schema_fields_ID = 'word';
    public const schema_fields_WORD = self::schema_fields_ID;
    #[Col('int', 1, nullable: false, default: 0, comment: '是否后端：0前端，1后端')]
    public const schema_fields_IS_BACKEND = 'is_backend';
    #[Col('varchar', 255, comment: '模组名')]
    public const schema_fields_MODULE = 'module';

    /**
     * @throws Exception
     */
    public static function assertWord(mixed $word): string
    {
        if (!is_string($word) && !is_int($word)) {
            throw new Exception((string)__('翻译词必须是字符串或整数'));
        }
        $word = (string)$word;
        if ($word === '') {
            throw new Exception((string)__('翻译词不能为空'));
        }
        if (!mb_check_encoding($word, 'UTF-8')) {
            throw new Exception((string)__('翻译词必须是有效的 UTF-8 文本'));
        }
        if (mb_strlen($word, 'UTF-8') > self::MAX_WORD_LENGTH) {
            throw new Exception((string)__('翻译词长度不能超过 %{1} 个 Unicode 字符', self::MAX_WORD_LENGTH));
        }

        return $word;
    }

    public function save_before(): void
    {
        $word = $this->getData(self::schema_fields_WORD);
        if ($word !== null) {
            $this->setData(self::schema_fields_WORD, self::assertWord($word));
        }
    }
}
