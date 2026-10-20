<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Repository;

use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\I18n\Api\Localization\Data\LocaleRecord;
use Weline\I18n\Api\Localization\LocaleRepositoryInterface;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

final class LocaleRepository implements LocaleRepositoryInterface
{
    /** 进程袋身份（显式命名，禁止匿名登记）。 */
    private const BAG_INSTALLED_ACTIVE = 'i18n.repository.locale.installed_active';

    /** @var array<string, list<LocaleRecord>> Worker 进程级 installed+active 记录，按 displayLocale 分区 */
    private static array $processInstalledActive = [];

    public function __construct(
        private readonly Locals $locals,
        private readonly I18n $i18n,
    ) {
    }

    /**
     * 清空进程级缓存：locale 安装/启停后由观察者调用。
     */
    public static function clearProcessCache(): void
    {
        self::$processInstalledActive = [];
    }

    public function installedActive(string $displayLocale): array
    {
        $cacheKey = DictionaryCacheNamespace::cacheKey('installed-active|' . $displayLocale, [$displayLocale]);
        $bag = &DictionaryCacheNamespace::processBag(
            self::BAG_INSTALLED_ACTIVE,
            self::$processInstalledActive,
            16,
            [$displayLocale],
        );
        if (isset($bag[$cacheKey])) {
            return $bag[$cacheKey];
        }

        $rows = $this->locals->reset()
            ->where(Locals::schema_fields_IS_INSTALL, 1)
            ->where(Locals::schema_fields_IS_ACTIVE, 1)
            ->order(Locals::schema_fields_CODE, 'ASC')
            ->select()
            ->fetchArray();
        $names = $this->i18n->getLocals($displayLocale);
        $records = [];
        $seen = [];
        foreach ($rows as $locale) {
            if (!is_array($locale)) {
                continue;
            }
            $code = trim((string)($locale[Locals::schema_fields_CODE] ?? ''));
            $key = strtolower($code);
            if ($code === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $records[] = new LocaleRecord(
                code: $code,
                displayLocale: $displayLocale,
                displayName: (string)($names[$code] ?? $locale[Locals::schema_fields_NAME] ?? $code),
                flag: (string)($locale[Locals::schema_fields_FLAG] ?? ''),
                active: (bool)($locale[Locals::schema_fields_IS_ACTIVE] ?? false),
                installed: (bool)($locale[Locals::schema_fields_IS_INSTALL] ?? false),
            );
        }

        $bag[$cacheKey] = $records;

        return $records;
    }

    public function resolveCode(string $localeCode, string $fallback = 'zh_Hans_CN'): string
    {
        $resolved = trim($this->i18n->getLocalByCode($localeCode));
        if ($resolved !== '') {
            return $resolved;
        }

        $resolved = trim($this->i18n->getLocalByCode($fallback));
        return $resolved !== '' ? $resolved : $fallback;
    }
}
