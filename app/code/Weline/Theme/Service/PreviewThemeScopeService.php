<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Session;
use Weline\I18n\Api\Translation\DictionaryEntry;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigRecord;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\MetaConfigRepositoryInterface;

final class PreviewThemeScopeService
{
    public const PREFIX = '__preview__';
    private const INIT_SESSION_KEY = 'theme_preview_scope_init';

    private ?MetaConfigRepositoryInterface $metaConfigRepository;
    private DictionaryRepositoryInterface $dictionary;
    private PreviewTokenService $previewTokenService;

    public function __construct(
        private readonly Request $request,
        private readonly Session $session,
        private readonly PreviewRequestInspector $previewRequestInspector,
        mixed $metaConfig = null,
        ?DictionaryRepositoryInterface $dictionary = null,
        ?PreviewTokenService $previewTokenService = null,
    ) {
        $this->metaConfigRepository = $metaConfig instanceof MetaConfigRepositoryInterface
            ? $metaConfig
            : null;
        $this->dictionary = $dictionary ?? $this->resolveDictionaryRepository();
        $this->previewTokenService = $previewTokenService
            ?? ObjectManager::getInstance(PreviewTokenService::class);
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

        return $this->publishResolvedPreviewScope($themeId, $area, $baseScope, $previewScope);
    }

    /**
     * Publish the session-scoped editor bucket even when the current request carries
     * a version_id/preview token that would otherwise derive a different scope.
     *
     * @return array{published_configs:int,published_translations:int,discarded_preview_configs:int,discarded_preview_translations:int,preview_scope:string}
     */
    public function publishSessionPreviewScope(int $themeId, string $area, string $baseScope = 'default'): array
    {
        $area = $this->normalizeArea($area);
        $baseScope = \trim($baseScope) !== '' ? \trim($baseScope) : 'default';
        $previewScope = $this->buildPreviewScopeFromIdentity(
            'session|' . $this->ensureSessionId() . '|' . $themeId . '|' . $baseScope
        );

        return $this->publishResolvedPreviewScope($themeId, $area, $baseScope, $previewScope);
    }

    /**
     * @return array{published_configs:int,published_translations:int,discarded_preview_configs:int,discarded_preview_translations:int,preview_scope:string}
     */
    private function publishResolvedPreviewScope(int $themeId, string $area, string $baseScope, string $previewScope): array
    {
        if ($themeId <= 0) {
            return $this->emptyPublishResult($previewScope);
        }

        $previewRows = $this->listConfigRows($themeId, $area, $previewScope);
        if (empty($previewRows) && !$this->isScopeInitialized($previewScope, $area, $baseScope)) {
            return $this->emptyPublishResult($previewScope);
        }

        $baseRows = $this->listConfigRows($themeId, $area, $baseScope);
        $previewMap = $this->mapConfigRows($previewRows);
        $publishedConfigs = 0;

        foreach ($baseRows as $baseRow) {
            if (!isset($previewMap[$this->configRowKey($baseRow)])) {
                $this->metaConfigRepository()->delete($this->identityForRecord($baseRow));
            }
        }

        foreach ($previewRows as $previewRow) {
            $this->metaConfigRepository()->upsert(new MetaConfigWrite(
                $this->identityForRecord($previewRow, $baseScope),
                $previewRow->value,
            ));
            $publishedConfigs++;
        }

        $publishedTranslations = $this->publishTranslations($area, $baseScope, $previewScope);
        $discard = $this->discardResolvedPreviewScope($themeId, $area, $baseScope, $previewScope);

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

        return $this->discardResolvedPreviewScope($themeId, $area, $baseScope, $previewScope);
    }

    /**
     * @return array{discarded_preview_configs:int,discarded_preview_translations:int,preview_scope:string}
     */
    private function discardResolvedPreviewScope(int $themeId, string $area, string $baseScope, string $previewScope): array
    {
        $discardedConfigs = 0;
        foreach ($this->listConfigRows($themeId, $area, $previewScope) as $row) {
            if ($this->metaConfigRepository()->delete($this->identityForRecord($row))) {
                $discardedConfigs++;
            }
        }

        $discardedTranslations = 0;
        foreach ($this->listTranslationsForScope($area, $previewScope) as $row) {
            if ($this->dictionary->deleteEntry($row->word, $row->localeCode)) {
                $discardedTranslations++;
            }
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

        return $this->buildPreviewScopeFromIdentity($identity);
    }

    private function buildPreviewScopeFromIdentity(string $identity): string
    {
        $parts = \explode('|', $identity);
        $themeId = 0;
        if (\count($parts) >= 3) {
            $themeId = (int)$parts[\count($parts) - 2];
        }
        $fingerprint = \substr(\hash('sha256', $identity), 0, 12);

        return self::PREFIX . '_t' . $themeId . '_' . $fingerprint;
    }

    /**
     * @return array{published_configs:int,published_translations:int,discarded_preview_configs:int,discarded_preview_translations:int,preview_scope:string}
     */
    private function emptyPublishResult(string $previewScope): array
    {
        return [
            'published_configs' => 0,
            'published_translations' => 0,
            'discarded_preview_configs' => 0,
            'discarded_preview_translations' => 0,
            'preview_scope' => $previewScope,
        ];
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
        foreach ($this->listConfigRows($themeId, $area, $baseScope) as $item) {
            $this->metaConfigRepository()->upsert(new MetaConfigWrite(
                $this->identityForRecord($item, $previewScope),
                $item->value,
            ));
        }
    }

    private function cloneTranslations(string $area, string $baseScope, string $previewScope): void
    {
        $prefix = '@meta::theme.' . $area . '.';

        foreach ($this->dictionary->listByWordPrefix($prefix) as $item) {
            $word = $item->word;
            if (!$this->matchesBaseScopeWord($word, $prefix, $baseScope)) {
                continue;
            }

            $previewWord = $this->bindWordToScope($word, $previewScope);
            $this->dictionary->upsert($previewWord, $item->localeCode, $item->translation);
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

    /** @return list<MetaConfigRecord> */
    private function listConfigRows(int $themeId, string $area, string $scope): array
    {
        $namespace = 'theme.' . $this->normalizeArea($area);
        return $this->metaConfigRepository()->search(new MetaConfigSearch(
            namespace: $namespace,
            scope: $scope,
            allLocales: true,
            identifyId: (string)$themeId,
        ));
    }

    /**
     * @param list<MetaConfigRecord> $rows
     * @return array<string, MetaConfigRecord>
     */
    private function mapConfigRows(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$this->configRowKey($row)] = $row;
        }

        return $map;
    }

    private function configRowKey(MetaConfigRecord $row): string
    {
        return \implode('|', [
            $row->namespace,
            $row->configKey,
            (string)$row->locale,
            (string)$row->metaId,
            (string)$row->metaIdentify,
        ]);
    }

    private function publishTranslations(string $area, string $baseScope, string $previewScope): int
    {
        $previewRows = $this->listTranslationsForScope($area, $previewScope);
        $baseRows = $this->listTranslationsForScope($area, $baseScope);
        $previewBaseWords = [];
        $published = 0;

        foreach ($previewRows as $previewRow) {
            $previewWord = $previewRow->word;
            $baseWord = $this->bindWordToScope($previewWord, $baseScope);
            $localeCode = $previewRow->localeCode;
            $translate = $previewRow->translation;
            $previewBaseWords[$baseWord . '|' . $localeCode] = true;
            $this->saveDictionaryWord($baseWord, $localeCode, $translate);
            $published++;
        }

        foreach ($baseRows as $baseRow) {
            $key = $baseRow->word . '|' . $baseRow->localeCode;
            if (!isset($previewBaseWords[$key])) {
                $this->dictionary->deleteEntry($baseRow->word, $baseRow->localeCode);
            }
        }

        return $published;
    }

    /**
     * @return list<DictionaryEntry>
     */
    private function listTranslationsForScope(string $area, string $scope): array
    {
        $prefix = '@meta::theme.' . $this->normalizeArea($area) . '.';

        $filtered = [];
        foreach ($this->dictionary->listByWordPrefix($prefix) as $item) {
            if ($this->matchesBaseScopeWord($item->word, $prefix, $scope)) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    private function saveDictionaryWord(string $word, string $localeCode, string $translate): void
    {
        $this->dictionary->upsert($word, $localeCode, $translate);
    }

    private function metaConfigRepository(): MetaConfigRepositoryInterface
    {
        if ($this->metaConfigRepository instanceof MetaConfigRepositoryInterface) {
            return $this->metaConfigRepository;
        }

        $repository = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(MetaConfigRepositoryInterface::class);
        if (!$repository instanceof MetaConfigRepositoryInterface) {
            throw new \RuntimeException('Weline_Meta config repository provider is unavailable.');
        }

        return $this->metaConfigRepository = $repository;
    }

    private function resolveDictionaryRepository(): DictionaryRepositoryInterface
    {
        $repository = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(DictionaryRepositoryInterface::class);
        if (!$repository instanceof DictionaryRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n dictionary repository provider is unavailable.');
        }

        return $repository;
    }

    private function identityForRecord(MetaConfigRecord $record, ?string $scope = null): MetaConfigIdentity
    {
        return new MetaConfigIdentity(
            namespace: $record->namespace,
            configKey: $record->configKey,
            scope: $scope ?? $record->scope,
            locale: $record->locale,
            identifyId: $record->identifyId,
            metaId: $record->metaId,
            metaIdentify: $record->metaIdentify,
        );
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
