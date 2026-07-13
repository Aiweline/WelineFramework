<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\State;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\WelineTheme;

class ThemeMetaIdentityService
{
    public function normalizeLayoutOption(string $layoutOption): string
    {
        $layoutOption = trim(str_replace('\\', '/', $layoutOption));
        $layoutOption = trim($layoutOption, '/ ');
        return $layoutOption !== '' ? str_replace('/', '.', $layoutOption) : 'default';
    }

    public function layoutIdentify(string $layoutType, string $layoutOption = 'default'): string
    {
        $layoutType = trim($layoutType) ?: 'homepage';
        return 'layouts.' . $layoutType . '.' . $this->normalizeLayoutOption($layoutOption);
    }

    public function targetIdentify(
        string $area,
        string $targetType,
        int $targetId,
        string $layoutType,
        string $layoutOption = 'default'
    ): string {
        $area = $area === PreviewContextService::AREA_BACKEND ? PreviewContextService::AREA_BACKEND : PreviewContextService::AREA_FRONTEND;
        $targetType = strtolower(trim($targetType));
        $layoutType = trim($layoutType) ?: 'homepage';

        if ($targetType === '' || $targetType === ThemeVirtualLayout::TARGET_GLOBAL) {
            return '';
        }
        if ($targetId < 0) {
            return '';
        }

        return 'theme.' . $area
            . '.targets.' . $targetType . '.' . $targetId
            . '.layouts.' . $layoutType . '.' . $this->normalizeLayoutOption($layoutOption);
    }

    /**
     * @param array<string,array<string,mixed>> $definitions
     * @return array<string,mixed>
     */
    public function getParamValuesForDefinitions(
        WelineTheme $theme,
        string $area,
        string $identify,
        array $definitions,
        string $scope = 'default',
        ?string $locale = null
    ): array {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        $values = [];
        foreach ($definitions as $name => $definition) {
            $default = $definition['default'] ?? null;
            $isTranslatable = !empty($definition['i18n']) || !empty($definition['translate']) || !empty($definition['translatable']);
            if ($isTranslatable && $locale !== null && $locale !== '') {
                $values[$name] = ThemeData::getParamTranslation(
                    $identify,
                    (string)$name,
                    $scope,
                    $locale,
                    is_scalar($default) ? (string)$default : null
                );
                continue;
            }

            $values[$name] = ThemeData::get($identify . '.param.' . $name . '.value', $default);
            $values[$name] = ThemeData::normalizeParamValueForDefinition($values[$name], $definition);
        }

        return $values;
    }

    /**
     * @param array<string,array<string,mixed>> $definitions
     * @return array<string,mixed>
     */
    public function mergeTargetOverrides(
        WelineTheme $theme,
        string $area,
        array $baseValues,
        string $targetIdentify,
        array $definitions,
        string $scope = 'default',
        ?string $locale = null
    ): array {
        if ($targetIdentify === '' || empty($definitions)) {
            return $baseValues;
        }

        $overrides = $this->getStoredParamValuesForDefinitions($theme, $area, $targetIdentify, $definitions, $scope, $locale);
        foreach ($overrides as $name => $value) {
            if ($value === null) {
                continue;
            }
            $baseValues[$name] = $value;
        }

        return $baseValues;
    }

    /**
     * @param array<string,array<string,mixed>> $definitions
     * @return array<string,mixed>
     */
    public function getStoredParamValuesForDefinitions(
        WelineTheme $theme,
        string $area,
        string $identify,
        array $definitions,
        string $scope = 'default',
        ?string $locale = null
    ): array {
        $identify = $this->normalizeFullIdentify($identify, $area);
        [$namespace] = $this->resolveNamespaceAndConfigKey($identify);
        $effectiveScope = $this->resolveEffectiveScope($scope, $area, (int)$theme->getId());
        $effectiveLocale = $this->resolveLocale($locale);
        $storedConfigRows = $this->loadStoredConfigRows(
            (int)$theme->getId(),
            $namespace,
            $identify,
            $effectiveScope,
            $effectiveLocale
        );
        $values = [];

        foreach ($definitions as $name => $definition) {
            $isTranslatable = !empty($definition['i18n']) || !empty($definition['translate']) || !empty($definition['translatable']);
            if ($isTranslatable) {
                $translated = $this->getStoredParamTranslation($identify, (string)$name, $effectiveScope, $effectiveLocale);
                if ($translated !== null) {
                    $values[$name] = ThemeData::normalizeParamValueForDefinition($translated, $definition);
                }
                continue;
            }

            [, $configKey] = $this->resolveNamespaceAndConfigKey($identify, 'param.' . $name . '.value');
            if (!array_key_exists($configKey, $storedConfigRows)) {
                continue;
            }

            $values[$name] = ThemeData::normalizeParamValueForDefinition($storedConfigRows[$configKey], $definition);
        }

        return $values;
    }

    /**
     * @return array<string,string>
     */
    private function loadStoredConfigRows(
        int $themeId,
        string $namespace,
        string $identify,
        string $scope,
        string $locale
    ): array {
        $records = $this->metaConfigRepository()->search(new MetaConfigSearch(
            namespace: $namespace,
            scope: $scope,
            allLocales: true,
            identifyId: (string)$themeId,
            metaIdentify: $identify,
        ));
        $localeOrder = array_values(array_unique([$locale, 'zh_Hans_CN', null], SORT_REGULAR));
        $values = [];
        $ranks = [];
        foreach ($records as $record) {
            $rank = array_search($record->locale, $localeOrder, true);
            if ($rank === false || (isset($ranks[$record->configKey]) && $ranks[$record->configKey] <= $rank)) {
                continue;
            }
            $ranks[$record->configKey] = $rank;
            $values[$record->configKey] = $record->value;
        }

        return $values;
    }

    private function getStoredParamTranslation(string $identify, string $paramName, string $scope, string $locale): ?string
    {
        $metaKey = $identify . '.param.' . $paramName . '.value';
        $translationKey = '@meta::' . $metaKey;
        if ($scope !== 'default') {
            $translationKey .= '|scope:' . $scope;
        }

        $repository = $this->dictionaryRepository();
        $entry = $repository->getEntry($translationKey, $locale);
        if ($entry !== null) {
            return $entry->translation;
        }

        if ($scope === 'default') {
            return null;
        }

        return $repository->getEntry('@meta::' . $metaKey, $locale)?->translation;
    }

    private function dictionaryRepository(): DictionaryRepositoryInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(DictionaryRepositoryInterface::class);
        if (!$provider instanceof DictionaryRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n dictionary repository provider is unavailable.');
        }
        return $provider;
    }

    private function metaConfigRepository(): MetaConfigRepositoryInterface
    {
        $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(MetaConfigRepositoryInterface::class);
        if (!$provider instanceof MetaConfigRepositoryInterface) {
            throw new \RuntimeException('Weline_Meta config repository provider is unavailable.');
        }

        return $provider;
    }

    private function resolveLocale(?string $locale): string
    {
        $locale = trim((string)$locale);
        if ($locale !== '') {
            return $locale;
        }

        $stateLocale = trim((string)State::getLangLocal());
        if ($stateLocale !== '') {
            return $stateLocale;
        }

        $stateLang = trim((string)State::getLang());
        return $stateLang !== '' ? $stateLang : 'zh_Hans_CN';
    }

    public function setParamValue(
        WelineTheme $theme,
        string $area,
        string $identify,
        string $paramName,
        mixed $value,
        array $definition,
        string $scope = 'default',
        ?string $locale = null
    ): void {
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        $isTranslatable = !empty($definition['i18n']) || !empty($definition['translate']) || !empty($definition['translatable']);
        $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$value;

        if ($isTranslatable && $locale !== null && $locale !== '') {
            ThemeData::setParamTranslation($identify, $paramName, $value, $scope, $locale);
            return;
        }

        $normalizedIdentify = $this->normalizeFullIdentify($identify, $area);
        [$namespace, $configKey] = $this->resolveNamespaceAndConfigKey($normalizedIdentify, 'param.' . $paramName . '.value');
        $effectiveScope = $this->resolveEffectiveScope($scope, $area, (int)$theme->getId());

        $this->metaConfigRepository()->upsert(new MetaConfigWrite(
            identity: new MetaConfigIdentity(
                namespace: $namespace,
                configKey: $configKey,
                scope: $effectiveScope,
                locale: $locale,
                identifyId: (string)$theme->getId(),
                metaIdentify: $normalizedIdentify,
            ),
            value: $value,
        ));
        ThemeData::clearCache();
    }

    private function normalizeFullIdentify(string $identify, string $area): string
    {
        $identify = trim($identify);
        if (str_starts_with($identify, 'theme.')) {
            return $identify;
        }
        if (preg_match('/^(frontend|backend)\./', $identify)) {
            return 'theme.' . $identify;
        }

        $area = $area === PreviewContextService::AREA_BACKEND ? PreviewContextService::AREA_BACKEND : PreviewContextService::AREA_FRONTEND;
        return 'theme.' . $area . '.' . $identify;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveNamespaceAndConfigKey(string $identify, string $extraPath = ''): array
    {
        $segments = explode('.', $identify);
        if (count($segments) < 3) {
            return ['', ''];
        }

        $namespace = $segments[0] . '.' . ($segments[1] ?? PreviewContextService::AREA_FRONTEND);
        $configKey = implode('.', array_slice($segments, 2));
        if ($extraPath !== '') {
            $configKey .= '.' . ltrim($extraPath, '.');
        }

        return [$namespace, $configKey];
    }

    private function resolveEffectiveScope(string $scope, string $area, int $themeId): string
    {
        $scope = trim($scope) !== '' ? trim($scope) : 'default';
        try {
            /** @var PreviewThemeScopeService $scopeService */
            $scopeService = ObjectManager::getInstance(PreviewThemeScopeService::class);
            return $scopeService->resolveEffectiveScope($themeId, $area, $scope);
        } catch (\Throwable) {
            return $scope;
        }
    }
}
