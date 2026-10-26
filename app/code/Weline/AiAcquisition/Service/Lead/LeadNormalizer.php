<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Lead;

use InvalidArgumentException;
use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Service\Catalog\ContinentCatalog;
use Weline\AiAcquisition\Service\Catalog\CountryCatalog;

/**
 * 线索归一化：把搜索/页面抽取/AI 回传的松散数据收敛成可入库字段。
 *
 * 全部为纯函数，不访问数据库，便于单元测试与在入库前做统一校验。
 */
final class LeadNormalizer
{
    /** 需要从 URL 中剔除的常见跟踪参数。 */
    private const TRACKING_PARAMS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'utm_id',
        'utm_source_platform',
        'gclid',
        'dclid',
        'gbraid',
        'wbraid',
        'fbclid',
        'msclkid',
        'yclid',
        'twclid',
        'igshid',
        'mc_cid',
        'mc_eid',
        'spm',
        'scm',
        'ref',
        'ref_src',
        'ref_url',
        'si',
    ];

    /**
     * 归一化线索数据。
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws InvalidArgumentException 状态、评分、时间、URL 或去重键不合法时抛出
     */
    public static function normalize(array $input): array
    {
        $email = self::normalizeEmail(self::text(self::pick($input, ['email', 'email_address'])));
        $sourceUrl = self::normalizeUrl(self::text(self::pick($input, ['source_url', 'url'])));
        $companyDomain = self::normalizeDomain(self::text(self::pick($input, ['company_domain', 'domain'])));
        $companyName = self::collapseWhitespace(self::text(self::pick($input, ['company_name', 'company'])));

        $countryCodeRaw = self::text(self::pick($input, ['country_code', 'country']));
        $countryNameRaw = self::text(self::pick($input, ['country_name']));
        $country = CountryCatalog::resolve($countryCodeRaw !== '' ? $countryCodeRaw : $countryNameRaw);
        // 代码是无效值但名称可识别时，以名称为准回填。
        if (
            $country['country_code'] === CountryCatalog::UNKNOWN_CODE
            && $countryCodeRaw !== ''
            && $countryNameRaw !== ''
        ) {
            $country = CountryCatalog::resolve($countryNameRaw);
        }
        $countryKnown = $country['country_code'] !== CountryCatalog::UNKNOWN_CODE;

        $continent = ContinentCatalog::normalize(
            self::text(self::pick($input, ['continent_code', 'continent']))
        );
        if ($continent === ContinentCatalog::UNKNOWN && $countryKnown) {
            $continent = $country['continent_code'];
        }

        $timezone = self::text(self::pick($input, ['timezone']));
        if ($timezone === '' && $countryKnown) {
            $timezone = $country['timezone'];
        }

        $latitude = self::normalizeCoordinate(self::pick($input, ['latitude', 'lat']), -90.0, 90.0, 'latitude');
        $longitude = self::normalizeCoordinate(self::pick($input, ['longitude', 'lng', 'lon']), -180.0, 180.0, 'longitude');
        if ($latitude === null && $countryKnown) {
            $latitude = $country['latitude'];
        }
        if ($longitude === null && $countryKnown) {
            $longitude = $country['longitude'];
        }

        $status = strtolower(self::text(self::pick($input, ['status'])));
        if ($status === '') {
            $status = Lead::STATUS_NEW;
        }
        if (!isset(Lead::STATUSES[$status])) {
            throw new InvalidArgumentException('无效的线索状态：' . $status);
        }

        $emailStatus = strtolower(self::text(self::pick($input, ['email_status'])));
        if ($emailStatus !== '') {
            if (!\in_array($emailStatus, [
                Lead::EMAIL_UNKNOWN,
                Lead::EMAIL_VALID,
                Lead::EMAIL_RISKY,
                Lead::EMAIL_INVALID,
            ], true)) {
                throw new InvalidArgumentException('无效的邮箱状态：' . $emailStatus);
            }
        } elseif ($email === '') {
            $emailStatus = Lead::EMAIL_UNKNOWN;
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $emailStatus = Lead::EMAIL_INVALID;
        } else {
            $emailStatus = Lead::EMAIL_UNKNOWN;
        }

        $dedupeKey = $email !== '' ? $email : ($companyDomain !== '' ? $companyDomain : $sourceUrl);
        if ($dedupeKey === '') {
            throw new InvalidArgumentException('线索至少需要邮箱、公司域名或来源 URL 之一用于去重。');
        }

        $sourcePlatform = strtolower(self::text(self::pick($input, ['source_platform', 'platform'])));
        if ($sourcePlatform === '') {
            $sourcePlatform = 'web';
        }

        $entryChannel = strtolower(self::text(self::pick($input, ['entry_channel', 'entry_channel_code'])));
        if ($entryChannel === '') {
            $entryChannel = Lead::ENTRY_CHANNEL_DEFAULT;
        }
        if (!isset(Lead::ENTRY_CHANNELS[$entryChannel])) {
            throw new InvalidArgumentException('无效的录入渠道：' . $entryChannel);
        }

        $countryName = $countryKnown
            ? $country['country_name']
            : ($countryNameRaw !== '' ? self::collapseWhitespace($countryNameRaw) : CountryCatalog::UNKNOWN_NAME);

        return [
            Lead::schema_fields_WEBSITE_ID => max(
                0,
                self::normalizeInt(self::pick($input, ['website_id']), 0, 'website_id')
            ),
            Lead::schema_fields_CONTINENT_CODE => $continent,
            Lead::schema_fields_COUNTRY_CODE => $country['country_code'],
            Lead::schema_fields_COUNTRY_NAME => $countryName,
            Lead::schema_fields_REGION => self::text(self::pick($input, ['region'])),
            Lead::schema_fields_CITY => self::text(self::pick($input, ['city'])),
            Lead::schema_fields_LATITUDE => $latitude,
            Lead::schema_fields_LONGITUDE => $longitude,
            Lead::schema_fields_TIMEZONE => $timezone,
            Lead::schema_fields_COMPANY_NAME => $companyName,
            Lead::schema_fields_COMPANY_DOMAIN => $companyDomain,
            Lead::schema_fields_CONTACT_NAME => self::text(self::pick($input, ['contact_name'])),
            Lead::schema_fields_CONTACT_ROLE => self::text(self::pick($input, ['contact_role'])),
            Lead::schema_fields_EMAIL => $email,
            Lead::schema_fields_EMAIL_STATUS => $emailStatus,
            Lead::schema_fields_PHONE => self::text(self::pick($input, ['phone'])),
            Lead::schema_fields_SOCIAL_PROFILES => self::normalizeJsonField(
                self::pick($input, ['social_profiles']),
                'social_profiles'
            ),
            Lead::schema_fields_SOURCE_URL => $sourceUrl,
            Lead::schema_fields_SOURCE_TITLE => self::text(self::pick($input, ['source_title', 'title'])),
            Lead::schema_fields_SOURCE_PLATFORM => $sourcePlatform,
            Lead::schema_fields_ENTRY_CHANNEL => $entryChannel,
            Lead::schema_fields_SOURCE_EXCERPT => self::text(self::pick($input, ['source_excerpt', 'excerpt'])),
            Lead::schema_fields_SOURCE_PUBLISHED_AT => self::normalizeTimestamp(
                self::pick($input, ['source_published_at']),
                'source_published_at'
            ),
            Lead::schema_fields_AI_PREFERENCE => self::text(self::pick($input, ['ai_preference', 'preference'])),
            Lead::schema_fields_AI_BUSINESS_DIRECTION => self::text(
                self::pick($input, ['ai_business_direction', 'business_direction'])
            ),
            Lead::schema_fields_AI_SCORE => self::normalizeScore(self::pick($input, ['ai_score', 'score'])),
            Lead::schema_fields_AI_REASON => self::text(self::pick($input, ['ai_reason', 'reason'])),
            Lead::schema_fields_AI_MODEL_CODE => self::text(self::pick($input, ['ai_model_code'])),
            Lead::schema_fields_AI_INFERRED_AT => self::normalizeTimestamp(
                self::pick($input, ['ai_inferred_at']),
                'ai_inferred_at'
            ),
            Lead::schema_fields_STATUS => $status,
            Lead::schema_fields_TAGS => self::normalizeJsonField(self::pick($input, ['tags']), 'tags'),
            Lead::schema_fields_LEAD_TYPE => self::normalizeLeadType(
                self::text(self::pick($input, ['lead_type', 'type']))
            ),
            Lead::schema_fields_OUTREACH_TARGET => self::normalizeBool(
                self::pick($input, ['outreach_target'])
            ),
            Lead::schema_fields_AI_AUTO_REPLY => self::normalizeBool(
                self::pick($input, ['ai_auto_reply'])
            ),
            Lead::schema_fields_CUSTOMER_ID => self::normalizeInt(
                self::pick($input, ['customer_id']), 0, 'customer_id'
            ),
            Lead::schema_fields_FIRST_SEEN_DATE => self::normalizeFirstSeenDate(
                self::text(self::pick($input, ['first_seen_date']))
            ),
            Lead::schema_fields_SEEN_COUNT => max(1, self::normalizeInt(self::pick($input, ['seen_count']), 1, 'seen_count')),
            Lead::schema_fields_DEDUPE_HASH => hash('sha256', $dedupeKey),
        ];
    }

    /**
     * 取第一个非空值，便于兼容 AI 工具与后台表单的字段别名。
     *
     * @param array<string,mixed> $input
     * @param list<string> $keys
     */
    private static function pick(array $input, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (\is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
                continue;
            }
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (\is_string($value)) {
            return trim($value);
        }
        if (\is_int($value) || \is_float($value)) {
            return (string)$value;
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return '';
    }

    private static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return '';
        }
        $email = (string)(preg_replace('/^mailto:/i', '', $email) ?? $email);
        $email = explode('?', $email, 2)[0];
        $email = trim($email, " \t\n\r\0\x0B<>");

        return $email;
    }

    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $url = trim($url, " \t\n\r\0\x0B<>");
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!\is_array($parts) || empty($parts['host']) || preg_match('/\s/', (string)$parts['host']) === 1) {
            throw new InvalidArgumentException('无效的来源 URL：' . $url);
        }

        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $queryPairs = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            foreach (explode('&', (string)$parts['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $name = urldecode(explode('=', $pair, 2)[0]);
                if (\in_array(strtolower($name), self::TRACKING_PARAMS, true)) {
                    continue;
                }
                $queryPairs[] = $pair;
            }
        }

        $rebuilt = $scheme . '://';
        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'];
            if (isset($parts['pass'])) {
                $rebuilt .= ':' . $parts['pass'];
            }
            $rebuilt .= '@';
        }
        $rebuilt .= $host;
        if ($port !== null) {
            $rebuilt .= ':' . $port;
        }
        $rebuilt .= (string)($parts['path'] ?? '');
        if ($queryPairs !== []) {
            $rebuilt .= '?' . implode('&', $queryPairs);
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }
        $domain = trim($domain, " \t\n\r\0\x0B<>");
        $domain = (string)(preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $domain) ?? $domain);
        $domain = explode('/', $domain, 2)[0];
        $domain = explode('?', $domain, 2)[0];
        $domain = explode('#', $domain, 2)[0];
        $domain = (string)(preg_replace('/:\d+$/', '', $domain) ?? $domain);
        $domain = rtrim($domain, '.');
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }

    private static function collapseWhitespace(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return \is_string($collapsed) ? $collapsed : $value;
    }

    private static function normalizeScore(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('AI 评分必须是数字。');
        }
        $score = (float)$value;
        $score = max(0.0, min(10.0, $score));

        return round($score, 2);
    }

    private static function normalizeInt(mixed $value, int $default, string $field): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('字段 ' . $field . ' 必须是整数。');
        }

        return (int)$value;
    }

    private static function normalizeCoordinate(mixed $value, float $min, float $max, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('字段 ' . $field . ' 必须是数字。');
        }
        $coordinate = (float)$value;
        if ($coordinate < $min || $coordinate > $max) {
            throw new InvalidArgumentException('字段 ' . $field . ' 超出取值范围。');
        }

        return $coordinate;
    }

    private static function normalizeTimestamp(mixed $value, string $field): int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return 0;
        }
        if (is_numeric($value)) {
            return max(0, (int)$value);
        }
        if (\is_string($value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        throw new InvalidArgumentException('字段 ' . $field . ' 不是有效时间。');
    }


    /**
     * 线索类型：Lead::LEAD_TYPES 枚举，未知/空值回落 unknown。
     */
    private static function normalizeLeadType(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || !isset(Lead::LEAD_TYPES[$value])) {
            return Lead::LEAD_TYPE_UNKNOWN;
        }
        return $value;
    }

    /**
     * 布尔归一：接受 true/false/0/1/'1'/'0'/'true'/'false'/'yes'/'no'；缺省按开处理（需求：默认开）。
     */
    private static function normalizeBool(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 1;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    /**
     * 首见日期：空值回落当日；支持 Y-m-d 与时间戳。
     */
    private static function normalizeFirstSeenDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return date('Y-m-d');
        }
        if (ctype_digit($value)) {
            return date('Y-m-d', (int)$value);
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new InvalidArgumentException('无效的 first_seen_date：' . $value);
        }
        return date('Y-m-d', $ts);
    }

    private static function normalizeJsonField(mixed $value, string $field): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (\is_string($value)) {
            return trim($value);
        }
        if (\is_array($value)) {
            if ($value === []) {
                return '';
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new InvalidArgumentException('字段 ' . $field . ' 无法序列化为 JSON。');
            }

            return $json;
        }

        return self::text($value);
    }
}
