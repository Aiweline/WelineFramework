<?php

declare(strict_types=1);

namespace Weline\Affiliate\Service;

use Weline\Framework\Runtime\ScopeContext;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Storefront affiliate program rules.
 *
 * Reads Weline_Affiliate/frontend SystemConfig at the current
 * website.store.channel scope. SystemConfig's fallback chain provides the
 * website -> store -> channel cascade (a lower scope only wins when it has a
 * saved value).
 */
final class AffiliateRuleService
{
    public const MODULE = 'Weline_Affiliate';
    public const AREA = ConfigReader::area_FRONTEND;

    public const KEY_TITLE = 'rule_title';
    public const KEY_SUMMARY = 'rule_summary';
    public const KEY_COMMISSION_RATE = 'default_commission_rate';
    public const KEY_LEVEL_NAME = 'level_name';

    public const DEFAULT_COMMISSION_RATE = 0.10;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $testingByScope = null;

    public function __construct(private readonly ?ConfigReader $reader = null)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $byScope
     */
    public static function forTesting(array $byScope): self
    {
        $service = new self();
        $normalized = [];
        foreach ($byScope as $scope => $values) {
            $normalized[ScopeContext::normalizeScope((string)$scope)] = is_array($values) ? $values : [];
        }
        $service->testingByScope = $normalized;

        return $service;
    }

    /**
     * @return array{
     *     title:string,
     *     summary:string,
     *     lines:list<string>,
     *     commission_rate:float,
     *     commission_rate_label:string,
     *     level_name:string,
     *     scope:string
     * }
     */
    public function resolve(?string $scope = null): array
    {
        $scope = ScopeContext::normalizeScope($scope === null ? ScopeContext::getScope() : $scope);
        $values = $this->testingByScope !== null
            ? $this->resolveTestingValues($scope)
            : $this->readValues($scope);

        return $this->build($values, $scope);
    }

    /**
     * @return array{
     *     title:string,
     *     summary:string,
     *     lines:list<string>,
     *     commission_rate:float,
     *     commission_rate_label:string,
     *     level_name:string,
     *     scope:string
     * }
     */
    public static function defaults(?string $scope = null): array
    {
        return (new self())->build([], ScopeContext::normalizeScope($scope ?? ''));
    }

    /** @return list<string> */
    public static function defaultSummaryLines(): array
    {
        return [
            (string) \__('分享商品给朋友，成交后获得佣金。'),
            (string) \__('登录后可查看专属推广链接与佣金明细。'),
        ];
    }

    /** @return list<string> */
    public static function splitLines(string $summary): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $summary) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            $line = trim((string)(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line) ?? $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    public static function normalizeRate(mixed $value): float
    {
        if (is_string($value)) {
            $value = trim(str_replace('%', '', $value));
        }
        if (!is_numeric($value)) {
            return self::DEFAULT_COMMISSION_RATE;
        }

        $rate = (float)$value;
        if ($rate > 1.0) {
            $rate /= 100.0;
        }

        return round(max(0.0, min(1.0, $rate)), 4);
    }

    public static function formatRate(float $rate): string
    {
        $percent = round(max(0.0, min(1.0, $rate)) * 100, 2);
        $text = rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');

        return ($text !== '' ? $text : '0') . '%';
    }

    /**
     * @param array<string, mixed> $values
     * @return array{
     *     title:string,
     *     summary:string,
     *     lines:list<string>,
     *     commission_rate:float,
     *     commission_rate_label:string,
     *     level_name:string,
     *     scope:string
     * }
     */
    private function build(array $values, string $scope): array
    {
        $title = trim((string)($values[self::KEY_TITLE] ?? ''));
        if ($title === '') {
            $title = (string) \__('加入分销计划');
        }

        $summary = trim((string)($values[self::KEY_SUMMARY] ?? ''));
        if ($summary === '') {
            $summary = implode("\n", self::defaultSummaryLines());
        }

        $rate = self::normalizeRate($values[self::KEY_COMMISSION_RATE] ?? self::DEFAULT_COMMISSION_RATE);

        $levelName = trim((string)($values[self::KEY_LEVEL_NAME] ?? ''));
        if ($levelName === '') {
            $levelName = (string) \__('分销员');
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'lines' => self::splitLines($summary),
            'commission_rate' => $rate,
            'commission_rate_label' => self::formatRate($rate),
            'level_name' => $levelName,
            'scope' => $scope,
        ];
    }

    /** @return array<string, mixed> */
    private function readValues(string $scope): array
    {
        try {
            $reader = $this->reader ?? new ConfigReader();
            $map = $reader->getConfigMapByModule(
                self::MODULE,
                self::AREA,
                $scope,
                ConfigReader::LOCALE_DEFAULT,
            );

            return is_array($map) ? $map : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function resolveTestingValues(string $scope): array
    {
        $values = [];
        foreach (array_reverse($this->fallbackScopes($scope)) as $candidate) {
            $scopeValues = $this->testingByScope[$candidate] ?? null;
            if (is_array($scopeValues)) {
                $values = array_replace($values, $scopeValues);
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function fallbackScopes(string $scope): array
    {
        [$website, $store, $channel] = explode('.', $scope) + ['default', 'default', 'default'];

        return array_values(array_unique([
            $scope,
            $website . '.' . $store . '.default',
            $website . '.default.default',
            ConfigReader::SCOPE_GLOBAL,
        ]));
    }
}
