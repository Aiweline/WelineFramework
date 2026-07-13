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
use Weline\I18n\Api\Translation\DictionaryEntry;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
#[Table(comment: '地区词典')]
#[Index(name: 'idx_code', columns: ['locale_code'], comment: '区码索引')]
class Dictionary extends Model implements DictionaryRepositoryInterface
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

    public function getEntry(string $word, string $localeCode): ?DictionaryEntry
    {
        $model = clone $this;
        $rows = $model->clearData()->clearQuery()
            ->where(self::schema_fields_MD5, self::generateMd5($word, $localeCode))
            ->select()
            ->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            return null;
        }

        $row = \is_array($rows[0] ?? null) ? $rows[0] : $rows;
        return $this->entryFromRow($row);
    }

    public function getEntries(array $words, string $localeCode): array
    {
        $words = \array_values(\array_unique(\array_filter(
            \array_map(static fn(mixed $word): string => (string)$word, $words),
            static fn(string $word): bool => $word !== '',
        )));
        if ($words === []) {
            return [];
        }

        $fingerprints = [];
        foreach ($words as $word) {
            $fingerprints[] = self::generateMd5($word, $localeCode);
        }
        $model = clone $this;
        $rows = $model->clearData()->clearQuery()
            ->where(self::schema_fields_MD5, $fingerprints, 'IN')
            ->select()
            ->fetchArray();
        $entries = [];
        foreach ((array)$rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $entry = $this->entryFromRow($row);
            if ($entry instanceof DictionaryEntry) {
                $entries[$entry->word] = $entry;
            }
        }
        return $entries;
    }

    public function listByWordPrefix(string $prefix): array
    {
        $model = clone $this;
        $rows = $model->clearData()->clearQuery()
            ->where(self::schema_fields_WORD, $prefix . '%', 'LIKE')
            ->select()
            ->fetchArray();
        $entries = [];
        foreach ((array)$rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $entry = $this->entryFromRow($row);
            if ($entry instanceof DictionaryEntry) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    public function upsert(string $word, string $localeCode, string $translation): bool
    {
        $model = clone $this;
        return (bool)$model->clearData()->clearQuery()
            ->insert([
                self::schema_fields_MD5 => self::generateMd5($word, $localeCode),
                self::schema_fields_WORD => $word,
                self::schema_fields_LOCALE_CODE => $localeCode,
                self::schema_fields_TRANSLATE => $translation,
            ], self::schema_fields_MD5)
            ->fetch();
    }

    public function deleteEntry(string $word, string $localeCode): bool
    {
        $model = clone $this;
        $model->clearData()->clearQuery()->load(self::schema_fields_MD5, self::generateMd5($word, $localeCode));
        if (!$model->getId()) {
            return false;
        }
        $model->delete()->fetch();
        return true;
    }

    /** @param array<string, mixed> $row */
    private function entryFromRow(array $row): ?DictionaryEntry
    {
        $word = (string)($row[self::schema_fields_WORD] ?? '');
        $localeCode = (string)($row[self::schema_fields_LOCALE_CODE] ?? '');
        if ($word === '' || $localeCode === '') {
            return null;
        }
        return new DictionaryEntry(
            $word,
            $localeCode,
            (string)($row[self::schema_fields_TRANSLATE] ?? ''),
        );
    }
}
