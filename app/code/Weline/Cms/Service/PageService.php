<?php
declare(strict_types=1);

namespace Weline\Cms\Service;

use Weline\Cms\Model\Page;
use Weline\Cms\Model\PathGroup;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\WlsRuntimeAdapterInterface;
use Weline\Seo\Api\Url\UrlChangeNotifierInterface;
use Weline\Theme\Api\Layout\LayoutWorkspaceInterface;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

class PageService
{
    public const DEFAULT_PREVIEW_VERSION = 'draft';
    public const PREVIEW_QUERY_FLAG = 'cms_preview';
    public const PREVIEW_TOKEN_QUERY_KEY = 'weline_preview_token';

    /** @var list<string> */
    private const RESERVED_ROUTE_PREFIXES = [
        'admin',
        'backend',
        'api',
        'rest',
        'graphql',
        'static',
        'pub/static',
        'pub/media',
        'media',
        'uploads',
        'cms',
        'theme',
        'customer',
        'account',
        'cart',
        'checkout',
        'product',
        'category',
        'catalog',
        'search',
        'wishlist',
    ];

    public function __construct(
        private readonly Page $pageModel,
        private readonly PathGroup $pathGroupModel,
        private readonly Url $url,
        private readonly Request $request,
        private readonly ?EventsManager $eventsManager = null,
        private ?PageLocaleService $pageLocaleService = null,
        private ?PageSlugService $pageSlugService = null,
        private ?CmsEditorContextResolver $cmsContextResolver = null,
        private ?CmsPageVariantService $variantService = null,
        private ?LayoutWorkspaceInterface $layoutWorkspace = null,
        private ?StoreCatalogInterface $storeCatalog = null,
    ) {
    }

    public function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim(str_replace('\\', '/', $identifier));
        if (str_contains($identifier, '://')) {
            $identifier = (string)(parse_url($identifier, PHP_URL_PATH) ?: '');
        }
        if (str_contains($identifier, '?')) {
            $identifier = explode('?', $identifier, 2)[0];
        }
        $identifier = strtolower(trim(rawurldecode($identifier), '/ '));
        $identifier = preg_replace('#/+#', '/', $identifier) ?? $identifier;

        if ($identifier === '') {
            throw new \InvalidArgumentException((string)__('页面路径不能为空。'));
        }
        if (strlen($identifier) > 190) {
            throw new \InvalidArgumentException((string)__('页面路径不能超过 190 个字符。'));
        }
        if (str_contains($identifier, '..') || !preg_match('#^[a-z0-9][a-z0-9/_-]*$#', $identifier)) {
            throw new \InvalidArgumentException((string)__('页面路径只能包含字母、数字、斜杠、中划线和下划线。'));
        }

        return $identifier;
    }

    public function normalizeScope(?string $scope): string
    {
        $scope = trim((string)$scope);
        return $scope !== '' ? $scope : 'default';
    }

    public function normalizeStatus(?string $status): string
    {
        $status = strtolower(trim((string)$status));
        if ($status === '') {
            return Page::STATUS_DRAFT;
        }
        if (!in_array($status, [Page::STATUS_DRAFT, Page::STATUS_PUBLISHED, Page::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException((string)__('无效的 CMS 页面状态：%{1}', $status));
        }

        return $status;
    }

    public function assertPagePublishable(Page $page): void
    {
        if ($page->getPageId() <= 0 || $page->isDeleted()) {
            throw new \InvalidArgumentException((string)__('CMS 页面不存在或已删除，不能发布。'));
        }
        $this->assertRouteAvailableForPublish($page->getIdentifier());
    }

    /** @param array<string,mixed> $variantContext */
    public function notifyVariantPublished(Page $page, array $variantContext): void
    {
        $reason = 'cms_page_variant_publish_' . $page->getPageId()
            . '_' . (int)($variantContext['store_id'] ?? 0)
            . '_' . (string)($variantContext['locale_code'] ?? '');
        $this->schedulePostCommitSideEffects(
            $page,
            'publish',
            [],
            $reason,
        );
    }

    public function normalizePathGroup(string $pathGroup): string
    {
        $pathGroup = $this->normalizePathSegment($pathGroup, false);
        if ($pathGroup === '') {
            throw new \InvalidArgumentException((string)__('一级路径不能为空。'));
        }
        if (strlen($pathGroup) > 100) {
            throw new \InvalidArgumentException((string)__('一级路径不能超过 100 个字符。'));
        }
        if (str_contains($pathGroup, '/')) {
            throw new \InvalidArgumentException((string)__('一级路径只能是一段，不能包含斜杠。'));
        }
        if ($this->isReservedRoutePrefix($pathGroup)) {
            throw new \InvalidArgumentException((string)__('一级路径为系统保留路径，不能使用。'));
        }

        return $pathGroup;
    }

    public function normalizeSlug(?string $slug): string
    {
        $slug = $this->normalizePathSegment((string)$slug, true);
        if (strlen($slug) > 190) {
            throw new \InvalidArgumentException((string)__('页面 slug 不能超过 190 个字符。'));
        }

        return $slug;
    }

    /**
     * @return array{path_group:string,slug:string,identifier:string}
     */
    public function normalizePathParts(array $data): array
    {
        $pathGroupInput = trim((string)($data['path_group'] ?? $data['group'] ?? ''));
        $slugInput = trim((string)($data['slug'] ?? ''));
        if ($pathGroupInput === '' && $slugInput === '') {
            return $this->splitIdentifierToPathParts(
                $this->normalizeIdentifier((string)($data['identifier'] ?? $data['path'] ?? ''))
            );
        }

        $pathGroup = $this->normalizePathGroup($pathGroupInput);
        $slug = $this->normalizeSlug($slugInput);
        $identifier = $this->composeIdentifier($pathGroup, $slug);

        return [
            'path_group' => $pathGroup,
            'slug' => $slug,
            'identifier' => $identifier,
        ];
    }

    public function normalizePathGroupAlias(?string $alias, string $pathGroup): string
    {
        $alias = trim((string)$alias);
        if ($alias === '') {
            return $pathGroup;
        }
        $aliasLength = function_exists('mb_strlen') ? mb_strlen($alias) : strlen($alias);
        if ($aliasLength > 255) {
            throw new \InvalidArgumentException((string)__('一级路径显示别名不能超过 255 个字符。'));
        }

        return $alias;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function savePage(array $data): Page
    {
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        if (!$transactions->isActive($this->pageModel->getConnection())) {
            return $transactions->runWrite(
                $this->pageModel->getConnection(),
                fn(): Page => $this->savePage($data),
            );
        }
        if (!$transactions->isWriteIntent($this->pageModel->getConnection())) {
            throw new \LogicException((string)__('CMS 页面保存必须位于写意图事务内。'));
        }
        $pageId = (int)($data['page_id'] ?? $data['id'] ?? 0);
        $group = $this->resolvePathGroupInput($data);
        if ($group !== null) {
            $data['website_id'] = $group->getWebsiteId();
            $data['website_code'] = $group->getWebsiteCode();
            $data['path_group'] = $group->getPathGroup();
            if (trim((string)($data['path_group_alias'] ?? '')) === '') {
                $data['path_group_alias'] = $group->getAlias();
            }
        }
        $website = $this->resolveWebsiteForSave($data);
        $pathParts = $this->normalizePathParts($data);
        $identifier = $pathParts['identifier'];
        $scope = $this->normalizeScope(isset($data['scope']) ? (string)$data['scope'] : null);
        $requestedStatus = $this->normalizeStatus(isset($data['status']) ? (string)$data['status'] : Page::STATUS_DRAFT);
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException((string)__('页面标题不能为空。'));
        }
        $pathGroupAlias = $this->normalizePathGroupAlias(
            isset($data['path_group_alias']) ? (string)$data['path_group_alias'] : null,
            $pathParts['path_group']
        );
        $group = $group
            ? $this->syncPathGroupAlias($group, $pathGroupAlias)
            : $this->ensurePathGroup($website, $pathParts['path_group'], $pathGroupAlias);
        $pathGroupAlias = $group->getAlias() !== '' ? $group->getAlias() : $pathGroupAlias;

        $page = clone $this->pageModel;
        $previousData = [];
        if ($pageId > 0) {
            $page = $this->getPageModelForUpdate($pageId);
            if (!$page instanceof Page) {
                throw new \InvalidArgumentException((string)__('CMS 页面不存在。'));
            }
            $previousData = $page->getData();
            if ($page->getWebsiteId() !== (int)$website['website_id']) {
                throw new \InvalidArgumentException((string)__(
                    'CMS 页面所属网站不可直接变更，请使用跨网站复制。',
                ));
            }
        }

        $page->setData(Page::schema_fields_WEBSITE_ID, (int)$website['website_id']);
        $page->setData(Page::schema_fields_WEBSITE_CODE, (string)$website['website_code']);
        $page->setData(Page::schema_fields_PATH_GROUP, $pathParts['path_group']);
        $page->setData(Page::schema_fields_PATH_GROUP_ALIAS, $pathGroupAlias);

        $localeService = $this->pageLocaleService ??= ObjectManager::getInstance(PageLocaleService::class);
        $localeContext = $localeService->prepareWriteLocales(
            $page,
            (string)($data['locale_code'] ?? $data['locale'] ?? ''),
            (string)($data['source_locale'] ?? ''),
            isset($data['store_id']) ? (int)$data['store_id'] : null,
        );
        $localeTitles = $localeService->normalizeSubmittedTitles(
            $data['locale_titles'] ?? $data['locale_titles_json'] ?? [],
            $localeContext['supported_locales'],
        );
        $localeTitles[$localeContext['locale_code']] = $title;
        $submittedSourceTitle = trim((string)($localeTitles[$localeContext['source_locale']] ?? ''));
        $isDefaultStore = false;
        try {
            $isDefaultStore = ($this->cmsContextResolver ??= ObjectManager::getInstance(CmsEditorContextResolver::class))
                ->resolve($page, (int)$localeContext['store_id'], $localeContext['source_locale'])
                ->defaultStore;
        } catch (\Throwable $exception) {
            throw new \RuntimeException((string)__('无法解析 CMS 页面店铺上下文。'), 0, $exception);
        }
        if ($pageId <= 0 && !$isDefaultStore) {
            throw new \InvalidArgumentException((string)__('新建 CMS 页面必须先在网站默认店铺保存源语言变体。'));
        }
        if ($isDefaultStore && $submittedSourceTitle !== '') {
            $page->setData(Page::schema_fields_TITLE, $submittedSourceTitle);
        }
        if ($isDefaultStore && $localeContext['locale_code'] === $localeContext['source_locale']) {
            $page->setData(Page::schema_fields_TITLE, $title);
        } elseif ($pageId <= 0) {
            throw new \InvalidArgumentException((string)__('新建 CMS 页面时必须先保存源语言标题。'));
        }
        $page->setSourceLocale($localeContext['source_locale']);

        $slugService = $this->pageSlugService ??= new PageSlugService($this->pageModel);
        $slugDecision = $slugService->resolveForSave(
            $page,
            $page->getTitle(),
            (string)($data['slug'] ?? $pathParts['slug']),
            $pathParts['path_group'],
            (int)$website['website_id'],
            $requestedStatus,
            (string)($data['slug_mode'] ?? ''),
        );
        $identifier = $slugDecision['identifier'];
        $page->setData(Page::schema_fields_SLUG, $slugDecision['slug']);
        $page->setData(Page::schema_fields_IDENTIFIER, $identifier);
        $page->setSlugMode($slugDecision['mode']);
        $page->setSlugSourceHash($slugDecision['source_hash']);

        $this->assertUniqueIdentifier($identifier, (int)$website['website_id'], $pageId);
        $page->setData(
            Page::schema_fields_STATUS,
            $requestedStatus === Page::STATUS_DISABLED ? Page::STATUS_DISABLED : Page::STATUS_DRAFT,
        );
        $page->setData(Page::schema_fields_SCOPE, $scope);
        if (array_key_exists('deleted_at', $data)) {
            $page->setData(Page::schema_fields_DELETED_AT, $data['deleted_at'] ?: null);
        }
        $page->save();

        if ($page->getPageId() <= 0) {
            $savedPage = $this->getPageByIdentifier($identifier, $scope, true, (int)$website['website_id']);
            if ($savedPage === null) {
                throw new \RuntimeException((string)__('CMS 页面保存后未能读取页面ID。'));
            }
            $page = $savedPage;
        }

        $titleOrigin = trim((string)($data['title_origin'] ?? ''));
        if ($titleOrigin === '') {
            $titleOrigin = $pageId <= 0
                && $localeContext['locale_code'] === $localeContext['source_locale']
                ? \Weline\Cms\Model\PageLocale::ORIGIN_SOURCE
                : \Weline\Cms\Model\PageLocale::ORIGIN_MANUAL;
        }
        $sourceTitleHash = hash('sha256', $page->getTitle());
        foreach ($localeTitles as $locale => $localizedTitle) {
            $isCurrentLocale = $locale === $localeContext['locale_code'];
            $isSourceLocale = $locale === $localeContext['source_locale'];
            $existingVariant = $localeService->findVariant(
                $page->getPageId(),
                (int)$localeContext['store_id'],
                $locale,
            );
            if (!$isCurrentLocale
                && $existingVariant instanceof \Weline\Cms\Model\PageLocale
                && hash_equals($existingVariant->getTitle(), $localizedTitle)
            ) {
                continue;
            }
            $origin = $isCurrentLocale
                ? $titleOrigin
                : ($isSourceLocale
                    ? \Weline\Cms\Model\PageLocale::ORIGIN_SOURCE
                    : \Weline\Cms\Model\PageLocale::ORIGIN_MANUAL);
            $translationState = \Weline\Cms\Model\PageLocale::TRANSLATION_STATE_REVIEWED;
            if ($existingVariant instanceof \Weline\Cms\Model\PageLocale
                && $existingVariant->getOrigin() === \Weline\Cms\Model\PageLocale::ORIGIN_AI
                && hash_equals($existingVariant->getTitle(), $localizedTitle)
            ) {
                $origin = \Weline\Cms\Model\PageLocale::ORIGIN_AI;
                $translationState = $isCurrentLocale && !empty($data['translation_reviewed'])
                    ? \Weline\Cms\Model\PageLocale::TRANSLATION_STATE_REVIEWED
                    : \Weline\Cms\Model\PageLocale::TRANSLATION_STATE_DRAFT;
            }
            $localeService->upsertTitle(
                $page,
                $locale,
                $localizedTitle,
                $origin,
                $isCurrentLocale && trim((string)($data['source_hash'] ?? '')) !== ''
                    ? trim((string)$data['source_hash'])
                    : ($isSourceLocale ? hash('sha256', $localizedTitle) : $sourceTitleHash),
                $localeContext['supported_locales'],
                (int)$localeContext['store_id'],
                $translationState,
                \Weline\Cms\Model\PageLocale::VALIDATION_STATE_PENDING,
                $requestedStatus === Page::STATUS_DISABLED
                    ? \Weline\Cms\Model\PageLocale::VARIANT_STATUS_DISABLED
                    : \Weline\Cms\Model\PageLocale::VARIANT_STATUS_DRAFT,
            );
        }

        $variants = $this->variantService ??= ObjectManager::getInstance(CmsPageVariantService::class);
        if ($requestedStatus === Page::STATUS_DISABLED) {
            $variants->disableAll($page);
            $page->setData(Page::schema_fields_STATUS, Page::STATUS_DISABLED)->save();
        } else {
            $variants->aggregatePageStatus($page, false);
        }

        $changeAction = $this->resolvePageChangeAction($page, $previousData, $pageId <= 0);
        $previous = clone $this->pageModel;
        $previous->clearData()->setData($previousData);
        ObjectManager::getInstance(CmsPageResourceChangePublisher::class)->publish(
            $page,
            $changeAction,
            $previousData,
            $this->buildPublicUrl($page),
            $previousData !== [] && $previous->getIdentifier() !== '' ? $this->buildPublicUrl($previous) : '',
        );
        $this->schedulePostCommitSideEffects(
            $page,
            $changeAction,
            $previousData,
            'cms_page_save_' . $page->getPageId(),
        );

        return $page;
    }

    public function createDraftPage(?string $scope = null, string $layoutOption = 'default', array $siteParams = []): Page
    {
        $scope = $this->normalizeScope($scope);
        $group = $this->resolvePathGroupInput($siteParams);
        if ($group !== null) {
            $siteParams['website_id'] = $group->getWebsiteId();
            $siteParams['website_code'] = $group->getWebsiteCode();
            $siteParams['path_group'] = $group->getPathGroup();
            $siteParams['path_group_alias'] = $group->getAlias();
        }
        $website = $this->resolveWebsiteForSave($siteParams);
        $pathGroup = trim((string)($siteParams['path_group'] ?? '')) !== ''
            ? $this->normalizePathGroup((string)$siteParams['path_group'])
            : 'cms-draft';
        $pathGroupAlias = $this->normalizePathGroupAlias(
            isset($siteParams['path_group_alias']) ? (string)$siteParams['path_group_alias'] : null,
            $pathGroup
        );
        $group = $group ?: $this->ensurePathGroup($website, $pathGroup, $pathGroupAlias);
        $page = $this->savePage([
            'title' => (string)__('新建 CMS 页面'),
            'path_group_id' => $group->getGroupId(),
            'website_id' => $website['website_id'],
            'website_code' => $website['website_code'],
            'path_group' => $group->getPathGroup(),
            'path_group_alias' => $group->getAlias(),
            'slug' => 'page',
            'slug_mode' => Page::SLUG_MODE_AUTO,
            'scope' => $scope,
            'status' => Page::STATUS_DRAFT,
        ]);

        $this->saveLayoutSelection($page->getPageId(), $layoutOption, $scope);

        return $page;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{page:Page,theme:array<string,mixed>,target_website:array{website_id:int,website_code:string,name:string,url:string}}
     */
    public function copyPage(int $sourcePageId, array $data): array
    {
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $connection = $this->pageModel->getConnection();
        if (!$transactions->isActive($connection)) {
            return $transactions->runWrite(
                $connection,
                fn (): array => $this->copyPage($sourcePageId, $data),
            );
        }
        if (!$transactions->isWriteIntent($connection)) {
            throw new \LogicException((string)__('CMS 页面复制必须位于写意图事务内。'));
        }
        $sourcePage = $this->getPageModel($sourcePageId, false);
        if ($sourcePage === null) {
            throw new \InvalidArgumentException((string)__('CMS 页面不存在。'));
        }

        $targetWebsite = $this->resolveCopyTargetWebsite($data);
        if ($this->isSameWebsite($sourcePage->getWebsiteId(), $sourcePage->getWebsiteCode(), $targetWebsite)) {
            throw new \InvalidArgumentException((string)__('源站点和目标站点相同，无需拷贝。'));
        }

        $result = $this->copyPageToWebsite($sourcePage, $targetWebsite);
        $result['target_website'] = $targetWebsite;

        return $result;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{path_group:PathGroup,pages:list<Page>,theme_results:list<array<string,mixed>>,target_website:array{website_id:int,website_code:string,name:string,url:string}}
     */
    public function copyPathGroup(array $data): array
    {
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $connection = $this->pageModel->getConnection();
        if (!$transactions->isActive($connection)) {
            return $transactions->runWrite(
                $connection,
                fn (): array => $this->copyPathGroup($data),
            );
        }
        if (!$transactions->isWriteIntent($connection)) {
            throw new \LogicException((string)__('CMS 页面组复制必须位于写意图事务内。'));
        }
        $sourceGroup = $this->resolveCopySourcePathGroup($data);
        $targetWebsite = $this->resolveCopyTargetWebsite($data);
        if ($this->isSameWebsite($sourceGroup->getWebsiteId(), $sourceGroup->getWebsiteCode(), $targetWebsite)) {
            throw new \InvalidArgumentException((string)__('源站点和目标站点相同，无需拷贝。'));
        }

        $sourcePages = $this->listPageModelsForPathGroup($sourceGroup);
        $conflicts = [];
        foreach ($sourcePages as $sourcePage) {
            try {
                $this->assertUniqueIdentifier($sourcePage->getIdentifier(), (int)$targetWebsite['website_id'], 0);
            } catch (\InvalidArgumentException) {
                $conflicts[] = $sourcePage->getIdentifier();
            }
        }
        if ($conflicts !== []) {
            $visibleConflicts = implode(', ', array_slice(array_values(array_unique($conflicts)), 0, 5));
            throw new \InvalidArgumentException(
                (string)__('目标站点已存在相同路径的 CMS 页面：%{1}', $visibleConflicts)
            );
        }

        $targetGroup = $this->savePathGroup([
            'website_id' => (int)$targetWebsite['website_id'],
            'website_code' => (string)$targetWebsite['website_code'],
            'path_group' => $sourceGroup->getPathGroup(),
            'path_group_alias' => $sourceGroup->getAlias(),
            'sort_order' => (int)$sourceGroup->getData(PathGroup::schema_fields_SORT_ORDER),
        ]);

        $copiedPages = [];
        $themeResults = [];
        foreach ($sourcePages as $sourcePage) {
            $result = $this->copyPageToWebsite($sourcePage, $targetWebsite, $targetGroup);
            $copiedPages[] = $result['page'];
            $themeResults[] = $result['theme'];
        }

        return [
            'path_group' => $targetGroup,
            'pages' => $copiedPages,
            'theme_results' => $themeResults,
            'target_website' => $targetWebsite,
        ];
    }

    public function softDeletePage(int $pageId): Page
    {
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        if (!$transactions->isActive($this->pageModel->getConnection())) {
            return $transactions->runWrite(
                $this->pageModel->getConnection(),
                fn(): Page => $this->softDeletePage($pageId),
            );
        }
        if (!$transactions->isWriteIntent($this->pageModel->getConnection())) {
            throw new \LogicException((string)__('CMS 页面删除必须位于写意图事务内。'));
        }
        $page = $this->getPageModelForUpdate($pageId);
        if ($page === null) {
            throw new \InvalidArgumentException((string)__('CMS 页面不存在。'));
        }

        $previousData = $page->getData();
        $page->setData(Page::schema_fields_STATUS, Page::STATUS_DISABLED);
        $page->setData(Page::schema_fields_DELETED_AT, date('Y-m-d H:i:s'));
        $page->save();
        ($this->variantService ??= ObjectManager::getInstance(CmsPageVariantService::class))->disableAll($page);
        $previous = clone $this->pageModel;
        $previous->clearData()->setData(is_array($previousData) ? $previousData : []);
        ObjectManager::getInstance(CmsPageResourceChangePublisher::class)->publish(
            $page,
            'delete',
            is_array($previousData) ? $previousData : [],
            '',
            $previous->getIdentifier() !== '' ? $this->buildPublicUrl($previous) : '',
        );
        $this->schedulePostCommitSideEffects(
            $page,
            'delete',
            is_array($previousData) ? $previousData : [],
            'cms_page_delete_' . $page->getPageId(),
        );

        return $page;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function restorePageFromTrashRow(array $row): Page
    {
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        if (!$transactions->isActive($this->pageModel->getConnection())) {
            return $transactions->runWrite(
                $this->pageModel->getConnection(),
                fn (): Page => $this->restorePageFromTrashRow($row),
            );
        }
        if (!$transactions->isWriteIntent($this->pageModel->getConnection())) {
            throw new \LogicException((string)__('CMS 页面恢复必须位于写意图事务内。'));
        }
        $pageId = (int)($row[Page::schema_fields_ID] ?? $row['page_id'] ?? 0);
        if ($pageId <= 0) {
            throw new \InvalidArgumentException((string)__('回收站快照中缺少 CMS 页面ID，无法恢复。'));
        }
        if ($this->getPageModel($pageId, true) === null) {
            throw new \InvalidArgumentException((string)__('原 CMS 页面记录不存在，无法自动恢复；请从原始数据手动处理。'));
        }

        $this->savePage([
            'page_id' => $pageId,
            'website_id' => (int)($row[Page::schema_fields_WEBSITE_ID] ?? 0),
            'website_code' => (string)($row[Page::schema_fields_WEBSITE_CODE] ?? 'default'),
            'path_group' => (string)($row[Page::schema_fields_PATH_GROUP] ?? ''),
            'path_group_alias' => (string)($row[Page::schema_fields_PATH_GROUP_ALIAS] ?? ''),
            'slug' => (string)($row[Page::schema_fields_SLUG] ?? ''),
            'identifier' => (string)($row[Page::schema_fields_IDENTIFIER] ?? ''),
            'title' => (string)($row[Page::schema_fields_TITLE] ?? ''),
            'status' => (string)($row[Page::schema_fields_STATUS] ?? Page::STATUS_DRAFT),
            'scope' => (string)($row[Page::schema_fields_SCOPE] ?? 'default'),
            'deleted_at' => null,
        ]);
        $this->clearPageDeletedAt($pageId);

        $restored = $this->getPageModel($pageId, true);
        if ($restored === null || $restored->isDeleted()) {
            throw new \RuntimeException((string)__('CMS 页面恢复后仍处于删除状态，请检查数据库 soft delete 字段写入。'));
        }
        ($this->variantService ??= ObjectManager::getInstance(CmsPageVariantService::class))
            ->restoreAllAsDraft($restored);

        return $restored;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function savePathGroup(array $data): PathGroup
    {
        $groupId = (int)($data['group_id'] ?? $data['path_group_id'] ?? $data['id'] ?? 0);
        $website = $this->resolveWebsiteForSave($data);
        $pathGroup = $this->normalizePathGroup((string)($data['path_group'] ?? $data['path'] ?? ''));
        $alias = $this->normalizePathGroupAlias(
            isset($data['alias']) ? (string)$data['alias'] : (isset($data['path_group_alias']) ? (string)$data['path_group_alias'] : null),
            $pathGroup
        );

        $group = clone $this->pathGroupModel;
        if ($groupId > 0) {
            $group->load($groupId);
            if ($group->getGroupId() <= 0) {
                throw new \InvalidArgumentException((string)__('CMS 一级 path 不存在。'));
            }
        } else {
            $existing = $this->getPathGroupByPath((int)$website['website_id'], $pathGroup, true);
            if ($existing !== null) {
                $group = $existing;
            }
        }

        $group->setData(PathGroup::schema_fields_WEBSITE_ID, (int)$website['website_id']);
        $group->setData(PathGroup::schema_fields_WEBSITE_CODE, (string)$website['website_code']);
        $group->setData(PathGroup::schema_fields_PATH_GROUP, $pathGroup);
        $group->setData(PathGroup::schema_fields_ALIAS, $alias);
        $group->setData(PathGroup::schema_fields_SORT_ORDER, (int)($data['sort_order'] ?? $group->getData(PathGroup::schema_fields_SORT_ORDER) ?? 0));
        if (array_key_exists('deleted_at', $data)) {
            $group->setData(PathGroup::schema_fields_DELETED_AT, $data['deleted_at'] ?: null);
        }
        $group->save();

        $this->syncPageGroupAlias((int)$website['website_id'], $pathGroup, $alias);

        return $group;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function restorePathGroupFromTrashRow(array $row): PathGroup
    {
        $groupId = (int)($row[PathGroup::schema_fields_ID] ?? $row['group_id'] ?? 0);
        if ($groupId <= 0) {
            throw new \InvalidArgumentException((string)__('回收站快照中缺少 CMS 一级 path ID，无法恢复。'));
        }
        if ($this->getPathGroupModel($groupId, true) === null) {
            throw new \InvalidArgumentException((string)__('原 CMS 一级 path 记录不存在，无法自动恢复；请从原始数据手动处理。'));
        }

        $this->savePathGroup([
            'group_id' => $groupId,
            'website_id' => (int)($row[PathGroup::schema_fields_WEBSITE_ID] ?? 0),
            'website_code' => (string)($row[PathGroup::schema_fields_WEBSITE_CODE] ?? 'default'),
            'path_group' => (string)($row[PathGroup::schema_fields_PATH_GROUP] ?? ''),
            'alias' => (string)($row[PathGroup::schema_fields_ALIAS] ?? ''),
            'sort_order' => (int)($row[PathGroup::schema_fields_SORT_ORDER] ?? 0),
            'deleted_at' => null,
        ]);
        $this->clearPathGroupDeletedAt($groupId);

        $restored = $this->getPathGroupModel($groupId, true);
        if ($restored === null || $restored->isDeleted()) {
            throw new \RuntimeException((string)__('CMS 一级 path 恢复后仍处于删除状态，请检查数据库 soft delete 字段写入。'));
        }

        return $restored;
    }

    private function clearPageDeletedAt(int $pageId): void
    {
        $page = clone $this->pageModel;
        $page->newQuery()
            ->where(Page::schema_fields_ID, $pageId)
            ->update([Page::schema_fields_DELETED_AT => null], Page::schema_fields_ID)
            ->fetch();
    }

    private function clearPathGroupDeletedAt(int $groupId): void
    {
        $group = clone $this->pathGroupModel;
        $group->newQuery()
            ->where(PathGroup::schema_fields_ID, $groupId)
            ->update([PathGroup::schema_fields_DELETED_AT => null], PathGroup::schema_fields_ID)
            ->fetch();
    }

    public function getPathGroupModel(int $groupId, bool $includeDeleted = false): ?PathGroup
    {
        if ($groupId <= 0) {
            return null;
        }

        $group = clone $this->pathGroupModel;
        $group->load($groupId);
        if ($group->getGroupId() <= 0) {
            return null;
        }
        if (!$includeDeleted && $group->isDeleted()) {
            return null;
        }

        return $group;
    }

    public function getPathGroupByPath(int $websiteId, string $pathGroup, bool $includeDeleted = false): ?PathGroup
    {
        $pathGroup = $this->normalizePathGroup($pathGroup);
        $group = clone $this->pathGroupModel;
        $query = $group->clearData()->reset()
            ->where(PathGroup::schema_fields_WEBSITE_ID, $websiteId)
            ->where(PathGroup::schema_fields_PATH_GROUP, $pathGroup);
        if (!$includeDeleted) {
            $query->where(PathGroup::schema_fields_DELETED_AT, null, 'IS NULL');
        }
        $query->find()->fetch();

        return $group->getGroupId() > 0 ? $group : null;
    }

    /**
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function listPathGroups(array $params): array
    {
        $websiteId = $this->resolveWebsiteIdForRead($params, false);
        $filterWebsite = $this->hasWebsiteSelector($params);
        $search = trim((string)($params['q'] ?? $params['search'] ?? ''));
        $pathGroup = trim((string)($params['path_group'] ?? ''));

        $model = clone $this->pathGroupModel;
        $query = $model->clearData()->reset();
        if ($filterWebsite) {
            $query->where(PathGroup::schema_fields_WEBSITE_ID, $websiteId);
        }
        if ($pathGroup !== '') {
            $query->where(PathGroup::schema_fields_PATH_GROUP, $this->normalizePathGroup($pathGroup));
        }
        if ($search !== '') {
            $query->where(
                'CONCAT(main_table.path_group,main_table.alias,main_table.website_code)',
                '%' . $search . '%',
                'LIKE'
            );
        }
        if (empty($params['include_deleted'])) {
            $query->where(PathGroup::schema_fields_DELETED_AT, null, 'IS NULL');
        }

        $rows = $query->order('main_table.' . PathGroup::schema_fields_WEBSITE_ID, 'ASC')
            ->order('main_table.' . PathGroup::schema_fields_SORT_ORDER, 'ASC')
            ->order('main_table.' . PathGroup::schema_fields_PATH_GROUP, 'ASC')
            ->select()
            ->fetchArray();

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $group = clone $this->pathGroupModel;
                $group->clearData()->setData($row);
                $items[] = $group->toApiArray();
            }
        }

        return $items;
    }

    public function getPageModel(int $pageId, bool $includeDeleted = false): ?Page
    {
        if ($pageId <= 0) {
            return null;
        }

        $page = clone $this->pageModel;
        $page->load($pageId);
        if ($page->getPageId() <= 0) {
            return null;
        }
        if (!$includeDeleted && $page->isDeleted()) {
            return null;
        }

        return $page;
    }

    private function getPageModelForUpdate(int $pageId): ?Page
    {
        if ($pageId <= 0) {
            return null;
        }
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $connection = $this->pageModel->getConnection();
        if (!$transactions->isActive($connection) || !$transactions->isWriteIntent($connection)) {
            throw new \LogicException((string)__('CMS 页面锁定读取必须位于写意图事务内。'));
        }
        $page = clone $this->pageModel;
        $page->clearData()->reset()
            ->where(Page::schema_fields_ID, $pageId)
            ->limit(1);
        $type = strtolower((string)$connection->getConnector()->getConfigProvider()->getDbType());
        if (in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true)) {
            $page->additional('FOR UPDATE');
        }
        $items = array_values($page->select()->fetch()->getItems());
        $current = $items[0] ?? null;
        return $current instanceof Page ? $current : null;
    }

    public function getPageByIdentifier(
        string $identifier,
        ?string $scope = null,
        bool $includeDeleted = false,
        ?int $websiteId = null
    ): ?Page
    {
        $identifier = $this->normalizeIdentifier($identifier);
        $scope = $this->normalizeScope($scope);
        $websiteId = $websiteId ?? $this->resolveCurrentWebsiteId();

        $page = clone $this->pageModel;
        $query = $page->clearData()->reset()
            ->where(Page::schema_fields_IDENTIFIER, $identifier);
        $query->where(Page::schema_fields_WEBSITE_ID, $websiteId);
        if ($scope !== '') {
            $query->where(Page::schema_fields_SCOPE, $scope);
        }
        if (!$includeDeleted) {
            $query->where(Page::schema_fields_DELETED_AT, null, 'IS NULL');
        }
        $query->find()->fetch();

        return $page->getPageId() > 0 ? $page : null;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function getPage(array $params): ?array
    {
        $page = null;
        $includeDeleted = !empty($params['include_deleted']);
        if ((int)($params['page_id'] ?? 0) > 0) {
            $page = $this->getPageModel((int)$params['page_id'], $includeDeleted);
        } elseif (trim((string)($params['identifier'] ?? $params['path'] ?? '')) !== '') {
            $page = $this->getPageByIdentifier(
                (string)($params['identifier'] ?? $params['path']),
                isset($params['scope']) ? (string)$params['scope'] : null,
                $includeDeleted,
                $this->resolveWebsiteIdForRead($params)
            );
        } elseif (trim((string)($params['path_group'] ?? '')) !== '') {
            $pathParts = $this->normalizePathParts($params);
            $page = $this->getPageByIdentifier(
                $pathParts['identifier'],
                isset($params['scope']) ? (string)$params['scope'] : null,
                $includeDeleted,
                $this->resolveWebsiteIdForRead($params)
            );
        }

        return $page ? $this->toPageApiArray($page) : null;
    }

    /**
     * @param array<string,mixed> $params
     * @return array{items:list<array<string,mixed>>,pagination:mixed}
     */
    public function listPages(array $params): array
    {
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = min(200, max(1, (int)($params['page_size'] ?? 20)));
        $status = trim((string)($params['status'] ?? ''));
        $scope = trim((string)($params['scope'] ?? ''));
        $search = trim((string)($params['q'] ?? $params['search'] ?? ''));
        $websiteId = $this->resolveWebsiteIdForRead($params, false);
        $filterWebsite = $this->hasWebsiteSelector($params);
        $pathGroup = trim((string)($params['path_group'] ?? ''));

        $model = clone $this->pageModel;
        $query = $model->clearData()->reset();
        if ($filterWebsite) {
            $query->where(Page::schema_fields_WEBSITE_ID, $websiteId);
        }
        if ($status !== '') {
            $query->where(Page::schema_fields_STATUS, $this->normalizeStatus($status));
        }
        if ($scope !== '') {
            $query->where(Page::schema_fields_SCOPE, $this->normalizeScope($scope));
        }
        if ($pathGroup !== '') {
            $query->where(Page::schema_fields_PATH_GROUP, $this->normalizePathGroup($pathGroup));
        }
        if ($search !== '') {
            $query->where(
                'CONCAT(main_table.identifier,main_table.title,main_table.path_group,main_table.path_group_alias,main_table.slug,main_table.website_code)',
                '%' . $search . '%',
                'LIKE'
            );
        }
        if (empty($params['include_deleted'])) {
            $query->where(Page::schema_fields_DELETED_AT, null, 'IS NULL');
        }

        $rows = $query->order('main_table.' . Page::schema_fields_WEBSITE_ID, 'ASC')
            ->order('main_table.' . Page::schema_fields_PATH_GROUP, 'ASC')
            ->order('main_table.' . Page::schema_fields_ID, 'DESC')
            ->pagination($page, $pageSize)
            ->select()
            ->fetchArray();

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $pageItem = clone $this->pageModel;
                $pageItem->clearData()->setData($row);
                $items[] = $this->toPageApiArray($pageItem);
            }
        }

        return [
            'items' => $items,
            'pagination' => $model->getPagination(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveThemeTarget(int $targetId): array
    {
        $page = $this->getPageModel($targetId, true);
        if ($page === null) {
            return [
                'target_type' => Page::TARGET_TYPE,
                'target_id' => $targetId,
                'layout_type' => Page::LAYOUT_TYPE,
                'label' => (string)__('CMS 页面不存在'),
                'status' => 'missing',
                'scope' => 'default',
                'website_id' => 0,
                'website_code' => '',
                'path_group' => '',
                'path_group_alias' => '',
                'slug' => '',
                'public_url' => '',
                'preview_url' => '',
                'cache_tags' => ['cms_page_' . $targetId],
            ];
        }

        return [
            'target_type' => Page::TARGET_TYPE,
            'target_id' => $page->getPageId(),
            'layout_type' => Page::LAYOUT_TYPE,
            'label' => $page->getTitle(),
            'status' => $page->isDeleted() ? 'deleted' : $page->getStatus(),
            'scope' => $page->getScope(),
            'website_id' => $page->getWebsiteId(),
            'website_code' => $page->getWebsiteCode(),
            'path_group' => $page->getPathGroup(),
            'path_group_alias' => $page->getPathGroupAlias(),
            'slug' => $page->getSlug(),
            'public_url' => $this->buildPublicUrl($page),
            'preview_url' => '',
            'cache_tags' => ['cms_page_' . $page->getPageId(), 'cms_page'],
        ];
    }

    public function saveLayoutSelection(
        int $pageId,
        string $layoutOption,
        ?string $scope = null,
        string $localeCode = '',
        ?int $storeId = null,
    ): array
    {
        $page = $this->getPageModel($pageId, true);
        if ($page === null) {
            throw new \InvalidArgumentException((string)__('CMS 页面不存在。'));
        }

        $context = ($this->cmsContextResolver ??= ObjectManager::getInstance(CmsEditorContextResolver::class))
            ->resolve($page, $storeId, $localeCode);
        $result = ($this->layoutWorkspace ??= ObjectManager::getInstance(LayoutWorkspaceInterface::class))
            ->saveLayoutSelection(
                Page::TARGET_TYPE,
                $page->getPageId(),
                Page::LAYOUT_TYPE,
                $this->normalizeLayoutOption($layoutOption),
                $context->canonicalScope,
                $context->localeCode,
                [
                'reason' => (string)__('保存 CMS 页面布局选择'),
                'metadata' => [
                    'module' => 'Weline_Cms',
                    'page_id' => $page->getPageId(),
                    'store_id' => $context->storeId,
                    'locale_code' => $context->localeCode,
                ],
                ],
            );

        return is_array($result) ? $result : ['success' => false, 'status' => 'invalid_theme_response'];
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveLayoutSelectionForPage(
        Page $page,
        ?int $storeId = null,
        string $localeCode = '',
    ): array
    {
        $context = ($this->cmsContextResolver ??= ObjectManager::getInstance(CmsEditorContextResolver::class))
            ->resolve($page, $storeId, $localeCode);
        $selection = ($this->layoutWorkspace ??= ObjectManager::getInstance(LayoutWorkspaceInterface::class))
            ->resolveLayoutSelection(
                Page::TARGET_TYPE,
                $page->getPageId(),
                Page::LAYOUT_TYPE,
                $context->canonicalScope,
                $context->localeCode,
            );

        $layoutOption = 'default';
        if (is_array($selection)) {
            $layoutOption = $this->normalizeLayoutOption((string)($selection['layout_option'] ?? $selection['layout_code'] ?? 'default'));
        }

        return array_merge(
            [
                'layout_type' => Page::LAYOUT_TYPE,
                'layout_option' => $layoutOption,
                'layout_code' => $layoutOption,
                'source' => 'default',
                'source_scope' => $context->canonicalScope,
                'locale_code' => $context->localeCode,
                'store_id' => $context->storeId,
                'store_code' => $context->storeCode,
                'store_mode' => $context->storeMode,
                'version' => 0,
            ],
            is_array($selection) ? $selection : [],
            [
                'layout_type' => Page::LAYOUT_TYPE,
                'layout_option' => $layoutOption,
                'layout_code' => $layoutOption,
                'scope' => $context->canonicalScope,
                'locale_code' => $context->localeCode,
            ]
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function renderPagePayload(array $params): ?array
    {
        $preview = !empty($params['preview']);
        $scope = $preview && isset($params['scope']) ? (string)$params['scope'] : null;
        $requestIdentity = RequestContext::scopeIdentity();
        if (!$preview && $requestIdentity === null) {
            return null;
        }
        $websiteId = !$preview && $requestIdentity !== null
            ? (int)($requestIdentity->websiteId ?? 0)
            : $this->resolveWebsiteIdForRead($params);
        $page = null;

        if ((int)($params['page_id'] ?? 0) > 0) {
            $page = $this->getPageModel((int)$params['page_id'], $preview);
        } elseif (trim((string)($params['identifier'] ?? $params['path'] ?? '')) !== '') {
            $page = $this->getPageByIdentifier(
                (string)($params['identifier'] ?? $params['path']),
                $scope,
                $preview,
                $websiteId
            );
        } elseif (trim((string)($params['path_group'] ?? '')) !== '') {
            $pathParts = $this->normalizePathParts($params);
            $page = $this->getPageByIdentifier(
                $pathParts['identifier'],
                $scope,
                $preview,
                $websiteId
            );
        }

        if ($page === null) {
            return null;
        }
        if ($page->isDeleted() || (!$preview && !$page->isPublished())) {
            return null;
        }
        if (!$preview && $requestIdentity !== null
            && ((int)($requestIdentity->websiteId ?? 0) !== $page->getWebsiteId()
                || !hash_equals((string)($requestIdentity->websiteCode ?? ''), $page->getWebsiteCode()))
        ) {
            return null;
        }

        $storeId = $preview
            ? (int)($params['store_id'] ?? 0)
            : RequestContext::getWelineStoreId();
        $localeCode = $preview
            ? (string)($params['locale_code'] ?? $params['locale'] ?? RequestContext::locale())
            : (string)RequestContext::locale();
        try {
            $context = ($this->cmsContextResolver ??= ObjectManager::getInstance(CmsEditorContextResolver::class))
                ->resolve($page, $storeId > 0 ? $storeId : null, $localeCode);
            $variant = ($this->variantService ??= ObjectManager::getInstance(CmsPageVariantService::class))
                ->resolveForRead($page, $context->storeId, $context->localeCode, $preview);
        } catch (\Throwable) {
            return null;
        }
        if (!$variant instanceof \Weline\Cms\Model\PageLocale) {
            return null;
        }

        $layoutSelection = $this->resolveLayoutSelectionForPage(
            $page,
            $context->storeId,
            $context->localeCode,
        );
        $pageData = $this->toPageApiArray($page);
        $pageData['title'] = $variant->getTitle();
        $pageData['store_id'] = $context->storeId;
        $pageData['store_code'] = $context->storeCode;
        $pageData['store_mode'] = $context->storeMode;
        $pageData['locale_code'] = $context->localeCode;
        $pageData['scope'] = $context->canonicalScope;
        $pageData['variant_status'] = $variant->getVariantStatus();
        $pageData['translation_state'] = $variant->getTranslationState();
        $pageData['validation_state'] = $variant->getValidationState();

        return [
            'page' => $pageData,
            'target' => $this->resolveThemeTarget($page->getPageId()),
            'layout' => $layoutSelection,
            'content' => [
                'title' => $variant->getTitle(),
            ],
            'editor_context' => $context->toArray(),
            'cache_tags' => [
                'cms_page_' . $page->getPageId(),
                'cms_page_store_' . $context->storeId,
                'cms_page_locale_' . $context->localeCode,
                'cms_page',
            ],
        ];
    }

    public function buildPublicUrl(Page $page): string
    {
        return $this->buildFrontendPathUrl($page->getIdentifier(), [], $page);
    }

    public function buildPreviewUrl(
        Page $page,
        ?string $previewVersion = null,
        ?int $storeId = null,
        string $localeCode = '',
    ): string
    {
        $previewVersion = $this->normalizePreviewVersion($previewVersion ?: self::DEFAULT_PREVIEW_VERSION);
        $context = ($this->cmsContextResolver ??= ObjectManager::getInstance(CmsEditorContextResolver::class))
            ->resolve($page, $storeId, $localeCode);
        $params = [
            self::PREVIEW_QUERY_FLAG => 1,
            'preview' => 1,
            'preview_version' => $previewVersion,
            '_t' => time(),
            'store_id' => $context->storeId,
            'locale_code' => $context->localeCode,
        ];
        $params['website_id'] = $page->getWebsiteId();
        if ($page->getWebsiteCode() !== '') {
            $params['website_code'] = $page->getWebsiteCode();
        }
        $previewToken = $this->generatePreviewTokenForPage($page, $previewVersion, $context);
        $token = trim((string)($previewToken['token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException((string)__('CMS 预览凭据生成失败。'));
        }
        $tokenKey = trim((string)($previewToken['token_key'] ?? self::PREVIEW_TOKEN_QUERY_KEY));
        $params[$tokenKey !== '' ? $tokenKey : self::PREVIEW_TOKEN_QUERY_KEY] = $token;

        return $this->buildFrontendPathUrl(
            $page->getIdentifier(),
            $params,
            $page,
            $this->resolveStorePreviewBaseUrl($page, $context->storeId),
        );
    }

    public function buildFrontendPreviewUrl(Page $page): string
    {
        return $this->buildPreviewUrl($page);
    }

    /**
     * @return array{identifier:string,preview:bool,preview_version:string}
     */
    public function parseSlugPreviewPath(string $path): array
    {
        $normalized = $this->normalizeRawPath($path);

        return [
            'identifier' => $this->normalizeIdentifier($normalized),
            'preview' => false,
            'preview_version' => '',
        ];
    }

    public function clearThemeRuntimeCaches(string $reason): void
    {
        try {
            $result = w_query('theme', 'clearRuntimeLayoutCaches', ['reason' => $reason]);
            if (\is_array($result) && !empty($result['success'])) {
                return;
            }
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning(
                    (string)__('CMS 清理 Theme 运行时缓存失败：%{1}', $e->getMessage()),
                    ['reason' => $reason],
                    'cms'
                );
            }
        }

        try {
            if (\class_exists(FullPageCacheCoordinator::class)) {
                FullPageCacheCoordinator::clearProcessCache();
            }
        } catch (\Throwable $e) {
            $this->logCacheClearFailure('fpc_process_cache', $reason, $e);
        }

        try {
            $cacheManager = ObjectManager::getInstance(CacheManager::class);
            foreach (['fpc', 'router'] as $pool) {
                if (!\method_exists($cacheManager, 'hasPool') || !$cacheManager->hasPool($pool)) {
                    continue;
                }
                $cacheManager->pool($pool)->clear();
            }
        } catch (\Throwable $e) {
            $this->logCacheClearFailure('router_fpc_pools', $reason, $e);
        }

        try {
            $adapter = $this->runtimeProvider(WlsRuntimeAdapterInterface::class);
            if ($adapter instanceof WlsRuntimeAdapterInterface) {
                $facade = $adapter->createSharedState([
                    'consumer_code' => $reason,
                    'prefer_direct_connect' => true,
                    'pool_size' => 1,
                    'auto_start' => false,
                ]);
                $facade->clearCache('router');
                $facade->clearCache('fpc');
                $facade->clearNamespace('theme_runtime');
                $facade->disconnect();
            }
        } catch (\Throwable $e) {
            $this->logCacheClearFailure('wls_shared_memory', $reason, $e);
        }

        try {
            $broadcaster = $this->runtimeProvider(RuntimeControlBroadcasterInterface::class);
            if ($broadcaster instanceof RuntimeControlBroadcasterInterface) {
                $broadcaster->cacheClear();
            }
        } catch (\Throwable $e) {
            $this->logCacheClearFailure('wls_worker_broadcast', $reason, $e);
        }

        $this->purgeRouterFpcPayloadFiles($reason);
    }

    private function runtimeProvider(string $contract): ?object
    {
        try {
            return ObjectManager::getInstance(RuntimeProviderResolver::class)->resolve($contract);
        } catch (\Throwable) {
            return null;
        }
    }

    private function logCacheClearFailure(string $step, string $reason, \Throwable $e): void
    {
        if (!function_exists('w_log_warning')) {
            return;
        }

        w_log_warning(
            (string)__('CMS 清理前台缓存失败：%{1}', $e->getMessage()),
            [
                'step' => $step,
                'reason' => $reason,
            ],
            'cms'
        );
    }

    /**
     * @param array<string,mixed> $previousData
     */
    private function resolvePageChangeAction(Page $page, array $previousData, bool $isCreate): string
    {
        if ($page->isDeleted()) {
            return 'delete';
        }
        if (!$page->isPublished()) {
            return $page->getStatus() === Page::STATUS_DISABLED ? 'unpublish' : 'draft';
        }

        $previousStatus = (string)($previousData[Page::schema_fields_STATUS] ?? '');
        if ($previousStatus !== Page::STATUS_PUBLISHED) {
            return 'publish';
        }

        return $isCreate ? 'publish' : 'upsert';
    }

    /**
     * @param array<string,mixed> $previousData
     */
    private function dispatchPageChanged(Page $page, string $action, array $previousData = []): void
    {
        try {
            $previousUrl = '';
            if ($previousData !== []) {
                $previous = clone $this->pageModel;
                $previous->clearData()->setData($previousData);
                $previousUrl = $previous->getIdentifier() !== '' ? $this->buildPublicUrl($previous) : '';
            }

            $payload = [
                'page' => $this->toPageApiArray($page),
                'previous' => $previousData,
                'action' => $action,
                'seo_action' => $action,
                'url' => $this->buildPublicUrl($page),
                'previous_url' => $previousUrl,
                'website_id' => $page->getWebsiteId(),
                'website_code' => $page->getWebsiteCode(),
                'scope' => $page->getScope(),
                'module' => 'Weline_Cms',
                'subject_type' => Page::TARGET_TYPE,
                'subject_id' => $page->getPageId(),
                'published' => $page->isPublished() && !$page->isDeleted(),
                'deleted' => $page->isDeleted(),
            ];
            $payload['url_key'] = 'cms-page-' . $page->getPageId();
            $payload['targets'] = [[
                'website_id' => $page->getWebsiteId(),
                'website_code' => $page->getWebsiteCode(),
                'url' => $payload['url'],
                'previous_url' => $previousUrl,
                'url_key' => $payload['url_key'],
            ]];
            $payload['seo_result'] = $this->notifySeoUrlChanged($payload);

            $this->getEventsManager()->dispatch(
                $action === 'delete' ? 'Weline_Cms::page_delete_after' : 'Weline_Cms::page_save_after',
                $payload
            );
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning(
                    (string)__('CMS 页面 SEO 变更事件触发失败：%{1}', $e->getMessage()),
                    ['page_id' => $page->getPageId(), 'action' => $action],
                    'cms'
                );
            }
        }
    }

    /** @param array<string,mixed> $previousData */
    private function schedulePostCommitSideEffects(
        Page $page,
        string $action,
        array $previousData,
        string $reason,
    ): void {
        $pageData = $page->getData();
        $pageId = $page->getPageId();
        $transactions = ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        $transactions->afterCommit(
            $page->getConnection(),
            'cms_page_side_effects_' . $pageId . '_' . hash('sha256', $action . "\0" . $reason),
            function () use ($pageData, $previousData, $action, $reason): void {
                $committedPage = clone $this->pageModel;
                $committedPage->clearData()->setData($pageData);
                $this->clearThemeRuntimeCaches($reason);
                $this->dispatchPageChanged($committedPage, $action, $previousData);
            },
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function notifySeoUrlChanged(array $payload): array
    {
        if (!interface_exists(UrlChangeNotifierInterface::class)) {
            return ['skipped' => true, 'reason' => 'seo_module_missing'];
        }

        try {
            $service = ObjectManager::getInstance(UrlChangeNotifierInterface::class);
            if (!$service instanceof UrlChangeNotifierInterface) {
                return ['skipped' => true, 'reason' => 'seo_url_change_service_unavailable'];
            }

            return $service->notify($payload);
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning(
                    (string)__('CMS 页面 SEO URL 变更通知失败：%{1}', $e->getMessage()),
                    ['subject_id' => (int)($payload['subject_id'] ?? 0), 'action' => (string)($payload['action'] ?? '')],
                    'cms'
                );
            }

            return ['skipped' => true, 'reason' => 'seo_notify_failed', 'error' => $e->getMessage()];
        }
    }

    private function getEventsManager(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }

    private function purgeRouterFpcPayloadFiles(string $reason): void
    {
        $dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads';
        $base = \realpath(BP);
        $resolved = \realpath($dir);
        if ($base === false || $resolved === false || !\is_dir($resolved)) {
            return;
        }

        $baseNormalized = \strtolower(\rtrim(\str_replace('\\', '/', $base), '/') . '/');
        $dirNormalized = \strtolower(\rtrim(\str_replace('\\', '/', $resolved), '/') . '/');
        if ($dirNormalized !== $baseNormalized . 'var/cache/router-fpc-payloads/') {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @\rmdir($item->getPathname());
                    continue;
                }
                @\unlink($item->getPathname());
            }
        } catch (\Throwable $e) {
            $this->logCacheClearFailure('router_fpc_payload_files', $reason, $e);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{website_id:int,website_code:string,name:string,url:string}
     */
    private function resolveWebsiteForSave(array $data): array
    {
        $hasWebsiteId = array_key_exists('website_id', $data) || array_key_exists('site_id', $data);
        $websiteId = (int)($data['website_id'] ?? $data['site_id'] ?? 0);
        $websiteCode = trim((string)($data['website_code'] ?? $data['site'] ?? ''));

        if ($hasWebsiteId) {
            if ($websiteId < 0) {
                throw new \InvalidArgumentException((string)__('选择的站点不存在。'));
            }
            $website = $this->getWebsiteById($websiteId);
            if ($website === null) {
                throw new \InvalidArgumentException((string)__('选择的站点不存在。'));
            }
            if ($websiteCode !== '' && !hash_equals((string)$website['website_code'], $websiteCode)) {
                throw new \InvalidArgumentException((string)__('站点 ID 与站点代码不匹配。'));
            }
            return $website;
        }

        if ($websiteCode !== '') {
            $website = $this->getWebsiteByCode($websiteCode);
            if ($website !== null) {
                return $website;
            }
            throw new \InvalidArgumentException((string)__('选择的站点不存在。'));
        }

        $identity = RequestContext::scopeIdentity();
        if ($identity !== null && !$identity->isGlobal() && $identity->websiteId !== null) {
            $website = $this->getWebsiteById($identity->websiteId);
            if ($website !== null) {
                return $website;
            }
            throw new \InvalidArgumentException((string)__('当前请求的站点不存在。'));
        }

        $website = $this->getDefaultWebsite();
        if ($website !== null) {
            return $website;
        }

        return [
            'website_id' => 0,
            'website_code' => $websiteCode !== '' ? $websiteCode : 'default',
            'name' => $websiteCode !== '' ? $websiteCode : 'default',
            'url' => '',
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function resolveWebsiteIdForRead(array $params, bool $useCurrent = true): int
    {
        foreach (['website_id', 'site_id'] as $idKey) {
            if (!array_key_exists($idKey, $params) || $params[$idKey] === '' || $params[$idKey] === null) {
                continue;
            }
            $websiteId = filter_var($params[$idKey], FILTER_VALIDATE_INT);
            if ($websiteId === false || $websiteId < 0) {
                throw new \InvalidArgumentException((string)__('站点 ID 无效。'));
            }
            return $websiteId;
        }

        $websiteCode = trim((string)($params['website_code'] ?? $params['site'] ?? ''));
        if ($websiteCode !== '') {
            $website = $this->getWebsiteByCode($websiteCode);
            if ($website === null) {
                throw new \InvalidArgumentException((string)__('选择的站点不存在。'));
            }
            return (int)$website['website_id'];
        }

        return $useCurrent ? $this->resolveCurrentWebsiteId() : 0;
    }

    /** @param array<string,mixed> $params */
    private function hasWebsiteSelector(array $params): bool
    {
        foreach (['website_id', 'site_id'] as $idKey) {
            if (array_key_exists($idKey, $params) && $params[$idKey] !== '' && $params[$idKey] !== null) {
                return true;
            }
        }
        return trim((string)($params['website_code'] ?? $params['site'] ?? '')) !== '';
    }

    private function resolveCurrentWebsiteId(): int
    {
        $identity = RequestContext::scopeIdentity();
        if ($identity !== null && !$identity->isGlobal() && $identity->websiteId !== null) {
            return $identity->websiteId;
        }

        return 0;
    }

    /**
     * @return array{website_id:int,website_code:string,name:string,url:string}|null
     */
    private function getWebsiteById(int $websiteId): ?array
    {
        if ($websiteId < 0) {
            return null;
        }

        try {
            $website = w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($website) ? $this->normalizeWebsiteRow($website) : null;
    }

    /**
     * @return array{website_id:int,website_code:string,name:string,url:string}|null
     */
    private function getWebsiteByCode(string $websiteCode): ?array
    {
        $websiteCode = trim($websiteCode);
        if ($websiteCode === '') {
            return null;
        }

        foreach ($this->getWebsiteList() as $website) {
            if (strcasecmp((string)$website['website_code'], $websiteCode) === 0) {
                return $website;
            }
        }

        return null;
    }

    /**
     * @return array{website_id:int,website_code:string,name:string,url:string}|null
     */
    private function getDefaultWebsite(): ?array
    {
        $websites = $this->getWebsiteList();
        foreach ($websites as $website) {
            if (($website['website_code'] ?? '') === 'default') {
                return $website;
            }
        }

        return $websites[0] ?? null;
    }

    /**
     * @return list<array{website_id:int,website_code:string,name:string,url:string}>
     */
    private function getWebsiteList(): array
    {
        try {
            $rows = w_query('websites', 'getWebsiteList', []);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }

        $websites = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $website = $this->normalizeWebsiteRow($row);
            if ($website !== null) {
                $websites[] = $website;
            }
        }

        return $websites;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{website_id:int,website_code:string,name:string,url:string}|null
     */
    private function normalizeWebsiteRow(array $row): ?array
    {
        $websiteId = (int)($row['website_id'] ?? $row['id'] ?? 0);
        $websiteCode = trim((string)($row['website_code'] ?? $row['code'] ?? ''));
        if ($websiteId <= 0 && $websiteCode === '') {
            return null;
        }

        return [
            'website_id' => $websiteId,
            'website_code' => $websiteCode !== '' ? $websiteCode : 'default',
            'name' => trim((string)($row['name'] ?? $websiteCode)),
            'url' => trim((string)($row['url'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function resolvePathGroupInput(array $data): ?PathGroup
    {
        $groupId = (int)($data['path_group_id'] ?? $data['group_id'] ?? 0);
        if ($groupId <= 0) {
            return null;
        }

        $group = $this->getPathGroupModel($groupId, false);
        if ($group === null) {
            throw new \InvalidArgumentException((string)__('CMS 一级 path 不存在。'));
        }

        return $group;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{website_id:int,website_code:string,name:string,url:string}
     */
    private function resolveCopyTargetWebsite(array $data): array
    {
        $target = trim((string)($data['target_website'] ?? ''));
        $hasTargetWebsiteId = array_key_exists('target_website_id', $data)
            || array_key_exists('target_site_id', $data);
        $targetWebsiteId = (int)($data['target_website_id'] ?? $data['target_site_id'] ?? 0);
        $targetWebsiteCode = trim((string)($data['target_website_code'] ?? $data['target_site'] ?? ''));
        if ($target !== '') {
            if (str_contains($target, '|')) {
                [$idPart, $codePart] = array_pad(explode('|', $target, 2), 2, '');
                if ($idPart === '' || !ctype_digit($idPart)) {
                    throw new \InvalidArgumentException((string)__('目标站点 ID 无效。'));
                }
                $targetWebsiteId = (int)$idPart;
                $targetWebsiteCode = trim($codePart);
                $hasTargetWebsiteId = true;
            } elseif (ctype_digit($target)) {
                $targetWebsiteId = (int)$target;
                $hasTargetWebsiteId = true;
            } else {
                $targetWebsiteCode = $target;
            }
        }

        if (!$hasTargetWebsiteId && $targetWebsiteCode === '') {
            throw new \InvalidArgumentException((string)__('请选择目标站点。'));
        }

        $selector = [];
        if ($hasTargetWebsiteId) {
            $selector['website_id'] = $targetWebsiteId;
        }
        if ($targetWebsiteCode !== '') {
            $selector['website_code'] = $targetWebsiteCode;
        }
        return $this->resolveWebsiteForSave($selector);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function resolveCopySourcePathGroup(array $data): PathGroup
    {
        $groupId = (int)($data['group_id'] ?? $data['path_group_id'] ?? $data['id'] ?? 0);
        if ($groupId > 0) {
            $group = $this->getPathGroupModel($groupId, false);
            if ($group !== null) {
                return $group;
            }
        }

        $sourceWebsiteId = (int)($data['source_website_id'] ?? $data['website_id'] ?? 0);
        $pathGroup = trim((string)($data['source_path_group'] ?? $data['path_group'] ?? ''));
        if ($pathGroup !== '') {
            $group = $this->getPathGroupByPath($sourceWebsiteId, $pathGroup, false);
            if ($group !== null) {
                return $group;
            }
        }

        throw new \InvalidArgumentException((string)__('源 CMS 一级 path 不存在。'));
    }

    /**
     * @param array{website_id:int,website_code:string,name:string,url:string} $targetWebsite
     * @return array{page:Page,theme:array<string,mixed>}
     */
    private function copyPageToWebsite(Page $sourcePage, array $targetWebsite, ?PathGroup $targetGroup = null): array
    {
        $targetGroup = $targetGroup ?: $this->ensurePathGroup(
            $targetWebsite,
            $sourcePage->getPathGroup(),
            $sourcePage->getPathGroupAlias()
        );

        $targetPage = $this->savePage([
            'title' => $sourcePage->getTitle(),
            'path_group_id' => $targetGroup->getGroupId(),
            'website_id' => (int)$targetWebsite['website_id'],
            'website_code' => (string)$targetWebsite['website_code'],
            'path_group' => $targetGroup->getPathGroup(),
            'path_group_alias' => $targetGroup->getAlias(),
            'slug' => $sourcePage->getSlug(),
            'scope' => $sourcePage->getScope(),
            'status' => Page::STATUS_DRAFT,
        ]);

        $variantCopy = ($this->variantService ??= ObjectManager::getInstance(CmsPageVariantService::class))
            ->copyVariants($sourcePage, $targetPage, true);
        $themeSuccess = true;
        foreach ($variantCopy['theme_results'] as $themeResult) {
            if (is_array($themeResult) && empty($themeResult['success'])) {
                $themeSuccess = false;
                break;
            }
        }

        return [
            'page' => $targetPage,
            'theme' => [
                'success' => $themeSuccess,
                'status' => $themeSuccess ? 'copied' : 'partial_failed',
                'variant_results' => $variantCopy['theme_results'],
                'skipped' => $variantCopy['skipped'],
                'copied_variants' => $variantCopy['copied'],
            ],
        ];
    }

    /**
     * @return list<Page>
     */
    private function listPageModelsForPathGroup(PathGroup $group): array
    {
        $rows = (clone $this->pageModel)->clearData()->reset()
            ->where(Page::schema_fields_WEBSITE_ID, $group->getWebsiteId())
            ->where(Page::schema_fields_PATH_GROUP, $group->getPathGroup())
            ->where(Page::schema_fields_DELETED_AT, null, 'IS NULL')
            ->order(Page::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $pages = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $page = clone $this->pageModel;
            $page->clearData()->setData($row);
            if ($page->getPageId() > 0) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /**
     * @param array{website_id:int,website_code:string,name:string,url:string} $website
     */
    private function isSameWebsite(int $websiteId, string $websiteCode, array $website): bool
    {
        $targetId = (int)$website['website_id'];
        if ($websiteId >= 0 && $targetId >= 0) {
            return $websiteId === $targetId;
        }

        return strcasecmp($websiteCode, (string)$website['website_code']) === 0;
    }

    /**
     * @param array{website_id:int,website_code:string,name:string,url:string} $website
     */
    private function ensurePathGroup(array $website, string $pathGroup, string $alias): PathGroup
    {
        $existing = $this->getPathGroupByPath((int)$website['website_id'], $pathGroup, true);
        if ($existing !== null) {
            return $this->syncPathGroupAlias($existing, $alias);
        }

        return $this->savePathGroup([
            'website_id' => (int)$website['website_id'],
            'website_code' => (string)$website['website_code'],
            'path_group' => $pathGroup,
            'path_group_alias' => $alias,
        ]);
    }

    private function syncPathGroupAlias(PathGroup $group, string $alias): PathGroup
    {
        $alias = $this->normalizePathGroupAlias($alias, $group->getPathGroup());
        if ($group->getAlias() === $alias && !$group->isDeleted()) {
            return $group;
        }

        $group->setData(PathGroup::schema_fields_ALIAS, $alias);
        $group->setData(PathGroup::schema_fields_DELETED_AT, null);
        $group->save();
        $this->syncPageGroupAlias($group->getWebsiteId(), $group->getPathGroup(), $alias);

        return $group;
    }

    private function syncPageGroupAlias(int $websiteId, string $pathGroup, string $alias): void
    {
        $model = clone $this->pageModel;
        $rows = $model->clearData()->reset()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_PATH_GROUP, $pathGroup)
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $page = clone $this->pageModel;
            $page->clearData()->setData($row);
            if ($page->getPageId() <= 0 || $page->getPathGroupAlias() === $alias) {
                continue;
            }
            $page->setData(Page::schema_fields_PATH_GROUP_ALIAS, $alias);
            $page->save();
        }
    }

    private function normalizeLayoutOption(string $layoutOption): string
    {
        $layoutOption = trim(str_replace('\\', '/', $layoutOption), '/ ');
        if ($layoutOption === '') {
            return 'default';
        }
        if (!preg_match('#^[a-zA-Z0-9][a-zA-Z0-9/_-]*$#', $layoutOption) || str_contains($layoutOption, '..')) {
            throw new \InvalidArgumentException((string)__('布局选项格式无效。'));
        }

        return $layoutOption;
    }

    /**
     * @return array<string,mixed>
     */
    private function toPageApiArray(Page $page): array
    {
        return array_merge($page->toApiArray(), [
            'public_url' => $this->buildPublicUrl($page),
            // Preview credentials are created only by an explicit backend
            // preview/editor action. Generic DTOs and frontend payloads must
            // never mint or expose them as a side effect of reading a page.
            'preview_url' => '',
        ]);
    }

    /**
     * @return array{success?:bool,token?:string,token_key?:string,context?:array<string,mixed>}
     */
    private function generatePreviewTokenForPage(
        Page $page,
        string $previewVersion,
        \Weline\Cms\Api\Data\CmsEditorContext $cmsContext,
    ): array
    {
        try {
            $layoutSelection = $this->resolveLayoutSelectionForPage(
                $page,
                $cmsContext->storeId,
                $cmsContext->localeCode,
            );
            $layoutOption = (string)($layoutSelection['layout_option'] ?? 'default');

            $result = w_query('theme', 'generatePreviewToken', [
                'page_type' => Page::LAYOUT_TYPE,
                'layout_type' => Page::LAYOUT_TYPE,
                'layout_option' => $layoutOption !== '' ? $layoutOption : 'default',
                'theme_layout_target_type' => Page::TARGET_TYPE,
                'theme_layout_target_id' => $page->getPageId(),
                'theme_layout_source_target_type' => Page::TARGET_TYPE,
                'theme_layout_source_target_id' => $page->getPageId(),
                'scope' => $cmsContext->canonicalScope,
                'store_mode' => $cmsContext->storeMode,
                'locale' => $cmsContext->localeCode,
                'status' => 'draft',
                'preview_version' => $this->normalizePreviewVersion($previewVersion),
                'target_value' => Page::TARGET_TYPE . ':' . $page->getPageId(),
                'context' => [
                    'cms_page_id' => $page->getPageId(),
                    'cms_identifier' => $page->getIdentifier(),
                    'cms_website_id' => $page->getWebsiteId(),
                    'cms_website_code' => $page->getWebsiteCode(),
                    'cms_store_id' => $cmsContext->storeId,
                    'cms_store_code' => $cmsContext->storeCode,
                    'cms_store_mode' => $cmsContext->storeMode,
                    'cms_locale_code' => $cmsContext->localeCode,
                    'cms_canonical_scope' => $cmsContext->canonicalScope,
                    'cms_path_group' => $page->getPathGroup(),
                    'cms_slug' => $page->getSlug(),
                    'scope' => $cmsContext->canonicalScope,
                    'store_mode' => $cmsContext->storeMode,
                    'locale' => $cmsContext->localeCode,
                ],
            ]);
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            if (function_exists('w_log_warning')) {
                w_log_warning(
                    (string)__('CMS 生成预览 token 失败：%{1}', $e->getMessage()),
                    ['page_id' => $page->getPageId()],
                    'cms'
                );
            }
            return [];
        }
    }

    private function normalizePreviewVersion(string $previewVersion): string
    {
        $previewVersion = strtolower(trim($previewVersion));
        if ($previewVersion === '') {
            return self::DEFAULT_PREVIEW_VERSION;
        }

        return preg_match('#^[a-z0-9_-]{1,48}$#', $previewVersion)
            ? $previewVersion
            : self::DEFAULT_PREVIEW_VERSION;
    }

    private function normalizeRawPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if (str_contains($path, '://')) {
            $path = (string)(parse_url($path, PHP_URL_PATH) ?: '');
        }
        if (str_contains($path, '?')) {
            $path = explode('?', $path, 2)[0];
        }
        $path = strtolower(trim(rawurldecode($path), '/ '));

        return preg_replace('#/+#', '/', $path) ?? $path;
    }

    private function normalizePathSegment(string $path, bool $allowEmpty): string
    {
        $path = $this->normalizeRawPath($path);
        if ($path === '') {
            if ($allowEmpty) {
                return '';
            }
            throw new \InvalidArgumentException((string)__('路径不能为空。'));
        }
        if (str_contains($path, '..') || !preg_match('#^[a-z0-9][a-z0-9/_-]*$#', $path)) {
            throw new \InvalidArgumentException((string)__('页面路径只能包含字母、数字、斜杠、中划线和下划线。'));
        }

        return $path;
    }

    /**
     * @return array{path_group:string,slug:string,identifier:string}
     */
    private function splitIdentifierToPathParts(string $identifier): array
    {
        $identifier = $this->normalizeIdentifier($identifier);
        $segments = explode('/', $identifier);
        $pathGroup = $this->normalizePathGroup((string)array_shift($segments));
        $slug = $this->normalizeSlug(implode('/', $segments));

        return [
            'path_group' => $pathGroup,
            'slug' => $slug,
            'identifier' => $this->composeIdentifier($pathGroup, $slug),
        ];
    }

    private function composeIdentifier(string $pathGroup, string $slug): string
    {
        $identifier = $pathGroup . ($slug !== '' ? '/' . $slug : '');
        if (strlen($identifier) > 190) {
            throw new \InvalidArgumentException((string)__('页面完整路径不能超过 190 个字符。'));
        }

        return $this->normalizeIdentifier($identifier);
    }

    private function assertUniqueIdentifier(string $identifier, int $websiteId, int $currentPageId): void
    {
        $model = clone $this->pageModel;
        $query = $model->clearData()->reset()
            ->where(Page::schema_fields_IDENTIFIER, $identifier)
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId);
        if ($currentPageId > 0) {
            $query->where(Page::schema_fields_ID, $currentPageId, '!=');
        }
        $row = $query->find()->fetchArray();
        if (!empty($row)) {
            throw new \InvalidArgumentException((string)__('同一站点下已存在相同路径的 CMS 页面。'));
        }
    }

    private function assertRouteAvailableForPublish(string $identifier): void
    {
        if ($this->isReservedRoutePrefix($identifier) || $this->generatedFrontendRouteExists($identifier)) {
            throw new \InvalidArgumentException((string)__('页面路径与已有系统或模块路由冲突，不能发布。'));
        }
    }

    private function isReservedRoutePrefix(string $identifier): bool
    {
        foreach (self::RESERVED_ROUTE_PREFIXES as $prefix) {
            if ($identifier === $prefix || str_starts_with($identifier, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function generatedFrontendRouteExists(string $identifier): bool
    {
        if (!is_file(Env::path_FRONTEND_PC_ROUTER_FILE)) {
            return false;
        }

        try {
            $routes = include Env::path_FRONTEND_PC_ROUTER_FILE;
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($routes)) {
            return false;
        }

        $routePath = trim($identifier, '/');
        return isset($routes[$routePath]) || isset($routes[$routePath . '::GET']);
    }

    /**
     * Url::getFrontendUrl($path) currently normalizes repeated slashes after
     * composing the URL, which can collapse "http://host/path" into a bad host
     * when $path is a plain frontend route. Build CMS frontend links from the
     * known base URL until the framework URL normalizer is fixed centrally.
     *
     * @param array<string,mixed> $params
     */
    private function buildFrontendPathUrl(
        string $path,
        array $params = [],
        ?Page $page = null,
        ?string $baseOverride = null,
    ): string
    {
        $base = $baseOverride !== null ? rtrim($baseOverride, '/') : $this->resolveFrontendBaseUrl($page);
        $path = trim($path, '/');
        $url = $base . ($path !== '' ? '/' . $path : '/');
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }

        return $url;
    }

    private function resolveStorePreviewBaseUrl(Page $page, int $storeId): ?string
    {
        $catalog = $this->storeCatalog ??= ObjectManager::getInstance(StoreCatalogInterface::class);
        $store = $catalog->byId($storeId);
        if (!$store instanceof StoreSummary || $store->websiteId !== $page->getWebsiteId()) {
            throw new \RuntimeException((string)__('CMS 预览店铺与页面网站不匹配。'));
        }

        $url = trim((string)$store->url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string)($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1
        ) {
            throw new \RuntimeException((string)__('CMS 预览店铺入口 URL 无效。'));
        }

        return rtrim($url, '/');
    }

    private function resolveFrontendBaseUrl(?Page $page = null): string
    {
        $requestBase = $this->resolveRequestBaseUrl();
        if ($page !== null) {
            $website = $this->getWebsiteById($page->getWebsiteId());
            $siteUrl = trim((string)($website['url'] ?? ''));
            if (preg_match('~^https?://[^/]+(?:/[^?#]*)?~i', $siteUrl, $matches)) {
                $siteBase = rtrim($matches[0], '/');
                if ($requestBase === '' || !$this->sameHostBase($requestBase, $siteBase)) {
                    return $siteBase;
                }
            }
        }

        if ($requestBase !== '') {
            return $requestBase;
        }

        $candidates = [
            (string)$this->url->getFrontendUrl(''),
            (string)$this->url->getFrontendUrl('/'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if (preg_match('#^https?://[^/]+#i', $candidate, $matches)) {
                return rtrim($matches[0], '/');
            }
        }

        return 'http://localhost';
    }

    private function sameHostBase(string $left, string $right): bool
    {
        $leftHost = strtolower((string)(parse_url($left, PHP_URL_HOST) ?: ''));
        $rightHost = strtolower((string)(parse_url($right, PHP_URL_HOST) ?: ''));
        if ($leftHost === '' || $rightHost === '' || $leftHost !== $rightHost) {
            return false;
        }

        $leftPort = (int)(parse_url($left, PHP_URL_PORT) ?: ($this->isHttpsUrl($left) ? 443 : 80));
        $rightPort = (int)(parse_url($right, PHP_URL_PORT) ?: ($this->isHttpsUrl($right) ? 443 : 80));

        return $leftPort === $rightPort;
    }

    private function isHttpsUrl(string $url): bool
    {
        return strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: '')) === 'https';
    }

    private function resolveRequestBaseUrl(): string
    {
        $baseHost = trim((string)$this->request->getBaseHost());
        if (preg_match('#^https?://[^/]+#i', $baseHost, $matches)) {
            return rtrim($matches[0], '/');
        }

        return '';
    }
}
