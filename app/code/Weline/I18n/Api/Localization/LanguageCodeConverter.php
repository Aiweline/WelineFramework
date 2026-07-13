<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

/**
 * Public locale-code conversion contract owned by I18n.
 *
 * Supports ISO 639-1, ISO 639-2 and BCP 47 language codes. Consumers must
 * depend on Weline_I18n instead of importing an integration module helper.
 */
class LanguageCodeConverter
{
    /** @var array<string, string> */
    private static array $iso6391To6392 = [
        'aa' => 'aar', 'ab' => 'abk', 'ae' => 'ave', 'af' => 'afr', 'ak' => 'aka',
        'am' => 'amh', 'an' => 'arg', 'ar' => 'ara', 'as' => 'asm', 'av' => 'ava',
        'ay' => 'aym', 'az' => 'aze', 'ba' => 'bak', 'be' => 'bel', 'bg' => 'bul',
        'bh' => 'bih', 'bi' => 'bis', 'bm' => 'bam', 'bn' => 'ben', 'bo' => 'bod',
        'br' => 'bre', 'bs' => 'bos', 'ca' => 'cat', 'ce' => 'che', 'ch' => 'cha',
        'co' => 'cos', 'cr' => 'cre', 'cs' => 'ces', 'cu' => 'chu', 'cv' => 'chv',
        'cy' => 'cym', 'da' => 'dan', 'de' => 'deu', 'dv' => 'div', 'dz' => 'dzo',
        'ee' => 'ewe', 'el' => 'ell', 'en' => 'eng', 'eo' => 'epo', 'es' => 'spa',
        'et' => 'est', 'eu' => 'eus', 'fa' => 'fas', 'ff' => 'ful', 'fi' => 'fin',
        'fj' => 'fij', 'fo' => 'fao', 'fr' => 'fra', 'fy' => 'fry', 'ga' => 'gle',
        'gd' => 'gla', 'gl' => 'glg', 'gn' => 'grn', 'gu' => 'guj', 'gv' => 'glv',
        'ha' => 'hau', 'he' => 'heb', 'hi' => 'hin', 'ho' => 'hmo', 'hr' => 'hrv',
        'ht' => 'hat', 'hu' => 'hun', 'hy' => 'hye', 'hz' => 'her', 'ia' => 'ina',
        'id' => 'ind', 'ie' => 'ile', 'ig' => 'ibo', 'ii' => 'iii', 'ik' => 'ipk',
        'io' => 'ido', 'is' => 'isl', 'it' => 'ita', 'iu' => 'iku', 'ja' => 'jpn',
        'jv' => 'jav', 'ka' => 'kat', 'kg' => 'kon', 'ki' => 'kik', 'kj' => 'kua',
        'kk' => 'kaz', 'kl' => 'kal', 'km' => 'khm', 'kn' => 'kan', 'ko' => 'kor',
        'kr' => 'kau', 'ks' => 'kas', 'ku' => 'kur', 'kv' => 'kom', 'kw' => 'cor',
        'ky' => 'kir', 'la' => 'lat', 'lb' => 'ltz', 'lg' => 'lug', 'li' => 'lim',
        'ln' => 'lin', 'lo' => 'lao', 'lt' => 'lit', 'lu' => 'lub', 'lv' => 'lvs',
        'mg' => 'mlg', 'mh' => 'mah', 'mi' => 'mri', 'mk' => 'mkd', 'ml' => 'mal',
        'mn' => 'mon', 'mr' => 'mar', 'ms' => 'msa', 'mt' => 'mlt', 'my' => 'mya',
        'na' => 'nau', 'nb' => 'nob', 'nd' => 'nde', 'ne' => 'nep', 'ng' => 'ndo',
        'nl' => 'nld', 'nn' => 'nno', 'no' => 'nor', 'nr' => 'nbl', 'nv' => 'nav',
        'ny' => 'nya', 'oc' => 'oci', 'oj' => 'oji', 'om' => 'orm', 'or' => 'ori',
        'os' => 'oss', 'pa' => 'pan', 'pi' => 'pli', 'pl' => 'pol', 'ps' => 'pus',
        'pt' => 'por', 'qu' => 'que', 'rm' => 'roh', 'rn' => 'run', 'ro' => 'ron',
        'ru' => 'rus', 'rw' => 'kin', 'sa' => 'san', 'sc' => 'srd', 'sd' => 'snd',
        'se' => 'sme', 'sg' => 'sag', 'si' => 'sin', 'sk' => 'slk', 'sl' => 'slv',
        'sm' => 'smo', 'sn' => 'sna', 'so' => 'som', 'sq' => 'sqi', 'sr' => 'srp',
        'ss' => 'ssw', 'st' => 'sot', 'su' => 'sun', 'sv' => 'swe', 'sw' => 'swa',
        'ta' => 'tam', 'te' => 'tel', 'tg' => 'tgk', 'th' => 'tha', 'ti' => 'tir',
        'tk' => 'tuk', 'tl' => 'tgl', 'tn' => 'tsn', 'to' => 'ton', 'tr' => 'tur',
        'ts' => 'tso', 'tt' => 'tat', 'tw' => 'twi', 'ty' => 'tah', 'ug' => 'uig',
        'uk' => 'ukr', 'ur' => 'urd', 'uz' => 'uzb', 've' => 'ven', 'vi' => 'vie',
        'vo' => 'vol', 'wa' => 'wln', 'wo' => 'wol', 'xh' => 'xho', 'yi' => 'yid',
        'yo' => 'yor', 'za' => 'zha', 'zh' => 'zho', 'zu' => 'zul',
    ];

    /** @var array<string, string> */
    private static array $languageNames = [
        'zh' => '中文', 'en' => 'English', 'ja' => '日本語', 'ko' => '한국어',
        'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español', 'ru' => 'Русский',
        'ar' => 'العربية', 'pt' => 'Português', 'it' => 'Italiano', 'nl' => 'Nederlands',
        'pl' => 'Polski', 'tr' => 'Türkçe', 'vi' => 'Tiếng Việt', 'th' => 'ไทย',
        'id' => 'Bahasa Indonesia', 'hi' => 'हिन्दी', 'cs' => 'Čeština', 'sv' => 'Svenska',
        'da' => 'Dansk', 'fi' => 'Suomi', 'no' => 'Norsk', 'ro' => 'Română',
        'hu' => 'Magyar', 'el' => 'Ελληνικά', 'he' => 'עברית', 'uk' => 'Українська',
        'bg' => 'Български', 'hr' => 'Hrvatski', 'sk' => 'Slovenčina', 'sl' => 'Slovenščina',
        'et' => 'Eesti', 'lv' => 'Latviešu', 'lt' => 'Lietuvių', 'mt' => 'Malti',
        'ga' => 'Gaeilge', 'cy' => 'Cymraeg', 'ca' => 'Català', 'eu' => 'Euskara',
        'gl' => 'Galego', 'is' => 'Íslenska', 'fo' => 'Føroyskt', 'lb' => 'Lëtzebuergesch',
    ];

    /** @var array<string, string> */
    private static array $bcp47ToIso6391 = [
        'zh-Hans' => 'zh', 'zh-Hant' => 'zh', 'zh-CN' => 'zh', 'zh-TW' => 'zh',
        'zh-HK' => 'zh', 'zh-SG' => 'zh', 'en-US' => 'en', 'en-GB' => 'en',
        'en-AU' => 'en', 'en-CA' => 'en', 'en-NZ' => 'en', 'en-IE' => 'en',
        'en-ZA' => 'en', 'fr-FR' => 'fr', 'fr-CA' => 'fr', 'fr-BE' => 'fr',
        'fr-CH' => 'fr', 'de-DE' => 'de', 'de-AT' => 'de', 'de-CH' => 'de',
        'es-ES' => 'es', 'es-MX' => 'es', 'es-AR' => 'es', 'es-CO' => 'es',
        'pt-BR' => 'pt', 'pt-PT' => 'pt', 'ar-SA' => 'ar', 'ar-EG' => 'ar',
        'ar-AE' => 'ar', 'ru-RU' => 'ru', 'ja-JP' => 'ja', 'ko-KR' => 'ko',
    ];

    public static function toIso6391(string $languageCode): string
    {
        $code = strtolower(trim($languageCode));
        if (strpos($code, '-') !== false) {
            $parts = explode('-', $code);
            $code = $parts[0];
            if (isset(self::$bcp47ToIso6391[$languageCode])) {
                return self::$bcp47ToIso6391[$languageCode];
            }
        }
        if (strpos($code, '_') !== false) {
            $parts = explode('_', $code);
            $code = $parts[0];
        }
        if (strlen($code) === 3) {
            $iso6391 = array_search($code, self::$iso6391To6392, true);
            if ($iso6391 !== false) {
                return $iso6391;
            }
        }
        if (strlen($code) === 2 && preg_match('/^[a-z]{2}$/', $code)) {
            return $code;
        }
        return $code;
    }

    public static function toIso6392(string $languageCode): string
    {
        $iso6391 = self::toIso6391($languageCode);
        return self::$iso6391To6392[$iso6391] ?? $iso6391;
    }

    public static function toBcp47(string $languageCode, ?string $region = null): string
    {
        $iso6391 = self::toIso6391($languageCode);
        if ($region) {
            return strtolower($iso6391) . '-' . strtoupper($region);
        }
        if (strpos($languageCode, '-') !== false) {
            $parts = explode('-', $languageCode);
            if (count($parts) >= 2) {
                return strtolower($iso6391) . '-' . strtoupper($parts[1]);
            }
        }
        if (strpos($languageCode, '_') !== false) {
            $parts = explode('_', $languageCode);
            if (count($parts) >= 2) {
                return strtolower($iso6391) . '-' . strtoupper($parts[1]);
            }
        }
        return strtolower($iso6391);
    }

    public static function getLanguageName(string $languageCode, string $locale = 'zh'): string
    {
        $iso6391 = self::toIso6391($languageCode);
        return self::$languageNames[$iso6391] ?? $iso6391;
    }

    /** @return list<string> */
    public static function getSupportedLanguages(): array
    {
        return array_keys(self::$languageNames);
    }

    public static function isValid(string $languageCode): bool
    {
        $iso6391 = self::toIso6391($languageCode);
        return isset(self::$languageNames[$iso6391]) || isset(self::$iso6391To6392[$iso6391]);
    }

    public static function normalize(string $languageCode): string
    {
        return self::toIso6391($languageCode);
    }
}
