<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Localization\LocalizationProviderRegistry;
use Weline\Framework\Manager\ObjectManager;

/**
 * 网站子路径（sub_path）禁止携带语言码与货币码。
 *
 * 前台 URL 解析会把路径段识别为 locale / currency；站点挂载路径不得占用这些段。
 */
final class WebsiteSubPathValidator
{
    private const RESERVED_SEGMENTS = [
        'static' => true,
        'pub' => true,
        'media' => true,
        'api' => true,
        'admin' => true,
        'favicon.ico' => true,
        'robots.txt' => true,
        'sitemap.xml' => true,
    ];

    /** @var array<string, true> */
    private array $languageLookup;

    /** @var array<string, true> */
    private array $currencyLookup;

    /**
     * @param list<string> $languageCodes
     * @param list<string> $currencyCodes
     */
    public function __construct(
        array $languageCodes = [],
        array $currencyCodes = [],
    ) {
        $this->languageLookup = self::buildLookup($languageCodes, false);
        $this->currencyLookup = self::buildLookup($currencyCodes, true);
    }

    public static function fromLocalizationRegistry(?LocalizationProviderRegistry $registry = null): self
    {
        $registry ??= ObjectManager::getInstance(LocalizationProviderRegistry::class);
        $languages = [];
        $currencies = [];
        try {
            $languages = $registry->preferredLanguageCodes();
        } catch (\Throwable) {
            $languages = [];
        }
        try {
            $currencies = $registry->preferredCurrencyCodes();
        } catch (\Throwable) {
            $currencies = [];
        }

        return new self($languages, $currencies);
    }

    /**
     * @return array{
     *   valid: bool,
     *   normalized: string,
     *   message: string,
     *   matched_code: string,
     *   matched_kind: string
     * }
     */
    public function validate(string $subPath): array
    {
        $normalized = self::normalize($subPath);
        if ($normalized === '') {
            return [
                'valid' => true,
                'normalized' => '',
                'message' => '',
                'matched_code' => '',
                'matched_kind' => '',
            ];
        }

        if (\preg_match(
            '#^/(?:[A-Za-z0-9][A-Za-z0-9_-]{0,62})(?:/(?:[A-Za-z0-9][A-Za-z0-9_-]{0,62})){0,4}$#D',
            $normalized
        ) !== 1) {
            return [
                'valid' => false,
                'normalized' => $normalized,
                'message' => (string)\__('子路径格式无效。'),
                'matched_code' => '',
                'matched_kind' => 'format',
            ];
        }

        foreach (\explode('/', \ltrim($normalized, '/')) as $segment) {
            $segment = \trim((string)$segment);
            if ($segment === '') {
                continue;
            }
            $hit = $this->matchForbiddenSegment($segment);
            if ($hit !== null) {
                return [
                    'valid' => false,
                    'normalized' => $normalized,
                    'message' => $hit['message'],
                    'matched_code' => $hit['code'],
                    'matched_kind' => $hit['kind'],
                ];
            }
        }

        return [
            'valid' => true,
            'normalized' => $normalized,
            'message' => '',
            'matched_code' => '',
            'matched_kind' => '',
        ];
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function assertValid(string $subPath): string
    {
        $result = $this->validate($subPath);
        if (!$result['valid']) {
            throw new \InvalidArgumentException($result['message'] !== ''
                ? $result['message']
                : (string)\__('网站子路径不允许使用语言编码或货币编码。'));
        }

        return $result['normalized'];
    }

    public static function normalize(string $subPath): string
    {
        $subPath = \trim($subPath);
        if ($subPath === '' || $subPath === '/') {
            return '';
        }

        $subPath = '/' . \trim($subPath, '/');

        return $subPath === '/' ? '' : $subPath;
    }

    /**
     * @return list<string>
     */
    public function languageCodes(): array
    {
        return \array_keys($this->languageLookup);
    }

    /**
     * @return list<string>
     */
    public function currencyCodes(): array
    {
        return \array_keys($this->currencyLookup);
    }

    /**
     * @return array{kind: string, code: string, message: string}|null
     */
    private function matchForbiddenSegment(string $segment): ?array
    {
        $lower = \strtolower($segment);
        $upper = \strtoupper($segment);

        if (isset(self::RESERVED_SEGMENTS[$lower])) {
            return [
                'kind' => 'reserved',
                'code' => $segment,
                'message' => (string)\__('子路径首段为保留字，请更换。'),
            ];
        }

        if (isset($this->currencyLookup[$upper])) {
            return [
                'kind' => 'currency',
                'code' => $upper,
                'message' => (string)\__('网站子路径不允许使用货币编码「%{1}」。', [$upper]),
            ];
        }

        if (isset($this->languageLookup[$lower])) {
            return [
                'kind' => 'language',
                'code' => $segment,
                'message' => (string)\__('网站子路径不允许使用语言编码「%{1}」。', [$segment]),
            ];
        }

        // Locale-shaped segments (en_US / zh-Hans-CN) even when not currently installed.
        if (\preg_match('/^[a-z]{2}(?:[_-][a-z0-9]{2,8}){1,2}$/D', $lower) === 1) {
            return [
                'kind' => 'language',
                'code' => $segment,
                'message' => (string)\__('网站子路径不允许使用语言编码「%{1}」。', [$segment]),
            ];
        }

        return null;
    }

    /**
     * @param list<string> $codes
     * @return array<string, true>
     */
    private static function buildLookup(array $codes, bool $upper): array
    {
        $lookup = [];
        foreach ($codes as $code) {
            $normalized = \trim((string)$code);
            if ($normalized === '') {
                continue;
            }
            $key = $upper ? \strtoupper($normalized) : \strtolower($normalized);
            $lookup[$key] = true;
            // Also index underscore/hyphen variants for language codes.
            if (!$upper) {
                $alt = \str_replace('-', '_', $key);
                $lookup[$alt] = true;
                $alt = \str_replace('_', '-', $key);
                $lookup[$alt] = true;
            }
        }

        return $lookup;
    }
}
