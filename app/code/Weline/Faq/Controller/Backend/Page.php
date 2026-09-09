<?php

declare(strict_types=1);

namespace Weline\Faq\Controller\Backend;

use Weline\Cms\Service\PageService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Faq\Api\Uri\FaqNamespace;
use Weline\Faq\Service\FaqCmsEditorService;

#[Acl('Weline_Faq::page', '帮助中心页面', 'faq-circle', '管理帮助中心 CMS 页面', 'Weline_Backend::cms_group')]
final class Page extends BackendController
{
    public function __construct(
        private readonly FaqCmsEditorService $editor,
    ) {
    }

    #[Acl('Weline_Faq::page_listing', '帮助页面列表', 'list', '查看帮助中心页面')]
    public function getListing(): string
    {
        $params = $this->request->getParams();
        $websiteIdFilter = (string)($params['website_id'] ?? '');
        $filters = [
            'path_group' => FaqNamespace::PREFIX,
            'status' => $params['status'] ?? '',
            'search' => $params['search'] ?? '',
            'page' => 1,
            'page_size' => 500,
        ];
        if ($websiteIdFilter !== '') {
            $filters['website_id'] = $websiteIdFilter;
        }

        $result = ['items' => [], 'pagination' => []];
        try {
            $result = ObjectManager::getInstance(PageService::class)->listPages($filters);
        } catch (\Throwable) {
            $result = ['items' => [], 'pagination' => []];
        }

        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $pageId = (int)($item['page_id'] ?? 0);
            $slug = trim((string)($item['slug'] ?? ''));
            $item['theme_editor_url'] = $pageId > 0 ? $this->editor->buildThemeEditorUrl($pageId) : '';
            $item['edit_url'] = $this->getUrl('faq/backend/page/edit', ['page_id' => $pageId]);
            $item['cms_edit_url'] = $this->getUrl('cms/backend/page/edit', ['page_id' => $pageId]);
            $item['public_path'] = FaqNamespace::articlePublicPath($slug);
        }
        unset($item);

        $websites = $this->loadWebsiteOptions();
        $siteGroups = $this->groupPagesByWebsite($items, $websites, $websiteIdFilter);

        $this->assign('pages', $items);
        $this->assign('site_groups', $siteGroups);
        $this->assign('websites', $websites);
        $this->assign('website_id', $websiteIdFilter);
        $this->assign('search', (string)($params['search'] ?? ''));
        $this->assign('status', (string)($params['status'] ?? ''));
        $this->assign('new_url', $this->getUrl('faq/backend/page/new'));
        $this->assign('listing_url', $this->getUrl('faq/backend/page/listing'));

        return $this->fetch('Weline_Faq::templates/Backend/Page/listing.phtml');
    }

    #[Acl('Weline_Faq::page_new', '新建帮助页面', 'plus', '创建帮助中心草稿页')]
    public function getNew(): string
    {
        try {
            $siteParams = [
                'kind' => 'faq',
                'path_group' => FaqNamespace::PREFIX,
                'website_code' => (string)$this->request->getGet('website_code', ''),
            ];
            $requestedWebsiteId = $this->request->getGet('website_id', null);
            if ($requestedWebsiteId !== null && $requestedWebsiteId !== '') {
                $siteParams['website_id'] = (int)$requestedWebsiteId;
            }
            $page = $this->editor->createDraft($siteParams);
            if ($page === null) {
                $this->getMessageManager()->addError((string)__('无法创建帮助页面（CMS 不可用）。'));

                return $this->redirect($this->getUrl('faq/backend/page/listing'));
            }
            $this->getMessageManager()->addSuccess((string)__('帮助草稿已创建，可打开可视化编辑器完善内容。'));

            return $this->redirect($this->getUrl('faq/backend/page/edit', [
                'page_id' => $page->getPageId(),
            ]));
        } catch (ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());

            return $this->redirect($this->getUrl('faq/backend/page/listing'));
        }
    }

    #[Acl('Weline_Faq::page_edit', '编辑帮助页面', 'edit', '编辑帮助中心页面')]
    public function getEdit(): string
    {
        $pageId = (int)$this->request->getGet('page_id', 0);
        $page = null;
        try {
            $page = ObjectManager::getInstance(PageService::class)->getPage(['page_id' => $pageId]);
        } catch (\Throwable) {
            $page = null;
        }
        if (!is_array($page) || (int)($page['page_id'] ?? 0) <= 0) {
            $this->getMessageManager()->addError((string)__('帮助页面不存在。'));

            return $this->redirect($this->getUrl('faq/backend/page/listing'));
        }
        if (strtolower(trim((string)($page['path_group'] ?? ''))) !== FaqNamespace::PREFIX) {
            $this->getMessageManager()->addError((string)__('该页面不属于帮助中心。'));

            return $this->redirect($this->getUrl('faq/backend/page/listing'));
        }

        $websites = $this->loadWebsiteOptions();
        $websiteLabel = $this->resolveWebsiteLabel(
            (int)($page['website_id'] ?? 0),
            (string)($page['website_code'] ?? ''),
            $websites,
        );

        $this->assign('page', $page);
        $this->assign('website_label', $websiteLabel);
        $this->assign('public_path', FaqNamespace::articlePublicPath((string)($page['slug'] ?? '')));
        $this->assign('theme_editor_url', $this->editor->buildThemeEditorUrl($pageId));
        $this->assign('cms_edit_url', $this->getUrl('cms/backend/page/edit', ['page_id' => $pageId]));
        $this->assign('listing_url', $this->getUrl('faq/backend/page/listing', [
            'website_id' => (string)($page['website_id'] ?? ''),
        ]));

        return $this->fetch('Weline_Faq::templates/Backend/Page/edit.phtml');
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
            if ($websiteId < 0 && $code === '') {
                continue;
            }
            $name = trim((string)($row['name'] ?? $code));
            $url = trim((string)($row['url'] ?? ''));
            $options[] = [
                'website_id' => $websiteId,
                'code' => $code !== '' ? $code : 'default',
                'name' => $name !== '' ? $name : ($code !== '' ? $code : 'default'),
                'url' => $url,
                'label' => ($name !== '' ? $name : ($code !== '' ? $code : 'default'))
                    . ' / ' . ($code !== '' ? $code : 'default'),
            ];
        }

        return $options !== [] ? $options : [[
            'website_id' => 0,
            'code' => 'default',
            'name' => 'default',
            'url' => '',
            'label' => 'default / default',
        ]];
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @param list<array<string,mixed>> $websites
     * @return list<array<string,mixed>>
     */
    private function groupPagesByWebsite(array $pages, array $websites, string $websiteIdFilter): array
    {
        $groups = [];
        foreach ($websites as $website) {
            if (!is_array($website)) {
                continue;
            }
            $websiteId = (int)($website['website_id'] ?? 0);
            if ($websiteIdFilter !== '' && $websiteId !== (int)$websiteIdFilter) {
                continue;
            }
            $code = (string)($website['code'] ?? 'default');
            $key = $websiteId . '|' . strtolower($code);
            $groups[$key] = [
                'website_id' => $websiteId,
                'website_code' => $code,
                'label' => (string)($website['label'] ?? $code),
                'url' => (string)($website['url'] ?? ''),
                'new_url' => $this->getUrl('faq/backend/page/new', [
                    'website_id' => $websiteId,
                    'website_code' => $code,
                ]),
                'pages' => [],
            ];
        }

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $websiteId = (int)($page['website_id'] ?? 0);
            $code = trim((string)($page['website_code'] ?? 'default'));
            if ($code === '') {
                $code = 'default';
            }
            $key = $websiteId . '|' . strtolower($code);
            if (!isset($groups[$key])) {
                if ($websiteIdFilter !== '' && $websiteId !== (int)$websiteIdFilter) {
                    continue;
                }
                $groups[$key] = [
                    'website_id' => $websiteId,
                    'website_code' => $code,
                    'label' => $code . ' / #' . $websiteId,
                    'url' => '',
                    'new_url' => $this->getUrl('faq/backend/page/new', [
                        'website_id' => $websiteId,
                        'website_code' => $code,
                    ]),
                    'pages' => [],
                ];
            }
            $groups[$key]['pages'][] = $page;
        }

        return array_values($groups);
    }

    /**
     * @param list<array<string,mixed>> $websites
     */
    private function resolveWebsiteLabel(int $websiteId, string $websiteCode, array $websites): string
    {
        foreach ($websites as $website) {
            if (!is_array($website)) {
                continue;
            }
            if ((int)($website['website_id'] ?? -1) === $websiteId) {
                return (string)($website['label'] ?? $website['name'] ?? $websiteCode);
            }
        }

        return $websiteCode !== '' ? $websiteCode : ('#' . $websiteId);
    }
}
