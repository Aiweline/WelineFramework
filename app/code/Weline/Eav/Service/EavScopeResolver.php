<?php

declare(strict_types=1);

namespace Weline\Eav\Service;

use Weline\Eav\Api\Scope\EavScopeValue;
use Weline\Framework\Runtime\ScopeContext;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * EAV typed scope 解析器（P1B-005）。
 *
 * 固定四层 fallback：channel → store → website → global。
 * 每层先目标 locale，再空 locale（默认）。
 * `cleared` 立即终止向上与 locale 回退。
 */
class EavScopeResolver
{
    public const KEY_CLEARED = 'cleared';
    public const KEY_VALUE = 'value';

    /**
     * @return list<string> 例如 ['site.shop.app', 'site.shop', 'site', '']（'' = global）
     */
    public function chain(?string $scope = null): array
    {
        $scope = ScopeContext::normalizeScope($scope ?? ScopeContext::getScope());
        if ($scope === '') {
            return [''];
        }
        [$website, $store, $channel] = explode('.', $scope) + ['default', 'default', 'default'];

        return [
            $website . '.' . $store . '.' . $channel,
            $website . '.' . $store,
            $website,
            '',
        ];
    }

    /**
     * @return list<string>
     */
    public function chainFromIdentity(ScopeIdentity $identity): array
    {
        return match ($identity->scopeKind) {
            ScopeIdentity::KIND_GLOBAL => [''],
            ScopeIdentity::KIND_WEBSITE => [
                (string)$identity->websiteCode,
                '',
            ],
            ScopeIdentity::KIND_STORE => [
                $identity->websiteCode . '.' . $identity->storeCode,
                (string)$identity->websiteCode,
                '',
            ],
            ScopeIdentity::KIND_CHANNEL => [
                $identity->websiteCode . '.' . $identity->storeCode . '.' . $identity->channelCode,
                $identity->websiteCode . '.' . $identity->storeCode,
                (string)$identity->websiteCode,
                '',
            ],
            default => [''],
        };
    }

    /**
     * @param array<string, array{value?: mixed, cleared?: bool}> $scopeRecords
     *        键为 `scope` 或 `scope\0locale`（无 locale 时等同空 locale）
     */
    public function resolve(array $scopeRecords, ?string $scope = null, string $locale = ''): EavScopeValue
    {
        return $this->resolveLayers($scopeRecords, $this->chain($scope), $locale);
    }

    /**
     * @param array<string, array{value?: mixed, cleared?: bool}> $scopeRecords
     */
    public function resolveForIdentity(
        array $scopeRecords,
        ScopeIdentity $identity,
        string $locale = '',
    ): EavScopeValue {
        return $this->resolveLayers($scopeRecords, $this->chainFromIdentity($identity), $locale);
    }

    public static function recordKey(string $scopeLayer, string $locale = ''): string
    {
        $locale = \trim($locale);

        return $locale === '' ? $scopeLayer : $scopeLayer . "\0" . $locale;
    }

    /**
     * @param array<string, array{value?: mixed, cleared?: bool}> $scopeRecords
     * @param list<string> $layers
     */
    private function resolveLayers(array $scopeRecords, array $layers, string $locale): EavScopeValue
    {
        $locale = \trim($locale);
        $localeOrder = $locale === '' ? [''] : [$locale, ''];

        foreach ($layers as $layer) {
            foreach ($localeOrder as $loc) {
                $key = self::recordKey($layer, $loc);
                if (!\array_key_exists($key, $scopeRecords) && $loc === '') {
                    // 兼容旧调用方只写 scope 键
                    if (!\array_key_exists($layer, $scopeRecords)) {
                        continue;
                    }
                    $key = $layer;
                } elseif (!\array_key_exists($key, $scopeRecords)) {
                    continue;
                }
                $record = $scopeRecords[$key];
                if (!empty($record[self::KEY_CLEARED])) {
                    return EavScopeValue::cleared($layer);
                }
                if (\array_key_exists(self::KEY_VALUE, $record) && $record[self::KEY_VALUE] !== null) {
                    return EavScopeValue::explicit($record[self::KEY_VALUE], $layer);
                }
            }
        }

        return EavScopeValue::unresolved();
    }
}
