<?php
declare(strict_types=1);

namespace Weline\Cms\Controller\Backend;

use Weline\BackendActivity\Api\BusinessContextInterface;
use Weline\Cms\Model\Page as CmsPage;
use Weline\Cms\Service\PageService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;

#[Acl('Weline_Cms::page', 'CMS 页面', 'mdi mdi-text-box-outline', '管理 CMS 页面', 'Weline_Backend::cms_group')]
class Page extends BackendController
{
    public function __construct(
        private readonly PageService $pageService,
        private readonly BusinessContextInterface $activityContext
    ) {
    }

    #[Acl('Weline_Cms::page_listing', 'CMS 页面列表', 'mdi mdi-format-list-bulleted', '查看 CMS 页面列表')]
    public function getListing(): string
    {
        $params = $this->request->getParams();
        $result = $this->pageService->listPages([
            'status' => $params['status'] ?? '',
            'scope' => $params['scope'] ?? '',
            'website_id' => $params['website_id'] ?? 0,
            'path_group' => $params['path_group'] ?? '',
            'search' => $params['search'] ?? '',
            'page' => $params['page'] ?? 1,
            'page_size' => $params['page_size'] ?? 20,
        ]);
        $websites = $this->loadWebsiteOptions();
        $pathGroups = $this->pageService->listPathGroups([
            'website_id' => $params['website_id'] ?? 0,
            'path_group' => $params['path_group'] ?? '',
        ]);

        $this->assign('pages', $result['items']);
        $this->assign('path_groups', $pathGroups);
        $this->assign('site_groups', $this->groupSitesForListing(
            $result['items'],
            $pathGroups,
            $websites,
            (string)($params['website_id'] ?? '')
        ));
        $this->assign('pagination', $result['pagination']);
        $this->assign('websites', $websites);
        $this->assign('search', (string)($params['search'] ?? ''));
        $this->assign('status', (string)($params['status'] ?? ''));
        $this->assign('scope', (string)($params['scope'] ?? ''));
        $this->assign('website_id', (string)($params['website_id'] ?? ''));
        $this->assign('path_group', (string)($params['path_group'] ?? ''));
        $this->assign('copy_notice', $this->buildCopyNotice($params));
        $this->assign('copy_highlight', $this->buildCopyHighlight($params));

        return $this->fetch('listing');
    }

    #[Acl('Weline_Cms::page_new', '新建 CMS 页面', 'mdi mdi-plus-box-outline', '新建 CMS 页面')]
    public function getNew(): string
    {
        try {
            $page = $this->pageService->createDraftPage(
                (string)$this->request->getGet('scope', 'default'),
                (string)$this->request->getGet('layout_option', 'default'),
                [
                    'website_id' => (int)$this->request->getGet('website_id', 0),
                    'website_code' => (string)$this->request->getGet('website_code', ''),
                    'path_group_id' => (int)$this->request->getGet('path_group_id', $this->request->getGet('group_id', 0)),
                    'path_group' => (string)$this->request->getGet('path_group', ''),
                    'path_group_alias' => (string)$this->request->getGet('path_group_alias', ''),
                ]
            );
            $this->markActivity('cms_page', $page->getPageId(), 'create_draft', $page->getTitle(), [
                'identifier' => $page->getIdentifier(),
                'website_id' => $page->getWebsiteId(),
                'website_code' => $page->getWebsiteCode(),
                'path_group' => $page->getPathGroup(),
                'slug' => $page->getSlug(),
                'scope' => $page->getScope(),
            ]);
            $this->getMessageManager()->addSuccess(__('CMS 草稿页面已创建，可以开始可视化编辑。'));
            return $this->redirect('cms/backend/page/edit', ['page_id' => $page->getPageId()]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            return $this->redirect('cms/backend/page/listing');
        }
    }

    #[Acl('Weline_Cms::page_edit', '编辑 CMS 页面', 'mdi mdi-file-edit-outline', '编辑 CMS 页面')]
    public function getEdit(): string
    {
        $pageId = (int)$this->request->getGet('page_id', $this->request->getGet('id', 0));
        $page = $pageId > 0 ? $this->pageService->getPageModel($pageId, true) : null;
        if ($pageId > 0 && $page === null) {
            $this->getMessageManager()->addError(__('CMS 页面不存在。'));
            return $this->redirect('cms/backend/page/listing');
        }

        $scope = $page ? $page->getScope() : 'default';
        $layoutSelection = $page
            ? $this->pageService->resolveLayoutSelectionForPage($page)
            : ['layout_type' => CmsPage::LAYOUT_TYPE, 'layout_option' => 'default', 'layout_code' => 'default'];

        $layoutOptions = $this->loadLayoutOptions($scope);
        $themeEditorUrl = '';
        $previewUrl = '';
        if ($page !== null && $page->getPageId() > 0) {
            $themeEditorUrl = $this->buildThemeEditorUrl($page, (string)$layoutSelection['layout_option']);
            $previewUrl = $this->pageService->buildPreviewUrl($page);
        }

        $websites = $this->loadWebsiteOptions();
        $this->assign('page', $page ? $page->toApiArray() : [
            'page_id' => 0,
            'website_id' => 0,
            'website_code' => 'default',
            'site' => 'default',
            'path_group' => '',
            'path_group_alias' => '',
            'path_group_label' => '',
            'slug' => '',
            'identifier' => '',
            'path' => '',
            'title' => '',
            'status' => CmsPage::STATUS_DRAFT,
            'scope' => 'default',
            'deleted_at' => '',
        ]);
        $this->assign('statuses', [
            CmsPage::STATUS_DRAFT => __('草稿'),
            CmsPage::STATUS_PUBLISHED => __('已发布'),
            CmsPage::STATUS_DISABLED => __('已禁用'),
        ]);
        $this->assign('websites', $websites);
        $this->assign('layout_options', $layoutOptions);
        $this->assign('layout_selection', $layoutSelection);
        $this->assign('theme_editor_url', $themeEditorUrl);
        $this->assign('preview_url', $previewUrl);

        return $this->fetch('edit');
    }

    #[Acl('Weline_Cms::page_save', '保存 CMS 页面', 'mdi mdi-content-save-outline', '保存 CMS 页面')]
    public function postSave(): string
    {
        $data = $this->collectRequestData();

        try {
            $isCreate = (int)($data['page_id'] ?? 0) <= 0;
            $page = $this->pageService->savePage($data);
            $layoutOption = trim((string)($data['layout_option'] ?? ''));
            $layoutResult = [];
            if ($layoutOption !== '') {
                $layoutResult = $this->pageService->saveLayoutSelection(
                    $page->getPageId(),
                    $layoutOption,
                    $page->getScope()
                );
                if (empty($layoutResult['success'])) {
                    $this->getMessageManager()->addWarning(
                        (string)($layoutResult['message'] ?? __('页面已保存，但布局选择保存失败。'))
                    );
                }
            }

            $this->markActivity('cms_page', $page->getPageId(), $isCreate ? 'create' : 'save', $page->getTitle(), [
                'identifier' => $page->getIdentifier(),
                'status' => $page->getStatus(),
                'scope' => $page->getScope(),
                'website_id' => $page->getWebsiteId(),
                'website_code' => $page->getWebsiteCode(),
                'path_group' => $page->getPathGroup(),
                'path_group_alias' => $page->getPathGroupAlias(),
                'slug' => $page->getSlug(),
                'layout_option' => $layoutOption,
                'layout_success' => $layoutResult === [] ? null : !empty($layoutResult['success']),
            ]);
            $this->getMessageManager()->addSuccess(__('CMS 页面已保存。'));
            return $this->redirect('cms/backend/page/edit', ['page_id' => $page->getPageId()]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $pageId = (int)($data['page_id'] ?? 0);
            $params = $pageId > 0 ? ['page_id' => $pageId] : [];
            return $this->redirect('cms/backend/page/edit', $params);
        }
    }

    #[Acl('Weline_Cms::path_group_save', '保存 CMS 一级 path', 'mdi mdi-folder-edit-outline', '保存 CMS 一级 path')]
    public function postSavePathGroup(): string
    {
        $data = $this->collectRequestData();

        try {
            $group = $this->pageService->savePathGroup($data);
            $this->markActivity('cms_path_group', $group->getGroupId(), 'save', $group->getAlias() !== '' ? $group->getAlias() : $group->getPathGroup(), [
                'website_id' => $group->getWebsiteId(),
                'website_code' => $group->getWebsiteCode(),
                'path_group' => $group->getPathGroup(),
                'alias' => $group->getAlias(),
            ]);
            $this->getMessageManager()->addSuccess(__('CMS 一级 path 已保存。'));
            return $this->redirect('cms/backend/page/listing', [
                'website_id' => $group->getWebsiteId(),
                'path_group' => $group->getPathGroup(),
            ]);
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $params = [];
            $websiteId = (int)($data['website_id'] ?? 0);
            if ($websiteId > 0) {
                $params['website_id'] = $websiteId;
            }
            return $this->redirect('cms/backend/page/listing', $params);
        }
    }

    #[Acl('Weline_Cms::page_copy', '拷贝 CMS 页面', 'mdi mdi-content-copy', '将 CMS 页面拷贝到目标站点')]
    public function postCopy(): string
    {
        $data = $this->collectRequestData();
        $sourcePageId = (int)($data['page_id'] ?? $data['id'] ?? 0);
        $sourcePage = $sourcePageId > 0 ? $this->pageService->getPageModel($sourcePageId, false) : null;
        $sourceListingParams = $this->buildSourceListingParams($data, $sourcePage);

        try {
            $result = $this->pageService->copyPage($sourcePageId, $data);
            $page = $result['page'];
            $themeResult = is_array($result['theme'] ?? null) ? $result['theme'] : [];
            $this->markActivity('cms_page', $page->getPageId(), 'copy', $page->getTitle(), [
                'source_page_id' => $sourcePageId,
                'target_page_id' => $page->getPageId(),
                'identifier' => $page->getIdentifier(),
                'website_id' => $page->getWebsiteId(),
                'website_code' => $page->getWebsiteCode(),
                'path_group' => $page->getPathGroup(),
                'slug' => $page->getSlug(),
                'theme_copy_success' => !empty($themeResult['success']),
            ]);
            if (empty($themeResult['success'])) {
                $this->getMessageManager()->addWarning(__('CMS 页面已拷贝，但 Theme 布局数据复制不完整。'));
            }

            $targetWebsite = is_array($result['target_website'] ?? null)
                ? $result['target_website']
                : $this->buildTargetWebsiteFromPage($page);
            $noticeParams = $this->buildCopyNoticeRedirectParams('page', $targetWebsite, [
                'path_group' => $page->getPathGroup(),
                'page_id' => $page->getPageId(),
                'title' => $page->getTitle(),
                'identifier' => $page->getIdentifier(),
                'theme_partial' => empty($themeResult['success']),
            ]);

            return $this->redirect('cms/backend/page/listing', array_merge($sourceListingParams, $noticeParams));
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            return $this->redirect('cms/backend/page/listing', $sourceListingParams);
        }
    }

    #[Acl('Weline_Cms::path_group_copy', '拷贝 CMS 一级 path', 'mdi mdi-content-copy', '将 CMS 一级 path 及页面拷贝到目标站点')]
    public function postCopyPathGroup(): string
    {
        $data = $this->collectRequestData();
        $sourceListingParams = $this->buildSourceListingParams($data);

        try {
            $result = $this->pageService->copyPathGroup($data);
            $group = $result['path_group'];
            $themeResults = is_array($result['theme_results'] ?? null) ? $result['theme_results'] : [];
            $themeCopySuccess = true;
            foreach ($themeResults as $themeResult) {
                if (is_array($themeResult) && empty($themeResult['success'])) {
                    $themeCopySuccess = false;
                    break;
                }
            }
            $this->markActivity('cms_path_group', $group->getGroupId(), 'copy', $group->getAlias() !== '' ? $group->getAlias() : $group->getPathGroup(), [
                'source_group_id' => (int)($data['group_id'] ?? $data['path_group_id'] ?? 0),
                'target_group_id' => $group->getGroupId(),
                'website_id' => $group->getWebsiteId(),
                'website_code' => $group->getWebsiteCode(),
                'path_group' => $group->getPathGroup(),
                'alias' => $group->getAlias(),
                'copied_page_count' => count((array)($result['pages'] ?? [])),
                'theme_copy_success' => $themeCopySuccess,
            ]);
            if (!$themeCopySuccess) {
                $this->getMessageManager()->addWarning(__('CMS 一级 path 已拷贝到目标站点，但部分 Theme 布局数据复制不完整。'));
            }

            $targetWebsite = is_array($result['target_website'] ?? null)
                ? $result['target_website']
                : [
                    'website_id' => $group->getWebsiteId(),
                    'website_code' => $group->getWebsiteCode(),
                    'name' => $group->getWebsiteCode(),
                    'url' => '',
                ];
            $noticeParams = $this->buildCopyNoticeRedirectParams('path', $targetWebsite, [
                'path_group' => $group->getPathGroup(),
                'page_count' => count((array)($result['pages'] ?? [])),
                'theme_partial' => !$themeCopySuccess,
            ]);

            return $this->redirect('cms/backend/page/listing', array_merge($sourceListingParams, $noticeParams));
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            return $this->redirect('cms/backend/page/listing', $sourceListingParams);
        }
    }

    #[Acl('Weline_Cms::page_delete', 'CMS 页面移入回收站', 'mdi mdi-delete-outline', '将 CMS 页面移入回收站')]
    public function postDelete(): string
    {
        $data = $this->collectRequestData();
        $pageId = (int)($data['page_id'] ?? $data['id'] ?? 0);
        try {
            $result = w_query('trash', 'delete', [
                'code' => 'weline_cms.page',
                'data' => ['page_id' => $pageId],
                'context' => ['source' => 'cms_backend'],
            ]);
            if (is_array($result) && !empty($result['success'])) {
                $this->getMessageManager()->addSuccess((string)($result['message'] ?? __('CMS 页面已移入回收站。')));
            } else {
                $this->getMessageManager()->addError((string)($result['message'] ?? __('CMS 页面移入回收站失败。')));
            }
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('cms/backend/page/listing');
    }

    #[Acl('Weline_Cms::path_group_delete', 'CMS 一级 path 移入回收站', 'mdi mdi-folder-remove-outline', '将 CMS 一级 path 移入回收站')]
    public function postDeletePathGroup(): string
    {
        $data = $this->collectRequestData();
        $groupId = (int)($data['group_id'] ?? $data['path_group_id'] ?? $data['id'] ?? 0);
        try {
            $result = w_query('trash', 'delete', [
                'code' => 'weline_cms.path_group',
                'data' => [
                    'group_id' => $groupId,
                    'website_id' => (int)($data['website_id'] ?? 0),
                    'path_group' => (string)($data['path_group'] ?? ''),
                ],
                'context' => ['source' => 'cms_backend'],
            ]);
            if (is_array($result) && !empty($result['success'])) {
                $this->getMessageManager()->addSuccess((string)($result['message'] ?? __('CMS 一级 path 已移入回收站。')));
            } else {
                $this->getMessageManager()->addError((string)($result['message'] ?? __('CMS 一级 path 移入回收站失败。')));
            }
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('cms/backend/page/listing');
    }

    #[Acl('Weline_Cms::page_preview', '预览 CMS 页面', 'mdi mdi-eye-outline', '预览 CMS 页面')]
    public function getPreview(): string
    {
        $pageId = (int)$this->request->getGet('page_id', $this->request->getGet('id', 0));
        $page = $this->pageService->getPageModel($pageId, true);
        if ($page === null) {
            $this->getMessageManager()->addError(__('CMS 页面不存在。'));
            return $this->redirect('cms/backend/page/listing');
        }

        $previewUrl = $this->pageService->buildPreviewUrl($page);
        $this->markActivity('cms_page', $page->getPageId(), 'preview', $page->getTitle(), [
            'identifier' => $page->getIdentifier(),
            'status' => $page->getStatus(),
            'scope' => $page->getScope(),
            'website_id' => $page->getWebsiteId(),
            'website_code' => $page->getWebsiteCode(),
            'path_group' => $page->getPathGroup(),
            'slug' => $page->getSlug(),
            'preview_url' => $previewUrl,
        ]);

        return $this->redirect($previewUrl);
    }

    /**
     * @return list<array{value:string,label:string,description:string,file:string}>
     */
    private function loadLayoutOptions(string $scope): array
    {
        try {
            $options = w_query('theme', 'scanThemeLayoutsByType', [
                'layout_type' => CmsPage::LAYOUT_TYPE,
                'area' => 'frontend',
                'scope' => $scope,
            ]);
        } catch (\Throwable) {
            $options = [];
        }

        if (!is_array($options) || empty($options)) {
            return [[
                'value' => 'default',
                'label' => 'default',
                'description' => '',
                'file' => '',
            ]];
        }

        $normalized = [];
        foreach ($options as $value => $option) {
            if (is_array($option)) {
                $layoutValue = (string)($option['value'] ?? $value);
                $normalized[] = [
                    'value' => $layoutValue,
                    'label' => (string)($option['label'] ?? $layoutValue),
                    'description' => (string)($option['description'] ?? ''),
                    'file' => (string)($option['file'] ?? ''),
                ];
                continue;
            }
            $layoutValue = is_string($value) ? $value : (string)$option;
            $normalized[] = [
                'value' => $layoutValue,
                'label' => $layoutValue,
                'description' => '',
                'file' => '',
            ];
        }

        return $normalized ?: [[
            'value' => 'default',
            'label' => 'default',
            'description' => '',
            'file' => '',
        ]];
    }

    /**
     * @return list<array{website_id:int,code:string,name:string,url:string,label:string}>
     */
    private function loadWebsiteOptions(): array
    {
        try {
            $rows = w_query('websites', 'getWebsiteList', []);
        } catch (\Throwable) {
            $rows = [];
        }
        if (!is_array($rows)) {
            $rows = [];
        }

        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $websiteId = (int)($row['website_id'] ?? $row['id'] ?? 0);
            $code = trim((string)($row['code'] ?? $row['website_code'] ?? ''));
            if ($websiteId <= 0 && $code === '') {
                continue;
            }
            $name = trim((string)($row['name'] ?? $code));
            $url = trim((string)($row['url'] ?? ''));
            $options[] = [
                'website_id' => $websiteId,
                'code' => $code !== '' ? $code : 'default',
                'name' => $name !== '' ? $name : ($code !== '' ? $code : 'default'),
                'url' => $url,
                'label' => ($name !== '' ? $name : ($code !== '' ? $code : 'default')) . ' / ' . ($code !== '' ? $code : 'default'),
            ];
        }

        return $options ?: [[
            'website_id' => 0,
            'code' => 'default',
            'name' => 'default',
            'url' => '',
            'label' => 'default',
        ]];
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @param list<array<string,mixed>> $pathGroups
     * @param list<array<string,mixed>> $websites
     * @return list<array<string,mixed>>
     */
    private function groupSitesForListing(array $pages, array $pathGroups, array $websites, string $websiteIdFilter = ''): array
    {
        $sites = [];
        foreach ($websites as $website) {
            if (!is_array($website)) {
                continue;
            }
            $websiteId = (int)($website['website_id'] ?? 0);
            if ($websiteIdFilter !== '' && $websiteId !== (int)$websiteIdFilter) {
                continue;
            }
            $websiteCode = (string)($website['code'] ?? $website['website_code'] ?? 'default');
            $name = (string)($website['name'] ?? $websiteCode);
            $key = $this->buildSiteKey($websiteId, $websiteCode);
            $sites[$key] = [
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'name' => $name !== '' ? $name : $websiteCode,
                'url' => (string)($website['url'] ?? ''),
                'label' => (string)($website['label'] ?? (($name !== '' ? $name : $websiteCode) . ' / ' . $websiteCode)),
                'page_count' => 0,
                'path_count' => 0,
                'path_groups' => [],
            ];
        }

        foreach ($pathGroups as $pathGroup) {
            if (!is_array($pathGroup)) {
                continue;
            }
            $websiteId = (int)($pathGroup['website_id'] ?? 0);
            $websiteCode = (string)($pathGroup['website_code'] ?? 'default');
            if ($websiteIdFilter !== '' && $websiteId !== (int)$websiteIdFilter) {
                continue;
            }
            $siteKey = $this->buildSiteKey($websiteId, $websiteCode);
            if (!isset($sites[$siteKey])) {
                $sites[$siteKey] = $this->buildFallbackSite($websiteId, $websiteCode);
            }
            $groupPath = (string)($pathGroup['path_group'] ?? '');
            if ($groupPath === '') {
                continue;
            }
            $alias = (string)($pathGroup['path_group_alias'] ?? $pathGroup['alias'] ?? '');
            $groupKey = $this->buildPathGroupKey($groupPath);
            $sites[$siteKey]['path_groups'][$groupKey] = [
                'group_id' => (int)($pathGroup['group_id'] ?? 0),
                'website_id' => $websiteId,
                'website_code' => $websiteCode,
                'path_group' => $groupPath,
                'path_group_alias' => $alias !== '' ? $alias : $groupPath,
                'label' => ($alias !== '' ? $alias : $groupPath) . ' / ' . $groupPath,
                'pages' => [],
            ];
            $sites[$siteKey]['path_count'] = count($sites[$siteKey]['path_groups']);
        }

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $websiteId = (int)($page['website_id'] ?? 0);
            $websiteCode = (string)($page['website_code'] ?? 'default');
            if ($websiteIdFilter !== '' && $websiteId !== (int)$websiteIdFilter) {
                continue;
            }
            $siteKey = $this->buildSiteKey($websiteId, $websiteCode);
            if (!isset($sites[$siteKey])) {
                $sites[$siteKey] = $this->buildFallbackSite($websiteId, $websiteCode);
            }
            $pathGroup = (string)($page['path_group'] ?? '');
            $alias = (string)($page['path_group_alias'] ?? '');
            $groupKey = $this->buildPathGroupKey($pathGroup);
            if (!isset($sites[$siteKey]['path_groups'][$groupKey])) {
                $sites[$siteKey]['path_groups'][$groupKey] = [
                    'group_id' => 0,
                    'website_id' => $websiteId,
                    'website_code' => $websiteCode,
                    'path_group' => $pathGroup,
                    'path_group_alias' => $alias !== '' ? $alias : $pathGroup,
                    'label' => ($alias !== '' ? $alias : $pathGroup) . ' / ' . $pathGroup,
                    'pages' => [],
                ];
            }
            $sites[$siteKey]['path_groups'][$groupKey]['pages'][] = $page;
            $sites[$siteKey]['page_count']++;
            $sites[$siteKey]['path_count'] = count($sites[$siteKey]['path_groups']);
        }

        foreach ($sites as &$site) {
            $site['path_groups'] = array_values($site['path_groups']);
        }
        unset($site);

        return array_values($sites);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildFallbackSite(int $websiteId, string $websiteCode): array
    {
        $websiteCode = $websiteCode !== '' ? $websiteCode : 'default';

        return [
            'website_id' => $websiteId,
            'website_code' => $websiteCode,
            'name' => $websiteCode,
            'url' => '',
            'label' => $websiteCode,
            'page_count' => 0,
            'path_count' => 0,
            'path_groups' => [],
        ];
    }

    private function buildSiteKey(int $websiteId, string $websiteCode): string
    {
        return $websiteId . '|' . ($websiteCode !== '' ? $websiteCode : 'default');
    }

    private function buildPathGroupKey(string $pathGroup): string
    {
        return $pathGroup !== '' ? $pathGroup : '__root__';
    }

    private function buildThemeEditorUrl(CmsPage $page, string $layoutOption): string
    {
        $layoutOption = trim($layoutOption) !== '' ? trim($layoutOption) : 'default';

        return $this->_url->getBackendUrl('theme/backend/theme-editor', [
            'page_type' => CmsPage::LAYOUT_TYPE,
            'layout_type' => CmsPage::LAYOUT_TYPE,
            'layout_option' => $layoutOption,
            'lock_layout' => 1,
            'lock_layout_context' => 1,
            'layout_lock_target_type' => CmsPage::TARGET_TYPE,
            'target_id' => $page->getPageId(),
            'virtual_target_type' => CmsPage::TARGET_TYPE,
            'virtual_target_id' => $page->getPageId(),
            'theme_layout_target_type' => CmsPage::TARGET_TYPE,
            'theme_layout_target_id' => $page->getPageId(),
            'theme_layout_source_target_type' => CmsPage::TARGET_TYPE,
            'theme_layout_source_target_id' => $page->getPageId(),
            'scope' => $page->getScope(),
            'editor_area' => 'frontend',
            'preview_area' => 'frontend',
            'status' => 'draft',
            'lock_source' => 'cms',
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function buildSourceListingParams(array $data, ?CmsPage $sourcePage = null): array
    {
        $params = [];
        if ($sourcePage !== null && $sourcePage->getPageId() > 0) {
            $params['website_id'] = $sourcePage->getWebsiteId();
            $params['path_group'] = $sourcePage->getPathGroup();

            return $params;
        }

        $websiteId = (int)($data['source_website_id'] ?? $data['website_id'] ?? 0);
        $pathGroup = trim((string)($data['source_path_group'] ?? $data['path_group'] ?? ''));
        if ($websiteId > 0) {
            $params['website_id'] = $websiteId;
        }
        if ($pathGroup !== '') {
            $params['path_group'] = $pathGroup;
        }

        return $params;
    }

    /**
     * @param array<string,mixed> $targetWebsite
     * @param array<string,mixed> $copy
     * @return array<string,mixed>
     */
    private function buildCopyNoticeRedirectParams(string $type, array $targetWebsite, array $copy): array
    {
        $websiteId = (int)($targetWebsite['website_id'] ?? 0);
        $websiteCode = trim((string)($targetWebsite['website_code'] ?? $targetWebsite['code'] ?? ''));
        $websiteName = trim((string)($targetWebsite['name'] ?? ''));
        $pathGroup = trim((string)($copy['path_group'] ?? ''));
        $params = [
            'copy_notice' => $type,
            'copy_target_label' => $this->buildWebsiteLabel($websiteId, $websiteCode, $websiteName),
        ];

        if ($websiteId > 0) {
            $params['copy_target_website_id'] = $websiteId;
        }
        if ($websiteCode !== '') {
            $params['copy_target_website_code'] = $websiteCode;
        }
        if ($pathGroup !== '') {
            $params['copy_target_path_group'] = $pathGroup;
        }
        if ((int)($copy['page_id'] ?? 0) > 0) {
            $params['copy_target_page_id'] = (int)$copy['page_id'];
        }
        if (trim((string)($copy['title'] ?? '')) !== '') {
            $params['copy_target_title'] = trim((string)$copy['title']);
        }
        if (trim((string)($copy['identifier'] ?? '')) !== '') {
            $params['copy_target_identifier'] = trim((string)$copy['identifier']);
        }
        if ((int)($copy['page_count'] ?? 0) > 0) {
            $params['copy_target_page_count'] = (int)$copy['page_count'];
        }
        if (!empty($copy['theme_partial'])) {
            $params['copy_theme_partial'] = 1;
        }

        return $params;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function buildCopyNotice(array $params): array
    {
        $type = strtolower(trim((string)($params['copy_notice'] ?? '')));
        if (!in_array($type, ['page', 'path'], true)) {
            return [];
        }

        $websiteId = (int)($params['copy_target_website_id'] ?? 0);
        $websiteCode = trim((string)($params['copy_target_website_code'] ?? ''));
        $pathGroup = trim((string)($params['copy_target_path_group'] ?? ''));
        $pageId = (int)($params['copy_target_page_id'] ?? 0);
        $goParams = [
            'copy_highlight' => $type,
        ];
        if ($websiteId > 0) {
            $goParams['website_id'] = $websiteId;
            $goParams['copy_highlight_website_id'] = $websiteId;
        }
        if ($websiteCode !== '') {
            $goParams['copy_highlight_website_code'] = $websiteCode;
        }
        if ($pathGroup !== '') {
            $goParams['path_group'] = $pathGroup;
            $goParams['copy_highlight_path_group'] = $pathGroup;
        }
        if ($type === 'page' && $pageId > 0) {
            $goParams['copy_highlight_page_id'] = $pageId;
        }

        return [
            'type' => $type,
            'target_website_id' => $websiteId,
            'target_website_code' => $websiteCode,
            'target_label' => trim((string)($params['copy_target_label'] ?? '')) !== ''
                ? trim((string)$params['copy_target_label'])
                : $this->buildWebsiteLabel($websiteId, $websiteCode, ''),
            'path_group' => $pathGroup,
            'page_id' => $pageId,
            'page_title' => trim((string)($params['copy_target_title'] ?? '')),
            'identifier' => trim((string)($params['copy_target_identifier'] ?? '')),
            'page_count' => (int)($params['copy_target_page_count'] ?? 0),
            'theme_partial' => !empty($params['copy_theme_partial']),
            'go_params' => $goParams,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function buildCopyHighlight(array $params): array
    {
        $type = strtolower(trim((string)($params['copy_highlight'] ?? '')));
        if (!in_array($type, ['page', 'path'], true)) {
            return [];
        }

        return [
            'type' => $type,
            'website_id' => (int)($params['copy_highlight_website_id'] ?? $params['website_id'] ?? 0),
            'website_code' => trim((string)($params['copy_highlight_website_code'] ?? '')),
            'path_group' => trim((string)($params['copy_highlight_path_group'] ?? $params['path_group'] ?? '')),
            'page_id' => (int)($params['copy_highlight_page_id'] ?? 0),
        ];
    }

    /**
     * @return array{website_id:int,website_code:string,name:string,url:string}
     */
    private function buildTargetWebsiteFromPage(CmsPage $page): array
    {
        return [
            'website_id' => $page->getWebsiteId(),
            'website_code' => $page->getWebsiteCode(),
            'name' => $page->getWebsiteCode(),
            'url' => '',
        ];
    }

    private function buildWebsiteLabel(int $websiteId, string $websiteCode, string $websiteName): string
    {
        if ($websiteName !== '' && $websiteCode !== '' && strcasecmp($websiteName, $websiteCode) !== 0) {
            return $websiteName . ' / ' . $websiteCode;
        }
        if ($websiteName !== '') {
            return $websiteName;
        }
        if ($websiteCode !== '') {
            return $websiteCode;
        }

        return $websiteId > 0 ? (string)$websiteId : 'default';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function markActivity(string $entityType, string|int $entityId, string $action, string $title = '', array $payload = []): void
    {
        try {
            $this->activityContext->mark('Weline_Cms', $entityType, $entityId, $action, $title, $payload);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function collectRequestData(): array
    {
        $data = [];
        $post = $this->request->getPost();
        if (is_array($post)) {
            $data = array_merge($data, $post);
        }

        $params = $this->request->getParams();
        if (is_array($params)) {
            $data = array_merge($data, $params);
        }

        $body = $this->request->getBodyParams();
        if (is_array($body)) {
            $data = array_merge($data, $body);
        } elseif (is_string($body) && trim($body) !== '') {
            $parsed = [];
            parse_str($body, $parsed);
            if (is_array($parsed)) {
                $data = array_merge($data, $parsed);
            }
        }

        foreach ([
            'page_id',
            'id',
            'group_id',
            'path_group_id',
            'website_id',
            'website_code',
            'site_id',
            'site',
            'path_group',
            'alias',
            'path_group_alias',
            'slug',
            'title',
            'identifier',
            'path',
            'scope',
            'status',
            'layout_option',
            'deleted_at',
            'target_website',
            'target_website_id',
            'target_website_code',
            'target_site_id',
            'target_site',
            'source_website_id',
            'source_path_group',
        ] as $key) {
            $value = $this->request->getParam($key, null);
            if ($value !== null && $value !== '') {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
