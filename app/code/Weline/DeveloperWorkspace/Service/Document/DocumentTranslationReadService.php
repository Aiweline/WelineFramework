<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Document;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\DeveloperWorkspace\Model\Document\Catalog\Translation as CatalogTranslation;
use Weline\DeveloperWorkspace\Model\Document\Translation;
use Weline\Framework\Manager\ObjectManager;

class DocumentTranslationReadService
{
    public function __construct(
        private DocumentTranslationConfigService $configService,
        private DocumentSourceService $sourceService,
        private Translation $translationModel,
        private CatalogTranslation $catalogTranslationModel
    ) {
    }

    public function resolveLocale(?string $locale): string
    {
        return $this->configService->normalizeLocale($locale);
    }

    public function getDocumentView(Document $document, string $locale, bool $includeContent = false, bool $enqueueMissing = false): array
    {
        $locale = $this->resolveLocale($locale);
        $config = $this->configService->getConfig();
        $sourceLocale = (string)$config['source_locale'];
        $sourceContent = $includeContent ? $this->sourceService->getDocumentContent($document) : '';
        $sourceHash = $this->sourceService->getDocumentSourceHash($document);
        $sourceView = [
            'id' => $document->getId(),
            'title' => (string)$document->getTitle(),
            'summary' => (string)$document->getData(Document::schema_fields_summary),
            'content' => $sourceContent,
            'category_id' => $document->getCategoryId(),
            'module_name' => $document->getModuleName(),
            'file_name' => $document->getFileName(),
            'locale' => $sourceLocale,
            'source_locale' => $sourceLocale,
            'is_translated' => false,
            'translation_status' => $this->filterVisibleStatus($locale, Translation::STATUS_TRANSLATED),
            'source_hash' => $sourceHash,
        ];

        if ($locale === $sourceLocale) {
            return $sourceView;
        }

        $translation = $this->translationModel->clear()
            ->where(Translation::schema_fields_SOURCE_DOCUMENT_ID, $document->getId())
            ->where(Translation::schema_fields_LOCALE, $locale)
            ->find()
            ->fetch();

        if (!$translation || !$translation->getId()) {
            $fallbackStatus = $this->getMissingTranslationStatus($locale, $document);
            if ($enqueueMissing && $fallbackStatus === Translation::STATUS_MISSING) {
                if ($this->enqueueDocument($document, $locale)) {
                    $fallbackStatus = Translation::STATUS_PENDING;
                }
            }
            return $this->buildFallbackDocumentView($sourceView, $locale, $fallbackStatus, $config);
        }

        $status = (string)$translation->getData(Translation::schema_fields_STATUS);
        $isStale = (string)$translation->getData(Translation::schema_fields_SOURCE_HASH) !== $sourceHash;
        if ($isStale && !((int)$translation->getData(Translation::schema_fields_IS_MANUAL_OVERRIDE))) {
            $status = Translation::STATUS_STALE;
            $translation->setData(Translation::schema_fields_STATUS, Translation::STATUS_STALE)->save();
            if ($enqueueMissing) {
                $this->enqueueDocument($document, $locale);
            }
        }

        if (!in_array($status, [Translation::STATUS_TRANSLATED, Translation::STATUS_STALE], true)) {
            return $this->buildFallbackDocumentView(
                $sourceView,
                $locale,
                $status ?: Translation::STATUS_MISSING,
                $config
            );
        }

        return array_replace($sourceView, [
            'title' => (string)$translation->getData(Translation::schema_fields_TITLE),
            'summary' => (string)$translation->getData(Translation::schema_fields_SUMMARY),
            'content' => $includeContent ? (string)$translation->getData(Translation::schema_fields_CONTENT) : '',
            'locale' => $locale,
            'is_translated' => true,
            'translation_status' => $this->filterVisibleStatus($locale, $status),
            'translated_at' => (int)$translation->getData(Translation::schema_fields_TRANSLATED_AT),
        ]);
    }

    public function localizeCatalogRow(array $row, string $locale, bool $enqueueMissing = false): array
    {
        $locale = $this->resolveLocale($locale);
        $sourceLocale = (string)$this->configService->getConfig()['source_locale'];
        if ($locale === $sourceLocale) {
            $row['locale'] = $sourceLocale;
            $row['translation_status'] = $this->filterVisibleStatus($locale, Translation::STATUS_TRANSLATED);
            return $row;
        }

        $catalogId = (int)($row[Catalog::schema_fields_ID] ?? $row['id'] ?? 0);
        if ($catalogId <= 0) {
            return $row;
        }

        $translation = $this->catalogTranslationModel->clear()
            ->where(CatalogTranslation::schema_fields_CATALOG_ID, $catalogId)
            ->where(CatalogTranslation::schema_fields_LOCALE, $locale)
            ->find()
            ->fetch();

        if (!$translation || !$translation->getId()) {
            $fallbackStatus = $this->getMissingTranslationStatus($locale);
            if ($enqueueMissing && $fallbackStatus === Translation::STATUS_MISSING) {
                $catalog = ObjectManager::make(Catalog::class)->load($catalogId);
                if ($catalog && $catalog->getId()) {
                    $this->enqueueCatalog($catalog, $locale);
                }
            }
            $row['locale'] = $locale;
            $row['translation_status'] = $this->filterVisibleStatus($locale, $fallbackStatus);
            if ($this->useEmptyFallback()) {
                $row[Catalog::schema_fields_NAME] = '';
                $row['name'] = '';
                $row[Catalog::schema_fields_DESCRIPTION] = '';
                $row['description'] = '';
            }
            return $row;
        }

        $status = (string)$translation->getData(CatalogTranslation::schema_fields_STATUS);
        if (in_array($status, [Translation::STATUS_TRANSLATED, Translation::STATUS_STALE], true)) {
            $row[Catalog::schema_fields_NAME] = (string)$translation->getData(CatalogTranslation::schema_fields_NAME);
            $row['name'] = $row[Catalog::schema_fields_NAME];
            $row[Catalog::schema_fields_DESCRIPTION] = (string)$translation->getData(CatalogTranslation::schema_fields_DESCRIPTION);
            $row['description'] = $row[Catalog::schema_fields_DESCRIPTION];
        }
        $row['locale'] = $locale;
        $row['translation_status'] = $this->filterVisibleStatus($locale, $status ?: Translation::STATUS_MISSING);

        return $row;
    }

    private function buildFallbackDocumentView(array $sourceView, string $locale, string $status, array $config): array
    {
        $view = array_replace($sourceView, [
            'locale' => $locale,
            'translation_status' => $this->filterVisibleStatus($locale, $status),
        ]);

        if (($config['fallback_policy'] ?? 'source') !== 'empty') {
            return $view;
        }

        $view['title'] = '';
        $view['summary'] = '';
        $view['content'] = '';

        return $view;
    }

    private function useEmptyFallback(): bool
    {
        $config = $this->configService->getConfig();
        return ($config['fallback_policy'] ?? 'source') === 'empty';
    }

    private function filterVisibleStatus(string $locale, string $status): string
    {
        $config = $this->configService->getConfig();
        if (empty($config['show_translation_status']) || $this->configService->isSourceLocale($locale)) {
            return '';
        }

        return $status;
    }

    private function enqueueDocument(Document $document, string $locale): bool
    {
        try {
            return ObjectManager::getInstance(DocumentTranslationTaskService::class)->enqueueDocument($document, $locale);
        } catch (\Throwable) {
        }

        return false;
    }

    private function enqueueCatalog(Catalog $catalog, string $locale): void
    {
        try {
            ObjectManager::getInstance(DocumentTranslationTaskService::class)->enqueueCatalog($catalog, $locale);
        } catch (\Throwable) {
        }
    }

    private function getMissingTranslationStatus(string $locale, ?Document $document = null): string
    {
        if (!$this->configService->canAutoTranslateLocale($locale)) {
            return Translation::STATUS_DISABLED;
        }

        if ($document) {
            $isApiDocument = str_starts_with((string)$document->getModuleName(), 'API_');
            $scope = $isApiDocument ? 'api_documents' : 'documents';
            if (!$this->configService->isScopeEnabled($scope)) {
                return Translation::STATUS_DISABLED;
            }
        }

        return Translation::STATUS_MISSING;
    }
}
