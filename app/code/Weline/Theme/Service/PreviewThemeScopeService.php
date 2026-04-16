<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Session\Session;
use Weline\I18n\Model\Locale\Dictionary;
use Weline\Meta\Model\MetaConfig;
use Weline\Theme\Service\PreviewTokenService;

final class PreviewThemeScopeService
{
    public const PREFIX = '__preview__';
    private const INIT_SESSION_KEY = 'theme_preview_scope_init';

    public function __construct(
        private readonly Request $request,
        private readonly Session $session,
        private readonly PreviewRequestInspector $previewRequestInspector,
        private readonly MetaConfig $metaConfig,
        private readonly Dictionary $dictionary,
        private readonly PreviewTokenService $previewTokenService,
    ) {
    }

    public function shouldUsePreviewScope(?string $path = null): bool
    {
        if ($this->isThemeConfigEditorPath($path)) {
            return true;
        }

        return $this->previewRequestInspector->shouldUseStoredPreviewContext();
    }

    public function isPreviewScope(string $scope): bool
    {
        return \str_starts_with(\trim($scope), self::PREFIX);
    }

    public function filterPreviewScopes(array $scopes): array
    {
        return \array_values(\array_filter(
            \array_map(static fn(mixed $scope): string => \trim((string)$scope), $scopes),
            fn(string $scope): bool => $scope !== '' && !$this->isPreviewScope($scope)
        ));
    }

    public function resolveEffectiveScope(int $themeId, string $area, string $requestedScope = 'default'): string
    {
        $requestedScope = \trim($requestedScope) !== '' ? \trim($requestedScope) : 'default';
        if ($themeId <= 0 || $this->isPreviewScope($requestedScope) || !$this->shouldUsePreviewScope()) {
            return $requestedScope;
        }

        $area = $this->normalizeArea($area);
        $previewScope = $this->buildPreviewScope($themeId, $requestedScope);
        $this->ensureScopeInitialized($themeId, $area, $requestedScope, $previewScope);

        return $previewScope;
    }

    /**
     * Publish the isolated preview data bucket back to the requested formal scope.
     *
     * @return array{published_configs:int,published_translations:int,discarded_preview_configs:int,discarded_preview_translations:int,preview_scope:string}
     */
    public function publishPreviewScope(int $themeId, string $area, string $baseScope = 'default'): array
    {
        $area = $this->normalizeArea($area);
        $baseScope = \trim($baseScope) !== '' ? \trim($baseScope) : 'default';
        $previewScope = $this->buildPreviewScope($themeId, $baseScope);

        $previewRows = $this->listConfigRows($themeId, $area, $previewScope);
        if (empty($previewRows) && !$this->isScopeInitialized($previewScope, $area, $baseScope)) {
            return [
                'published_configs' => 0,
                'published_translations' => 0,
                'discarded_preview_configs' => 0,
                'discarded_preview_translations' => 0,
                'preview_scope' => $previewScope,
            ];
        }

        $baseRows = $this->listConfigRows($themeId, $area, $baseScope);
        $previewMap = $this->mapConfigRows($previewRows);
        $publishedConfigs = 0;

        foreach ($baseRows as $baseRow) {
            if (!isset($previewMap[$this->configRowKey($baseRow)])) {
                $baseRow->delete()->fetch();
            }
        }

        foreach ($previewRows as $previewRow) {
            $locale = $previewRow->getData(MetaConfig::schema_fields_LOCALE);
            $locale = $locale === null || $locale === '' ? null : (string)$locale;

            /** @var MetaConfig $target */
            $target = clone $this->metaConfig;
            $target->clearData()->clearQuery()->setConfig(
                (string)$themeId,
                (string)$previewRow->getData(MetaConfig::schema_fields_NAMESPACE),
                (string)$previewRow->getData(MetaConfig::schema_fields_CONFIG_KEY),
                (string)$previewRow->getData(MetaConfig::schema_fields_CONFIG_VALUE),
                $baseScope,
                $locale,
                ($previewRow->getData(MetaConfig::schema_fields_META_ID) ?: null)
                    ? (int)$previewRow->getData(MetaConfig::schema_fields_META_ID)
                    : null,
                ($previewRow->getData(MetaConfig::schema_fields_META_IDENTIFY) ?: null)
                    ? (string)$previewRow->getData(MetaConfig::schema_fields_META_IDENTIFY)
                    : null,
            );
            $publishedConfigs++;
        }

        $publishedTranslations = $this->publishTranslations($area, $baseScope, $previewScope);
        $discard = $this->discardPreviewScope($themeId, $area, $baseScope);

        return [
            'published_configs' => $publishedConfigs,
            'published_translations' => $publishedTranslations,
            'discarded_preview_configs' => $discard['discarded_preview_configs'],
            'discarded_preview_translations' => $discard['discarded_preview_translations'],
            'preview_scope' => $previewScope,
        ];
    }

    /**
     * @return array{discarded_preview_configs:int,discarded_preview_translations:int,preview_scope:string}
     */
    public function discardPreviewScope(int $themeId, string $area, string $baseScope = 'default'): array
    {
        $area = $this->normalizeArea($area);
        $baseScope = \trim($baseScope) !== '' ? \trim($baseScope) : 'default';
        $previewScope = $this->buildPreviewScope($themeId, $baseScope);

        $discardedConfigs = 0;
        foreach ($this->listConfigRows($themeId, $area, $previewScope) as $row) {
            $row->delete()->fetch();
            $discardedConfigs++;
        }

        $discardedTranslations = 0;
        foreach ($this->listTranslationsForScope($area, $previewScope) as $row) {
            $row->delete()->fetch();
            $discardedTranslations++;
        }

        $this->unmarkScopeInitialized($previewScope, $area, $baseScope);

        return [
            'discarded_preview_configs' => $discardedConfigs,
            'discarded_preview_translations' => $discardedTranslations,
            'preview_scope' => $previewScope,
        ];
    }

    public function buildPreviewScope(int $themeId, string $baseScope = 'default'): string
    {
        $baseScope = \trim($baseScope) !== '' ? \trim($baseScope) : 'default';
        $identity = $this->buildPreviewIdentity($themeId, $baseScope);
        $fingerprint = \substr(\hash('sha256', $identity), 0, 12);

        return self::PREFIX . '_t' . $themeId . '_' . $fingerprint;
    }

    private function ensureScopeInitialized(int $themeId, string $area, string $baseScope, string $previewScope): void
    {
        if ($this->isScopeInitialized($previewScope, $area, $baseScope)) {
            return;
        }

        $this->cloneConfigRows($themeId, $area, $baseScope, $previewScope);
        $this->cloneTranslations($area, $baseScope, $previewScope);
        $this->markScopeInitialized($previewScope, $area, $baseScope);
    }

    private function cloneConfigRows(int $themeId, string $area, string $baseScope, string $previewScope): void
    {
        $namespace = 'theme.' . $area;

        /** @var MetaConfig $metaConfig */
        $metaConfig = clone $this->metaConfig;
        $metaConfig->clearData()->clearQuery();

        $items = $metaConfig
            ->where(MetaConfig::schema_fields_IDENTIFY_ID, (string)$themeId)
            ->where(MetaConfig::schema_fields_NAMESPACE, $namespace)
            ->where(MetaConfig::schema_fields_SCOPE, $baseScope)
            ->select()
            ->fetch()
            ->getItems();

        foreach ($items as $item) {
            if (!$item instanceof MetaConfig) {
                continue;
            }

            $locale = $item->getData(MetaConfig::schema_fields_LOCALE);
            $locale = $locale === null || $locale === '' ? null : (string)$locale;

            /** @var MetaConfig $target */
            $target = clone $this->metaConfig;
            $target->clearData()->clearQuery()->setConfig(
                (string)$themeId,
                (string)$item->getData(MetaConfig::schema_fields_NAMESPACE),
                (string)$item->getData(MetaConfig::schema_fields_CONFIG_KEY),
                (string)$item->getData(MetaConfig::schema_fields_CONFIG_VALUE),
                $previewScope,
                $locale,
                ($item->getData(MetaConfig::schema_fields_META_ID) ?: null)
                    ? (int)$item->getData(MetaConfig::schema_fields_META_ID)
                    : null,
                ($item->getData(MetaConfig::schema_fields_META_IDENTIFY) ?: null)
                    ? (string)$item->getData(MetaConfig::schema_fields_META_IDENTIFY)
                    : null,
            );
        }
    }

    private function cloneTranslations(string $area, string $baseScope, string $previewScope): void
    {
        $prefix = '@meta::theme.' . $area . '.';

        /** @var Dictionary $dictionary */
        $dictionary = clone $this->dictionary;
        $dictionary->clearData()->clearQuery();

        $items = $dictionary
            ->where(Dictionary::schema_fields_WORD, $prefix . '%', 'LIKE')
            ->select()
            ->fetch()
            ->getItems();

        foreach ($items as $item) {
            if (!$item instanceof Dictionary) {
                continue;
            }

            $word = (string)$item->getData(Dictionary::schema_fields_WORD);
            if (!$this->matchesBaseScopeWord($word, $prefix, $baseScope)) {
                continue;
            }

            $previewWord = $this->bindWordToScope($word, $previewScope);
            $localeCode = (string)$item->getData(Dictionary::schema_fields_LOCALE_CODE);
            $translate = (string)$item->getData(Dictionary::schema_fields_TRANSLATE);

            /** @var Dictionary $target */
            $target = clone $this->dictionary;
            $target->clearData()->clearQuery();
            $target->setData(Dictionary::schema_fields_MD5, Dictionary::generateMd5($previewWord, $localeCode));
            $target->setData(Dictionary::schema_fields_WORD, $previewWord);
            $target->setData(Dictionary::schema_fields_LOCALE_CODE, $localeCode);
            $target->setData(Dictionary::schema_fields_TRANSLATE, $translate);
            $target->save();
        }
    }

    private function matchesBaseScopeWord(string $word, string $prefix, string $baseScope): bool
    {
        if (!\str_starts_with($word, $prefix)) {
            return false;
        }

        $scopeMarker = '|scope:';
        $scopePos = \strpos($word, $scopeMarker);

        if ($baseScope === 'default') {
            return $scopePos === false;
        }

        return $scopePos !== false && \substr($word, $scopePos + \strlen($scopeMarker)) === $baseScope;
    }

    private function bindWordToScope(string $word, string $scope): string
    {
        $scopeMarker = '|scope:';
        $scopePos = \strpos($word, $scopeMarker);
        if ($scopePos !== false) {
            $word = \substr($word, 0, $scopePos);
        }

        if ($scope === 'default') {
            return $word;
        }

        return $word . $scopeMarker . $scope;
    }

    private function isScopeInitialized(string $previewScope, string $area, string $baseScope): bool
    {
        $initMap = $this->getInitMap();
        return !empty($initMap[$previewScope][$area][$baseScope]);
    }

    private function markScopeInitialized(string $previewScope, string $area, string $baseScope): void
    {
        $initMap = $this->getInitMap();
        $initMap[$previewScope][$area][$baseScope] = 1;
        $this->session->setData(self::INIT_SESSION_KEY, $initMap);
    }

    private function unmarkScopeInitialized(string $previewScope, string $area, string $baseScope): void
    {
        $initMap = $this->getInitMap();
        unset($initMap[$previewScope][$area][$baseScope]);
        if (empty($initMap[$previewScope][$area])) {
            unset($initMap[$previewScope][$area]);
        }
        if (empty($initMap[$previewScope])) {
            unset($initMap[$previewScope]);
        }
        $this->session->setData(self::INIT_SESSION_KEY, $initMap);
    }

    private function getInitMap(): array
    {
        $initMap = $this->session->getData(self::INIT_SESSION_KEY);
        return \is_array($initMap) ? $initMap : [];
    }

    private function ensureSessionId(): string
    {
        $sessionId = $this->session->getId();
        if ($sessionId !== '') {
            return $sessionId;
        }

        $this->session->start();
        return $this->session->getId();
    }

    private function buildPreviewIdentity(int $themeId, string $baseScope): string
    {
        $previewToken = \trim((string)($this->previewTokenService->getCurrentToken() ?? ''));
        $versionId = (int)$this->request->getParam('version_id', 0);
        $status = \trim((string)$this->request->getParam('status', ''));

        if ($previewToken !== '') {
            return 'token|' . $previewToken . '|' . $themeId . '|' . $baseScope;
        }

        if ($versionId > 0) {
            return 'version|' . $versionId . '|' . $status . '|' . $themeId . '|' . $baseScope;
        }

        $sessionId = $this->ensureSessionId();
        return 'session|' . $sessionId . '|' . $themeId . '|' . $baseScope;
    }

    /**
     * @return MetaConfig[]
     */
    private function listConfigRows(int $themeId, string $area, string $scope): array
    {
        $namespace = 'theme.' . $this->normalizeArea($area);
        /** @var MetaConfig $metaConfig */
        $metaConfig = clone $this->metaConfig;
        $metaConfig->clearData()->clearQuery();

        $items = $metaConfig
            ->where(MetaConfig::schema_fields_IDENTIFY_ID, (string)$themeId)
            ->where(MetaConfig::schema_fields_NAMESPACE, $namespace)
            ->where(MetaConfig::schema_fields_SCOPE, $scope)
            ->select()
            ->fetch()
            ->getItems();

        return \array_values(\array_filter($items, static fn(mixed $item): bool => $item instanceof MetaConfig));
    }

    /**
     * @param MetaConfig[] $rows
     * @return array<string, MetaConfig>
     */
    private function mapConfigRows(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$this->configRowKey($row)] = $row;
        }

        return $map;
    }

    private function configRowKey(MetaConfig $row): string
    {
        return \implode('|', [
            (string)$row->getData(MetaConfig::schema_fields_NAMESPACE),
            (string)$row->getData(MetaConfig::schema_fields_CONFIG_KEY),
            (string)$row->getData(MetaConfig::schema_fields_LOCALE),
            (string)$row->getData(MetaConfig::schema_fields_META_ID),
            (string)$row->getData(MetaConfig::schema_fields_META_IDENTIFY),
        ]);
    }

    private function publishTranslations(string $area, string $baseScope, string $previewScope): int
    {
        $previewRows = $this->listTranslationsForScope($area, $previewScope);
        $baseRows = $this->listTranslationsForScope($area, $baseScope);
        $previewBaseWords = [];
        $published = 0;

        foreach ($previewRows as $previewRow) {
            $previewWord = (string)$previewRow->getData(Dictionary::schema_fields_WORD);
            $baseWord = $this->bindWordToScope($previewWord, $baseScope);
            $localeCode = (string)$previewRow->getData(Dictionary::schema_fields_LOCALE_CODE);
            $translate = (string)$previewRow->getData(Dictionary::schema_fields_TRANSLATE);
            $previewBaseWords[$baseWord . '|' . $localeCode] = true;
            $this->saveDictionaryWord($baseWord, $localeCode, $translate);
            $published++;
        }

        foreach ($baseRows as $baseRow) {
            $key = (string)$baseRow->getData(Dictionary::schema_fields_WORD)
                . '|' . (string)$baseRow->getData(Dictionary::schema_fields_LOCALE_CODE);
            if (!isset($previewBaseWords[$key])) {
                $baseRow->delete()->fetch();
            }
        }

        return $published;
    }

    /**
     * @return Dictionary[]
     */
    private function listTranslationsForScope(string $area, string $scope): array
    {
        $prefix = '@meta::theme.' . $this->normalizeArea($area) . '.';

        /** @var Dictionary $dictionary */
        $dictionary = clone $this->dictionary;
        $dictionary->clearData()->clearQuery();

        $items = $dictionary
            ->where(Dictionary::schema_fields_WORD, $prefix . '%', 'LIKE')
            ->select()
            ->fetch()
            ->getItems();

        $filtered = [];
        foreach ($items as $item) {
            if (!$item instanceof Dictionary) {
                continue;
            }
            $word = (string)$item->getData(Dictionary::schema_fields_WORD);
            if ($this->matchesBaseScopeWord($word, $prefix, $scope)) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    private function saveDictionaryWord(string $word, string $localeCode, string $translate): void
    {
        /** @var Dictionary $target */
        $target = clone $this->dictionary;
        $target->clearData()->clearQuery();
        $md5 = Dictionary::generateMd5($word, $localeCode);
        $target->load(Dictionary::schema_fields_MD5, $md5);
        $target->setData(Dictionary::schema_fields_MD5, $md5);
        $target->setData(Dictionary::schema_fields_WORD, $word);
        $target->setData(Dictionary::schema_fields_LOCALE_CODE, $localeCode);
        $target->setData(Dictionary::schema_fields_TRANSLATE, $translate);
        $target->save();
    }

    private function normalizeArea(string $area): string
    {
        return \strtolower(\trim($area)) === 'backend' ? 'backend' : 'frontend';
    }

    private function isThemeConfigEditorPath(?string $path = null): bool
    {
        $path = $this->previewRequestInspector->normalizePath($path);
        return \str_starts_with($path, '/theme/backend/config/');
    }
}
