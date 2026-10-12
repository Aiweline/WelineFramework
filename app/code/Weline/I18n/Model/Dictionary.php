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
#[Index(name: 'idx_is_backend', columns: ['is_backend'], comment: '已废弃：请用 area')]
#[Index(name: 'idx_area', columns: ['area'], comment: '词典区 frontend|backend|shared')]
#[Index(name: 'idx_collect_batch', columns: ['collect_batch'], comment: '收集批次索引')]
class Dictionary extends Model
{
    public const MAX_WORD_LENGTH = 512;
    public const schema_table = 'i18n_dictionary';
    public const schema_primary_key = 'word';
    #[Col('varchar', self::MAX_WORD_LENGTH, primaryKey: true, nullable: false, comment: '词')]
    public const schema_fields_ID = 'word';
    public const schema_fields_WORD = self::schema_fields_ID;
    /** @deprecated 使用 schema_fields_AREA；保留仅供回填 */
    #[Col('int', 1, nullable: false, default: 0, comment: '已废弃：0前端1后端，请用 area')]
    public const schema_fields_IS_BACKEND = 'is_backend';
    #[Col('varchar', 16, nullable: false, default: 'shared', comment: '词典区：frontend|backend|shared')]
    public const schema_fields_AREA = 'area';
    #[Col('varchar', 255, comment: '模组名')]
    public const schema_fields_MODULE = 'module';
    /** local / meta 收集共用；0=未触达。本轮仅 local 词写入；meta 后续复用。 */
    #[Col('int', 11, nullable: false, default: 0, comment: '收集批次（local/meta 共用；0=未触达）')]
    public const schema_fields_COLLECT_BATCH = 'collect_batch';

    /**
     * 词典「词」门禁：短 UI 源串，禁止把网页/事件长文、换行文档、BOM、引号失控串写入词典。
     *
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
        // Meta 译文归属 MetaLocal；禁止再写入 i18n_dictionary
        if (str_starts_with($word, '@meta::')) {
            throw new Exception((string)__('禁止将 @meta:: 键写入 I18n 词典，请使用 MetaLocal'));
        }
        if (!mb_check_encoding($word, 'UTF-8')) {
            throw new Exception((string)__('翻译词必须是有效的 UTF-8 文本'));
        }
        if (mb_strlen($word, 'UTF-8') > self::MAX_WORD_LENGTH) {
            throw new Exception((string)__('翻译词长度不能超过 %{1} 个 Unicode 字符', self::MAX_WORD_LENGTH));
        }
        // UTF-8 BOM 或 U+FEFF：采集/粘贴污染，不得当词条主键
        if (str_contains($word, "\u{FEFF}") || str_starts_with($word, "\xEF\xBB\xBF")) {
            throw new Exception((string)__('翻译词不能包含 BOM'));
        }
        // 换行：事件/文档长描述误入 __()，部件库/Markdown 才是落点
        if (str_contains($word, "\n") || str_contains($word, "\r")) {
            throw new Exception((string)__('翻译词不能包含换行'));
        }
        // 连续引号失控（前端采集/错误串递归包裹）
        if (preg_match('/["\']{4,}/u', $word) === 1) {
            throw new Exception((string)__('翻译词不能包含连续引号失控串'));
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
