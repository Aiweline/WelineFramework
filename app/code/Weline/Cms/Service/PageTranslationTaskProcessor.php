<?php

declare(strict_types=1);

namespace Weline\Cms\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\TranslationService\Api\TranslationServiceInterface;

final class PageTranslationTaskProcessor
{
    public function __construct(
        private readonly PageService $pageService,
        private readonly PageLocaleService $pageLocaleService,
        private readonly PageTranslationPolicy $policy,
        private ?TranslationServiceInterface $translationService = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function freezeInput(int $pageId, string $requestId, ?int $storeId = null): array
    {
        $requestId = trim($requestId);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $requestId)) {
            throw new \InvalidArgumentException((string)__('翻译请求标识无效。'));
        }
        $page = $this->pageService->getPageModel($pageId);
        if ($page === null) {
            throw new \InvalidArgumentException((string)__('CMS 页面不存在或已删除。'));
        }

        $payload = $this->pageLocaleService->buildEditorPayload($page, '', $storeId);
        $supported = array_values(array_map('strval', (array)($payload['supported_locales'] ?? [])));
        $titles = is_array($payload['titles'] ?? null) ? $payload['titles'] : [];
        $sourceLocale = trim((string)($payload['source_locale'] ?? ''));
        $sourceTitle = trim((string)($titles[$sourceLocale] ?? ''));
        $targets = $this->policy->missingTargets($supported, $titles, $sourceLocale);
        if ($targets === []) {
            throw new \InvalidArgumentException((string)__('当前页面没有需要补全的语言标题。'));
        }

        return [
            'request_id' => $requestId,
            'page_id' => $page->getPageId(),
            'website_id' => $page->getWebsiteId(),
            'store_id' => (int)($payload['store_id'] ?? 0),
            'store_code' => (string)($payload['store_code'] ?? ''),
            'source_locale' => $sourceLocale,
            'source_title' => $sourceTitle,
            'source_hash' => hash('sha256', $sourceTitle),
            'supported_locales' => $supported,
            'target_locales' => $targets,
        ];
    }

    /** @param array<string,mixed> $input */
    public function translateTarget(array $input, string $targetLocale, string $idempotencyKey = ''): string
    {
        $targetLocale = trim($targetLocale);
        $targets = array_values(array_map('strval', (array)($input['target_locales'] ?? [])));
        if ($targetLocale === '' || !in_array($targetLocale, $targets, true)) {
            throw new \InvalidArgumentException((string)__('翻译目标语言无效。'));
        }
        $sourceTitle = trim((string)($input['source_title'] ?? ''));
        $sourceLocale = trim((string)($input['source_locale'] ?? ''));
        if ($sourceTitle === '' || $sourceLocale === '') {
            throw new \InvalidArgumentException((string)__('翻译源标题或源语言无效。'));
        }

        $service = $this->translationService
            ??= ObjectManager::getInstance(TranslationServiceInterface::class);
        $options = [
            'module_name' => 'Weline_Cms',
            'operation' => 'cms_page_title',
            'page_id' => (int)($input['page_id'] ?? 0),
            'store_id' => (int)($input['store_id'] ?? 0),
        ];
        if ($idempotencyKey !== '') {
            $options['idempotency_key'] = $idempotencyKey;
        }
        $translated = $service->batchTranslate(
            [$sourceTitle],
            $targetLocale,
            $sourceLocale,
            null,
            $options,
        );
        $title = trim((string)($translated[0] ?? ''));
        if ($title === '') {
            throw new \RuntimeException((string)__('翻译服务没有返回有效标题。'));
        }

        return $title;
    }

    /**
     * @param array<string,mixed> $input
     * @return 'saved'|'already_filled'|'source_changed'|'unsupported_locale'|'website_changed'|'page_missing'
     */
    public function persistTarget(array $input, string $targetLocale, string $translatedTitle): string
    {
        $page = $this->pageService->getPageModel((int)($input['page_id'] ?? 0));
        if ($page === null) {
            return 'page_missing';
        }
        if ($page->getWebsiteId() !== (int)($input['website_id'] ?? -1)) {
            return 'website_changed';
        }

        $storeId = (int)($input['store_id'] ?? 0);
        $payload = $this->pageLocaleService->buildEditorPayload($page, '', $storeId > 0 ? $storeId : null);
        if ((int)($payload['store_id'] ?? 0) !== $storeId
            || !hash_equals((string)($input['store_code'] ?? ''), (string)($payload['store_code'] ?? ''))
        ) {
            return 'website_changed';
        }
        $supported = array_values(array_map('strval', (array)($payload['supported_locales'] ?? [])));
        $targetLocale = trim($targetLocale);
        if (!in_array($targetLocale, $supported, true)) {
            return 'unsupported_locale';
        }
        $sourceLocale = trim((string)($payload['source_locale'] ?? ''));
        $titles = is_array($payload['titles'] ?? null) ? $payload['titles'] : [];
        $sourceTitle = trim((string)($titles[$sourceLocale] ?? ''));
        if ($sourceLocale !== trim((string)($input['source_locale'] ?? ''))
            || !hash_equals((string)($input['source_hash'] ?? ''), hash('sha256', $sourceTitle))
        ) {
            return 'source_changed';
        }
        if (trim((string)($titles[$targetLocale] ?? '')) !== '') {
            return 'already_filled';
        }

        $saved = $this->pageLocaleService->fillMissingTitle(
            $page,
            $targetLocale,
            $translatedTitle,
            (string)$input['source_hash'],
            $supported,
            $storeId,
        );

        return $saved === null ? 'already_filled' : 'saved';
    }
}
