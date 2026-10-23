<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

/**
 * 国家级电话号码规则（数据权威：data/phone-country-rules.json）。
 *
 * 同一套算法同时服务：
 *  - 后端 AddressValidationService::isValidInternationalPhone（权威校验）
 *  - 店面 country_profile.phone（AddressCountryProfileService 透传给 address.js / 结账地址部件做前置校验）
 *
 * 解析顺序：字符集 → 国际写法（+ / 00）按区号定位国家 → 否则按所选国家当国内写法 → 去 trunk → 位数区间 → 可选 pattern。
 * 无规则国家 / 未知区号回退 E.164 总位数（default）。
 */
final class PhoneNumberRuleService
{
    private const RULES_PATH = __DIR__ . '/../data/phone-country-rules.json';

    public const ALLOWED_CHARS_PATTERN = '/^\+?[0-9\-\s().\/]+$/';

    /** @var array{default:array<string,int>,countries:array<string,array<string,mixed>>}|null */
    private static ?array $cache = null;

    /**
     * @return array{country_code:string,dial_code:string,trunk_prefix:string,national_min:int,national_max:int,national_pattern:string,example:string}|null
     */
    public function ruleFor(?string $countryCode): ?array
    {
        $code = strtoupper(trim((string)$countryCode));
        if ($code === '') {
            return null;
        }
        $raw = $this->document()['countries'][$code] ?? null;

        return is_array($raw) ? $this->normalizeRule($code, $raw) : null;
    }

    /**
     * 店面透传形态（与 ruleFor 同形；无规则返回 null，前端回退 E.164 总位数）。
     *
     * @return array{country_code:string,dial_code:string,trunk_prefix:string,national_min:int,national_max:int,national_pattern:string,example:string}|null
     */
    public function frontendRule(?string $countryCode): ?array
    {
        return $this->ruleFor($countryCode);
    }

    /**
     * @return array{e164_min_digits:int,e164_max_digits:int,national_min:int,national_max:int}
     */
    public function defaultRule(): array
    {
        $default = $this->document()['default'];

        return [
            'e164_min_digits' => (int)($default['e164_min_digits'] ?? 7),
            'e164_max_digits' => (int)($default['e164_max_digits'] ?? 15),
            'national_min' => (int)($default['national_min'] ?? 4),
            'national_max' => (int)($default['national_max'] ?? 14),
        ];
    }

    public function isValid(string $phone, ?string $countryCode = null): bool
    {
        return $this->analyze($phone, $countryCode)['valid'];
    }

    /**
     * @return array{valid:bool,reason:string,country_code:string,national:string,e164:string,rule:array<string,mixed>|null}
     */
    public function analyze(string $phone, ?string $countryCode = null): array
    {
        $phone = trim($phone);
        $selected = strtoupper(trim((string)$countryCode));
        $default = $this->defaultRule();
        $fail = static fn (string $reason, ?array $rule = null): array => [
            'valid' => false,
            'reason' => $reason,
            'country_code' => $selected,
            'national' => '',
            'e164' => '',
            'rule' => $rule,
        ];

        if ($phone === '') {
            return $fail('empty');
        }
        if (strlen($phone) > 32) {
            return $fail('too_long');
        }
        if (!preg_match(self::ALLOWED_CHARS_PATTERN, $phone)) {
            return $fail('chars');
        }
        if (substr_count($phone, '+') > 1 || (str_contains($phone, '+') && !str_starts_with($phone, '+'))) {
            return $fail('plus_position');
        }
        // + 之后必须直接是数字或 "(" 区号括号（(212) 555-0100）
        if (!preg_match('/^\+?[0-9(]/', $phone)) {
            return $fail('chars');
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $isInternational = str_starts_with($phone, '+');
        if (!$isInternational && str_starts_with($digits, '00') && strlen($digits) > 2) {
            $isInternational = true;
            $digits = substr($digits, 2);
        }

        if ($isInternational) {
            $len = strlen($digits);
            if ($len < $default['e164_min_digits'] || $len > $default['e164_max_digits']) {
                return $fail($len < $default['e164_min_digits'] ? 'too_short' : 'too_long');
            }
            $rule = $this->ruleForDialDigits($digits, $selected);
            if ($rule === null) {
                // 未知区号：仅 E.164 总位数兜底
                return [
                    'valid' => true,
                    'reason' => 'e164_fallback',
                    'country_code' => $selected,
                    'national' => $digits,
                    'e164' => '+' . $digits,
                    'rule' => null,
                ];
            }
            $national = substr($digits, strlen($rule['dial_code']));
            $national = $this->stripTrunkIfRedundant($national, $rule);

            return $this->checkNational($national, $rule);
        }

        $rule = $this->ruleFor($selected);
        if ($rule === null) {
            $len = strlen($digits);
            if ($len < $default['e164_min_digits'] || $len > $default['e164_max_digits']) {
                return $fail($len < $default['e164_min_digits'] ? 'too_short' : 'too_long');
            }

            return [
                'valid' => true,
                'reason' => 'e164_fallback',
                'country_code' => $selected,
                'national' => $digits,
                'e164' => '',
                'rule' => null,
            ];
        }

        $national = $digits;
        // 省略 + 却带了本国区号（如 US 常见 1XXXXXXXXXX）
        if (
            strlen($national) > $rule['national_max']
            && str_starts_with($national, $rule['dial_code'])
            && strlen($national) - strlen($rule['dial_code']) >= $rule['national_min']
        ) {
            $national = substr($national, strlen($rule['dial_code']));
        }
        if ($rule['trunk_prefix'] !== '' && str_starts_with($national, $rule['trunk_prefix'])) {
            $stripped = substr($national, strlen($rule['trunk_prefix']));
            if (strlen($stripped) >= $rule['national_min']) {
                $national = $stripped;
            }
        }

        return $this->checkNational($national, $rule);
    }

    /**
     * 按区号最长前缀定位规则；所选国家区号命中时优先用所选国家（US/CA 共用 1 等）。
     *
     * @return array{country_code:string,dial_code:string,trunk_prefix:string,national_min:int,national_max:int,national_pattern:string,example:string}|null
     */
    public function ruleForDialDigits(string $digits, ?string $preferredCountry = null): ?array
    {
        $preferred = $this->ruleFor($preferredCountry);
        if ($preferred !== null && $preferred['dial_code'] !== '' && str_starts_with($digits, $preferred['dial_code'])) {
            return $preferred;
        }
        $best = null;
        foreach ($this->document()['countries'] as $code => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $rule = $this->normalizeRule((string)$code, $raw);
            if ($rule['dial_code'] === '' || !str_starts_with($digits, $rule['dial_code'])) {
                continue;
            }
            if ($best === null || strlen($rule['dial_code']) > strlen($best['dial_code'])) {
                $best = $rule;
            }
        }

        return $best;
    }

    /**
     * @param array{country_code:string,dial_code:string,trunk_prefix:string,national_min:int,national_max:int,national_pattern:string,example:string} $rule
     * @return array{valid:bool,reason:string,country_code:string,national:string,e164:string,rule:array<string,mixed>}
     */
    private function checkNational(string $national, array $rule): array
    {
        $len = strlen($national);
        $result = [
            'valid' => false,
            'reason' => '',
            'country_code' => $rule['country_code'],
            'national' => $national,
            'e164' => $rule['dial_code'] !== '' ? '+' . $rule['dial_code'] . $national : '',
            'rule' => $rule,
        ];
        if ($len < $rule['national_min']) {
            $result['reason'] = 'too_short';

            return $result;
        }
        if ($len > $rule['national_max']) {
            $result['reason'] = 'too_long';

            return $result;
        }
        if ($rule['national_pattern'] !== '' && !preg_match('/' . $rule['national_pattern'] . '/', $national)) {
            $result['reason'] = 'pattern';

            return $result;
        }
        $result['valid'] = true;
        $result['reason'] = 'ok';

        return $result;
    }

    /**
     * 国际写法里多带了 trunk（+86 0138…）：去掉后仍在区间才去。
     *
     * @param array{trunk_prefix:string,national_min:int,national_max:int} $rule
     */
    private function stripTrunkIfRedundant(string $national, array $rule): string
    {
        if ($rule['trunk_prefix'] === '' || !str_starts_with($national, $rule['trunk_prefix'])) {
            return $national;
        }
        if (strlen($national) <= $rule['national_max']) {
            return $national;
        }
        $stripped = substr($national, strlen($rule['trunk_prefix']));

        return strlen($stripped) >= $rule['national_min'] ? $stripped : $national;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{country_code:string,dial_code:string,trunk_prefix:string,national_min:int,national_max:int,national_pattern:string,example:string}
     */
    private function normalizeRule(string $code, array $raw): array
    {
        $default = $this->defaultRule();
        $min = max(1, (int)($raw['national_min'] ?? $default['national_min']));
        $max = max($min, (int)($raw['national_max'] ?? $default['national_max']));

        return [
            'country_code' => strtoupper($code),
            'dial_code' => preg_replace('/\D+/', '', (string)($raw['dial_code'] ?? '')) ?? '',
            'trunk_prefix' => preg_replace('/\D+/', '', (string)($raw['trunk_prefix'] ?? '')) ?? '',
            'national_min' => $min,
            'national_max' => $max,
            'national_pattern' => trim((string)($raw['national_pattern'] ?? '')),
            'example' => trim((string)($raw['example'] ?? '')),
        ];
    }

    /**
     * @return array{default:array<string,int>,countries:array<string,array<string,mixed>>}
     */
    private function document(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        if (!is_file(self::RULES_PATH)) {
            throw new \RuntimeException('Phone country rules file missing: ' . self::RULES_PATH);
        }
        $raw = file_get_contents(self::RULES_PATH);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException('Phone country rules file is not valid JSON: ' . self::RULES_PATH . ' (' . json_last_error_msg() . ')');
        }
        self::$cache = [
            'default' => is_array($decoded['default'] ?? null) ? $decoded['default'] : [],
            'countries' => is_array($decoded['countries'] ?? null) ? $decoded['countries'] : [],
        ];

        return self::$cache;
    }
}
