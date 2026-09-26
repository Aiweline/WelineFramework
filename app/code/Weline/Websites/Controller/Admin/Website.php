<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Admin;

use Weline\Backend\Api\Config\KeysInterface;
use Weline\Currency\Api\CurrencyCatalogInterface;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Localization\LocaleRepositoryInterface;
use Weline\SystemConfig\Api\ConfigStore as SystemConfig;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Service\StoreChannelAdminService;
use Weline\Websites\Service\WebsiteAdminListPresenter;
use Weline\Websites\Service\WebsiteBackendEntryBridgeService;
use Weline\Websites\Service\WebsiteCacheInvalidationService;
use Weline\Websites\Service\WebsiteChangeSnapshotFactory;
use Weline\Websites\Service\WebsiteEntryUrlService;
use Weline\Websites\Service\WebsiteScopeTreeService;
use Weline\Websites\Service\WebsiteStoreChannelDirectory;
use Weline\Websites\Service\WebsiteSubPathValidator;

#[Acl('Weline_Websites::website', '网站管理', 'globe', '网站管理', 'Weline_Websites::website_service')]
class Website extends BackendController
{
    private const FRONTEND_START_PAGE_CONFIG_KEY = 'frontend_start_page_path';
    private const FRONTEND_START_PAGE_CONFIG_MODULE = 'Weline_Websites';

    private \Weline\Websites\Model\Website $website;

    public function __construct(
        \Weline\Websites\Model\Website $website,
        private readonly RuntimeProviderResolver $runtimeProviders,
        private readonly WebsiteStoreChannelDirectory $storeChannelDirectory,
        private readonly WebsiteAdminListPresenter $listPresenter,
    ) {
        $this->website = $website;
    }

    #[Acl('Weline_Websites::website_list', '网站列表', 'list', '网站管理')]
    public function index()
    {
        // panel=1（或 Accept:json + node）：左树异步右栏；否则保留旧 DataTable 搜索 AJAX。
        if ($this->request->isAjax()) {
            if ($this->wantsTreeEditorPanel()) {
                return $this->treeEditorPanelAjax();
            }

            return $this->searchAjax();
        }

        $search = trim((string)$this->request->getGet('search', ''));
        $nodeRaw = trim((string)$this->request->getGet('node', ''));
        $focus = trim((string)$this->request->getGet('focus', ''));
        $newKind = trim((string)$this->request->getGet('new', ''));

        /** @var WebsiteScopeTreeService $treeService */
        $treeService = ObjectManager::getInstance(WebsiteScopeTreeService::class);
        $tree = $treeService->buildTree($search);
        $selection = $treeService->resolveSelection($nodeRaw, $focus, $tree, $newKind);

        $this->assignTreeShell($selection, $tree, $search, $focus);
        $this->prepareTreeEditor($selection);

        return $this->fetch();
    }

    private function wantsTreeEditorPanel(): bool
    {
        if (trim((string)$this->request->getGet('panel', '')) === '1') {
            return true;
        }
        $header = $this->request->getHeader('X-Weline-Scope-Panel');
        if (is_array($header)) {
            $header = (string)($header[0] ?? '');
        }
        if (trim((string)$header) === '1') {
            return true;
        }
        // 兜底：带 node 的 JSON AJAX（避免旧搜索接口吞掉右栏请求）
        $node = trim((string)$this->request->getGet('node', ''));
        if ($node === '') {
            return false;
        }
        $accept = $this->request->getHeader('Accept');
        if (is_array($accept)) {
            $accept = implode(',', $accept);
        }
        $accept = strtolower((string)$accept);

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    /**
     * 左树点击：返回右栏 HTML 片段（JSON）。
     */
    private function treeEditorPanelAjax(): string
    {
        try {
            $search = trim((string)$this->request->getGet('search', ''));
            $nodeRaw = trim((string)$this->request->getGet('node', ''));
            $focus = trim((string)$this->request->getGet('focus', ''));
            $newKind = trim((string)$this->request->getGet('new', ''));

            /** @var WebsiteScopeTreeService $treeService */
            $treeService = ObjectManager::getInstance(WebsiteScopeTreeService::class);
            $tree = $treeService->buildTree($search);
            $selection = $treeService->resolveSelection($nodeRaw, $focus, $tree, $newKind);

            $this->assignTreeShell($selection, $tree, $search, $focus);
            $this->prepareTreeEditor($selection);

            $html = (string)$this->template('Weline_Websites::templates/Admin/Website/tree-editor-panel.phtml');
            $payload = [
                'success' => true,
                'html' => $html,
                'kind' => (string)$selection['kind'],
                'node' => (string)$selection['node'],
                'title' => (string)$this->getData('editor_title'),
            ];
        } catch (\Throwable $throwable) {
            $payload = [
                'success' => false,
                'message' => $throwable->getMessage(),
            ];
        }

        return $this->fetchJson($payload);
    }

    /**
     * @param array{
     *     kind:string,
     *     id:int,
     *     node:string,
     *     acl_ok:bool,
     *     website_id:int,
     *     store_id:int,
     *     channel_id:int,
     *     entity:array<string,mixed>,
     *     error:string
     * } $selection
     * @param list<array<string,mixed>> $tree
     */
    private function assignTreeShell(array $selection, array $tree, string $search, string $focus): void
    {
        $kind = (string)$selection['kind'];
        $editorTitle = match ($kind) {
            'website' => (string)__('编辑网站'),
            'store' => (string)__('编辑商店'),
            'channel' => (string)__('编辑渠道'),
            'new_store' => (string)__('新建商店'),
            'new_channel' => (string)__('新建渠道'),
            default => (string)__('范围编辑'),
        };

        $this->assign('scope_tree', $tree);
        $this->assign('search', $search);
        $this->assign('selected_node', (string)$selection['node']);
        $this->assign('editor_kind', $kind);
        $this->assign('editor_acl_ok', (bool)$selection['acl_ok']);
        $this->assign('editor_error', (string)$selection['error']);
        $this->assign('editor_website_id', (int)$selection['website_id']);
        $this->assign('editor_store_id', (int)$selection['store_id']);
        $this->assign('editor_channel_id', (int)$selection['channel_id']);
        $this->assign('editor_title', $editorTitle);
        $this->assign('focus', $focus);
        $this->assign('tree_return_mode', true);
        $this->assign('tree_return_node', (string)$selection['node']);
        // 保活浏览器地址栏语种/货币段：@url/@backend-url 走 State 前缀时会丢掉 URL 路径里的 zh_Hans_CN。
        $this->assign('tree_return_url', $this->buildLocalePreservingWebsitesTreeUrl((string)$selection['node']));
        $this->assign('is_embedded_form', true);
    }

    /**
     * 旧 AJAX 搜索响应兼容面；新模板不再从浏览器调用该接口。
     */
    private function searchAjax(): string
    {
        try {
            $search = trim((string)$this->request->getGet('search', ''));
            $pageSize = (int)$this->request->getGet('pageSize', 10);
            if ($pageSize < 1) {
                $pageSize = 10;
            }
            $pageSize = min($pageSize, 1000);

            $websiteModel = $this->createWebsiteListingModel();
            $this->applyWebsiteSearch($websiteModel, $search);
            $websites = $websiteModel->order()->pagination(1, $pageSize, [
                'page' => 1,
                'pageSize' => $pageSize,
                'search' => $search,
            ])->select()->fetch();
            $items = $websites->getItems();
            $this->enrichWebsiteListingItems($items);
            $this->assignListingTableView($items, $websites->getPagination(), $search);
            $tableHtml = $this->template('Weline_Websites::templates/Admin/Website/datatable.phtml');
            $payload = [
                'success' => true,
                'html' => $tableHtml,
                'count' => count($items),
            ];
        } catch (\Throwable $throwable) {
            $payload = [
                'success' => false,
                'message' => $throwable->getMessage(),
            ];
        }

        return $this->fetchJson($payload);
    }

    /**
     * @param list<\Weline\Websites\Model\Website|array<string, mixed>> $items
     */
    private function assignListingTableView(array $items, string $pagination, string $search): void
    {
        $tableRows = $this->buildListingTableRows($items);
        $this->assign('table_rows', $tableRows);
        $this->assign('row_actions_json', $this->buildListingRowActionsJson());
        $this->assign('pagination', $pagination);
        $this->assign('search', $search);
        $this->assign('total', count($tableRows));
    }

    /**
     * @param list<\Weline\Websites\Model\Website|array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function buildListingTableRows(array $items): array
    {
        $tableRows = [];
        foreach ($items as $website) {
            if ($website instanceof \Weline\Websites\Model\Website) {
                $website = $website->getData();
            }
            if (!\is_array($website)) {
                throw new \RuntimeException((string)__('网站目录行必须是网站模型或数组'));
            }
            $row = $this->listPresenter->presentRow($website);
            $websiteId = (int)($row['website_id'] ?? 0);
            $row['edit_url'] = $this->getUrl('*/admin/website/edit', [
                'id' => $websiteId,
                'isIframe' => 'true',
            ]);
            $tableRows[] = $row;
        }

        return $tableRows;
    }

    private function buildListingRowActionsJson(): string
    {
        return json_encode([
            [
                'type' => 'link',
                'label' => (string)__('访问前端'),
                'hrefTemplate' => '{frontend_url}',
                'testId' => 'website-visit-frontend-button',
                'icon' => 'external-link',
                'tone' => 'primary',
                'variant' => 'outline',
                'size' => 'sm',
            ],
            [
                'type' => 'link',
                'label' => (string)__('管理后端'),
                'hrefTemplate' => '{backend_url}',
                'testId' => 'website-visit-backend-button',
                'icon' => 'grid',
                'tone' => 'success',
                'variant' => 'outline',
                'size' => 'sm',
            ],
            [
                'type' => 'event',
                'label' => (string)__('编辑'),
                'action' => 'edit',
                'testId' => 'website-edit-button',
                'icon' => 'edit',
                'tone' => 'info',
                'variant' => 'outline',
                'size' => 'sm',
            ],
            [
                'type' => 'event',
                'label' => (string)__('删除'),
                'action' => 'delete',
                'testId' => 'website-delete-button',
                'icon' => 'trash',
                'tone' => 'danger',
                'variant' => 'outline',
                'size' => 'sm',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * ORM pagination returns Website models while compatibility callers may pass arrays.
     * Normalize both forms before the templates consume the rows.
     *
     * @param list<\Weline\Websites\Model\Website|array<string, mixed>> $items
     */
    private function enrichWebsiteListingItems(array &$items): void
    {
        $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
        $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
        $websiteDomain = ObjectManager::getInstance(WebsiteDomain::class);

        foreach ($items as &$website) {
            if ($website instanceof \Weline\Websites\Model\Website) {
                $website = $website->getData();
            }
            if (!\is_array($website)) {
                throw new \RuntimeException((string)__('网站目录行必须是网站模型或数组'));
            }
            $websiteId = $this->requireWebsiteListingId($website);
            // 获取关联货币
            $currencyCodes = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
            $website['currency_codes'] = $currencyCodes;

            // 获取关联语言
            $languageCodes = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
            $website['language_codes'] = $languageCodes;

            // 获取关联域名（多个）
            $website['domain_list'] = $websiteDomain->getDomainsWithStatus($websiteId);
            $website['store_channel_directory'] = $this->storeChannelDirectory->forWebsite($websiteId);
            $entryUrls = ObjectManager::getInstance(WebsiteEntryUrlService::class)
                ->resolveForListingRow($website);
            $website['frontend_url'] = $entryUrls['frontend_url'];
            if ($websiteId === \Weline\Websites\Model\Website::ID_DEFAULT) {
                // 默认站：管理后端即本站后台首页
                $website['backend_url'] = $this->request->getUrlBuilder()->getBackendUrl('admin');
            } else {
                // 非默认站：主站签发直进令牌，有授权包则免再登录
                $website['backend_url'] = $this->request->getUrlBuilder()->getBackendUrl(
                    '*/admin/website/enter-backend',
                    ['website_id' => $websiteId]
                );
            }
            $website['backend_login_url'] = $entryUrls['backend_url'];
        }
        unset($website);
    }

    #[Acl('Weline_Websites::website_enter_backend', '直进子站后台', 'login', '主站直进已授权子站后台', 'Weline_Websites::website_list')]
    public function getEnterBackend()
    {
        $websiteId = (int)$this->request->getGet('website_id', 0);
        try {
            /** @var WebsiteBackendEntryBridgeService $bridge */
            $bridge = ObjectManager::getInstance(WebsiteBackendEntryBridgeService::class);
            $userId = (int)($this->session->getUserId() ?? 0);
            $token = $bridge->issueToken($websiteId, $userId);
            $urls = $bridge->buildConsumeUrl($websiteId, $token);
            // 跨子域直进：不能走 PcController::redirect（同源校验会拦掉）
            $this->request->getResponse()->redirect($urls['consume_url']);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('*/admin/website/index');
        }
    }

    /**
     * 一次性令牌消费入口：故意不挂 #[Acl]。
     * 有 Acl 时 RouteBefore 会按受保护路由强制登录，而本请求尚未装上子站会话。
     * 鉴权靠 WebsiteBackendEntryBridgeService 的短时一次性 token。
     */
    public function getConsumeBackendEntry()
    {
        $token = \trim((string)$this->request->getGet('token', ''));
        try {
            /** @var WebsiteBackendEntryBridgeService $bridge */
            $bridge = ObjectManager::getInstance(WebsiteBackendEntryBridgeService::class);
            $result = $bridge->consumeAndLogin($token, $this->session, (string)$this->request->clientIP());
            $this->redirect($result['redirect_url']);
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            // 失败时落到子站登录页，便于站点本地账号登录
            try {
                $websiteId = ObjectManager::getInstance(\Weline\Websites\Service\WebsiteAclGrantService::class)
                    ->currentWebsiteId();
                $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class, [], false)->load($websiteId);
                $entry = ObjectManager::getInstance(WebsiteEntryUrlService::class)
                    ->resolveForListingRow($website->getData() ?: ['website_id' => $websiteId]);
                $loginUrl = (string)($entry['backend_url'] ?? '');
                if ($loginUrl !== '') {
                    $this->redirect($loginUrl);
                }
            } catch (\Weline\Framework\Http\ResponseTerminateException $redirect) {
                throw $redirect;
            } catch (\Throwable) {
            }
            $this->redirect('*/admin/login');
        }
    }

    /**
     * 消费入口在装会话前必须可达；不依赖可能陈旧的 controller 白名单缓存。
     */
    protected function loginCheck(): void
    {
        $route = \strtolower(\trim((string)$this->request->getRouteUrlPath(), '/'));
        if ($route === 'websites/admin/website/consume-backend-entry'
            || $route === 'websites/admin/website/get-consume-backend-entry'
        ) {
            return;
        }
        parent::loginCheck();
    }

    private function createWebsiteListingModel(): \Weline\Websites\Model\Website
    {
        /** @var \Weline\Websites\Model\Website $websiteModel */
        $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class, [], false);
        $websiteModel->clearQuery();
        return $websiteModel;
    }

    private function applyWebsiteSearch(\Weline\Websites\Model\Website $websiteModel, string $search): void
    {
        if ($search === '') {
            return;
        }

        $searchPattern = '%' . $search . '%';
        $websiteModel->where([
            [\Weline\Websites\Model\Website::schema_fields_NAME, 'LIKE', $searchPattern, 'OR'],
            [\Weline\Websites\Model\Website::schema_fields_CODE, 'LIKE', $searchPattern, 'OR'],
            [\Weline\Websites\Model\Website::schema_fields_URL, 'LIKE', $searchPattern],
        ]);
    }

    /** @param array<string, mixed> $website */
    private function requireWebsiteListingId(array $website): int
    {
        $field = \Weline\Websites\Model\Website::schema_fields_ID;
        if (!\array_key_exists($field, $website)) {
            throw new \RuntimeException((string)__('网站目录行缺少 website_id'));
        }

        return $this->requireWebsiteId($website[$field]);
    }

    private function requireWebsiteId(mixed $value): int
    {
        if (\is_int($value)) {
            $websiteId = $value;
        } elseif (\is_string($value) && \preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $websiteId = (int)$value;
            if ((string)$websiteId !== $value) {
                throw new \InvalidArgumentException((string)__('网站ID必须是非负规范整数'));
            }
        } else {
            throw new \InvalidArgumentException((string)__('网站ID必须是非负规范整数'));
        }

        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('网站ID必须是非负规范整数'));
        }

        return $websiteId;
    }

    /**
     * 编辑入口同时接受列表契约的 id 与常见别名 website_id（含默认站 0）。
     * 二者皆有时以 id 为准。
     */
    private function resolveEditTargetWebsiteIdFromRequest(): int
    {
        $raw = $this->request->getParam('id');
        if ($raw === null || $raw === '') {
            $raw = $this->request->getParam('website_id');
        }

        return $this->requireWebsiteId($raw);
    }

    /**
     * 弹窗/iframe 表单：隐藏 blank 布局的页面大标题（否则会露出模块名）。
     */
    private function suppressPageChromeForEmbeddedForm(string $pageTitle): void
    {
        $this->assign('title', $pageTitle);
        $meta = $this->getTemplate()->getData('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['showPageHeader'] = false;
        $meta['showMessages'] = false;
        $meta['title'] = $pageTitle;
        $meta['controller_title'] = $pageTitle;
        $this->assign('meta', $meta);
        $this->assign('layoutShowPageHeader', false);
        $this->assign('layoutShowMessages', false);
        // drawer/offcanvas 外层已有标题与操作条，表单内不再套一层卡片标题。
        $this->assign('is_embedded_form', true);
    }

    #[Acl('Weline_Websites::website_add', '添加网站', 'plus', '网站管理')]
    public function add()
    {
        // 使用空白布局（适用于 offcanvas/弹窗）
        $this->layoutType = 'default.blank';
        $this->suppressPageChromeForEmbeddedForm((string)__('添加网站'));

        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $postData = $data;
            try {
                $poolIds = $data['pool_ids'] ?? '';
                $subPath = $this->assertValidSubPath((string)($data['sub_path'] ?? ''));
                $addressList = $this->buildAddressListFromPoolSelection($poolIds, $subPath);
                if (empty($addressList)) {
                    throw new \Exception(__('请至少选择一个域名'));
                }
                /** @var WebsiteDomain $domainModel */
                $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
                foreach ($addressList as $item) {
                    $conflict = $domainModel->findConflict($item['domain'], $item['sub_path'], null);
                    if ($conflict !== null) {
                        $addr = $item['domain'] . $item['sub_path'];
                        if ($item['sub_path'] === '') {
                            throw new \Exception(
                                __('该域名根路径已被站点「%{1}」使用，请使用子路径（如 /shop）', [$conflict['website_name']])
                            );
                        }
                        throw new \Exception(
                            __('该地址 %{1} 已被站点「%{2}」使用', [$addr, $conflict['website_name']])
                        );
                    }
                }
                // 排序：当同时存在根域与 www 时，www 优先作为主 URL
                $addressList = $this->orderAddressListPreferredUrl($addressList);
                // 用第一个地址的域名生成默认 code（若未填）
                $firstDomain = $addressList[0]['domain'];
                if (empty(trim((string)($data['code'] ?? '')))) {
                    $data['code'] = $this->domainToCode($firstDomain);
                }
                // 用第一个地址作为主 URL（不再自动加 www，站点可关联多域名）
                $firstSubPath = $addressList[0]['sub_path'];
                $data['url'] = 'https://' . $firstDomain . $firstSubPath;

                [$currencyCodes, $languageCodes] = $this->normalizeWebsiteLocaleMoneyPost($data);
                $startPagePath = $this->normalizeStartPagePath((string)($data['start_page_path'] ?? ''));

                if (isset($data['website_id'])) {
                    unset($data['website_id']);
                }
                unset(
                    $data['address_lines'],
                    $data['domain_values'],
                    $data['pool_ids'],
                    $data['sub_path'],
                    $data['start_page_path'],
                    $data['currency_codes'],
                    $data['language_codes'],
                );
                $this->stripExtensionPostData($data);
                $connection = $this->website->getConnection();
                $websiteId = $this->transactions()->runWrite(
                    $connection,
                    function () use (
                        $connection,
                        $data,
                        $addressList,
                        $currencyCodes,
                        $languageCodes,
                        $startPagePath,
                        $postData,
                    ): int {
                        $this->cacheInvalidation()->beginDeferred($connection);
                        $this->website->clearData()->setData($data)->save();
                        $websiteId = (int)$this->website->getId();
                        if ($websiteId <= \Weline\Websites\Model\Website::ID_DEFAULT) {
                            throw new \RuntimeException(__('网站保存失败，未能获取网站ID'));
                        }

                        $this->saveWebsiteDomains($websiteId, $addressList);
                        $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
                        $websiteCurrency->setConnection($connection);
                        $websiteCurrency->setWebsiteCurrencies($websiteId, $currencyCodes);
                        $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
                        $websiteLanguage->setConnection($connection);
                        $websiteLanguage->setWebsiteLanguages($websiteId, $languageCodes);
                        $this->saveStartPagePathConfig(
                            $websiteId,
                            $this->website->getCode(),
                            $startPagePath,
                            $connection,
                            true,
                        );
                        $this->dispatchWebsiteSaveAfter(
                            $websiteId,
                            'add',
                            $this->website->getData(),
                            $postData,
                            $addressList,
                        );

                        $after = $this->snapshots()->capture($websiteId, $connection);
                        if ($after === null) {
                            throw new \RuntimeException(__('网站保存后快照不存在'));
                        }
                        $this->publishWebsiteChange(
                            $connection,
                            $websiteId,
                            'add',
                            null,
                            $after,
                        );
                        return $websiteId;
                    },
                );
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                // 开发环境显示完整堆栈
                if (DEV) {
                    $errorMsg .= "\n\n[File] " . $e->getFile() . ':' . $e->getLine();
                }
                $this->finishWebsiteMutation(
                    '',
                    (string)__('网站添加失败: %{1}', [$errorMsg]),
                    null,
                    true,
                );
                return;
            }

            $this->finishWebsiteMutation(
                (string)__('网站添加成功'),
                '',
                $websiteId,
                false,
            );
            return;
        }

        // 初始化空网站数据，避免模板中访问未定义变量
        $this->assign('website', []);
        $this->assign('selected_currencies', []);
        $this->assign('selected_languages', []);
        $this->assign('selected_pool_ids', []);
        $this->assign('selected_domain_names', []);
        $this->assign('domain_options', $this->getDomainOptions());
        $this->assign('sub_path', '');
        $this->assign('start_page_route_options', $this->getStartPageRouteOptions());
        $this->assign('selected_start_page_path', '');
        $this->assign('store_channel_directory', []);
        $this->assignSubPathBanCatalog();

        // 获取所有货币
        $this->assign('currencies', $this->getAllCurrencies());

        // 获取所有语言
        $this->assign('locales', $this->getAllLocales());

        // 时区
        $timezones = \DateTimeZone::listIdentifiers();
        sort($timezones);
        $this->assign('timezones', $timezones);
        return $this->fetch('form');
    }

    #[Acl('Weline_Websites::website_edit', '编辑网站', 'edit', '网站管理')]
    public function edit()
    {
        // 使用空白布局（适用于 offcanvas/弹窗）
        $this->layoutType = 'default.blank';
        $this->suppressPageChromeForEmbeddedForm((string)__('编辑网站'));

        try {
            $websiteId = $this->resolveEditTargetWebsiteIdFromRequest();
        } catch (\InvalidArgumentException $exception) {
            $this->respondWebsiteOffcanvasError((string)$exception->getMessage());
            return;
        }

        $this->website->load($websiteId);

        // 检查网站是否存在
        if (!$this->website->hasData(\Weline\Websites\Model\Website::schema_fields_ID)) {
            $this->respondWebsiteOffcanvasError((string)__('网站不存在'));
            return;
        }

        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $postData = $data;

            // POST body website_id → URL id → URL website_id → 已加载的编辑目标
            $postWebsiteId = $data['website_id'] ?? null;
            if ($postWebsiteId === null || $postWebsiteId === '') {
                $raw = $this->request->getParam('id');
                if ($raw === null || $raw === '') {
                    $raw = $this->request->getParam('website_id');
                }
                $postWebsiteId = ($raw === null || $raw === '') ? $websiteId : $raw;
            }

            try {
                $postWebsiteId = $this->requireWebsiteId($postWebsiteId);
                if ($postWebsiteId !== $websiteId) {
                    throw new \InvalidArgumentException(__('网站ID与当前编辑目标不一致'));
                }
                $poolIds = $data['pool_ids'] ?? '';
                $subPath = $this->assertValidSubPath((string)($data['sub_path'] ?? ''));
                $addressList = $this->buildAddressListFromPoolSelection($poolIds, $subPath);
                if (empty($addressList)) {
                    throw new \Exception(__('请至少选择一个域名'));
                }
                /** @var WebsiteDomain $domainModel */
                $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
                foreach ($addressList as $item) {
                    $conflict = $domainModel->findConflict($item['domain'], $item['sub_path'], $postWebsiteId);
                    if ($conflict !== null) {
                        if ($item['sub_path'] === '') {
                            throw new \Exception(
                                __('该域名根路径已被站点「%{1}」使用，请使用子路径（如 /shop）', [$conflict['website_name']])
                            );
                        }
                        throw new \Exception(
                            __('该地址 %{1} 已被站点「%{2}」使用', [$item['domain'] . $item['sub_path'], $conflict['website_name']])
                        );
                    }
                }
                $addressList = $this->orderAddressListPreferredUrl($addressList);
                $firstDomain = $addressList[0]['domain'];
                $firstSubPath = $addressList[0]['sub_path'];
                $data['url'] = 'https://' . $firstDomain . $firstSubPath;

                [$currencyCodes, $languageCodes] = $this->normalizeWebsiteLocaleMoneyPost($data);
                $startPagePath = $this->normalizeStartPagePath((string)($data['start_page_path'] ?? ''));

                $data['website_id'] = $postWebsiteId;
                unset(
                    $data['address_lines'],
                    $data['domain_values'],
                    $data['pool_ids'],
                    $data['sub_path'],
                    $data['start_page_path'],
                    $data['currency_codes'],
                    $data['language_codes'],
                );
                $this->stripExtensionPostData($data);
                $connection = $this->website->getConnection();
                $this->transactions()->runWrite(
                    $connection,
                    function () use (
                        $connection,
                        $postWebsiteId,
                        $data,
                        $addressList,
                        $currencyCodes,
                        $languageCodes,
                        $startPagePath,
                        $postData,
                    ): void {
                        $this->cacheInvalidation()->beginDeferred($connection);
                        $before = $this->snapshots()->capture($postWebsiteId, $connection);
                        if ($before === null) {
                            throw new \RuntimeException(__('网站不存在'));
                        }

                        $this->website->clearData()
                            ->setData($this->websiteCoreData($before))
                            ->addData($data)
                            ->save();
                        $this->saveWebsiteDomains($postWebsiteId, $addressList);
                        $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
                        $websiteCurrency->setConnection($connection);
                        $websiteCurrency->setWebsiteCurrencies($postWebsiteId, $currencyCodes);
                        $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
                        $websiteLanguage->setConnection($connection);
                        $websiteLanguage->setWebsiteLanguages($postWebsiteId, $languageCodes);
                        $this->saveStartPagePathConfig(
                            $postWebsiteId,
                            trim((string)($before[\Weline\Websites\Model\Website::schema_fields_CODE]
                                ?? $data['code']
                                ?? $this->website->getCode())),
                            $startPagePath,
                            $connection,
                            true,
                        );
                        $this->dispatchWebsiteSaveAfter(
                            $postWebsiteId,
                            'edit',
                            $this->website->getData(),
                            $postData,
                            $addressList,
                        );

                        $after = $this->snapshots()->capture($postWebsiteId, $connection);
                        if ($after === null) {
                            throw new \RuntimeException(__('网站保存后快照不存在'));
                        }
                        $this->publishWebsiteChange(
                            $connection,
                            $postWebsiteId,
                            'edit',
                            $before,
                            $after,
                        );
                    },
                );
            } catch (\Throwable $e) {
                $this->finishWebsiteMutation(
                    '',
                    (string)$e->getMessage(),
                    $websiteId,
                    true,
                );
                return;
            }

            $this->finishWebsiteMutation(
                (string)__('网站更新成功'),
                '',
                $websiteId,
                false,
            );
            return;
        }

        $this->assignWebsiteEditorFormData($websiteId);
        return $this->fetch('form');
    }

    /**
     * 快速创建站点（AJAX接口）
     * 与 add 保持一致：支持 address_lines + pool_ids 多地址逻辑
     * 兼容旧参数：仅传 url 时转为单行 address_lines
     */
    #[Acl('Weline_Websites::website_quick_save', '快速创建站点', '', '快速创建站点')]
    public function quickSave()
    {
        try {
            $postData = $this->request->getPost();
            $name = trim((string) $this->request->getPost('name', ''));
            $code = trim((string) $this->request->getPost('code', ''));
            $addressLines = trim((string) $this->request->getPost('address_lines', ''));
            $poolIds = trim((string) $this->request->getPost('pool_ids', ''));
            $url = trim((string) $this->request->getPost('url', ''));
            $defaultTimezone = (string) $this->request->getPost('default_timezone', 'Asia/Shanghai');
            $scope = trim((string) $this->request->getPost('scope', ''));
            $startPagePath = $this->normalizeStartPagePath((string)$this->request->getPost('start_page_path', ''));

            if (empty($name)) {
                return $this->fetchJson(['success' => false, 'message' => __('站点名称不能为空')]);
            }
            if (empty($code)) {
                return $this->fetchJson(['success' => false, 'message' => __('站点代码不能为空')]);
            }
            if (empty($addressLines) && empty($poolIds)) {
                if (empty($url)) {
                    return $this->fetchJson(['success' => false, 'message' => __('请填写网站地址或选择域名')]);
                }
                $url = preg_replace('#^https?://#i', '', rtrim($url, '/'));
                $addressLines = $url;
            }

            $addressList = $this->parseAddressLines($addressLines, $poolIds);
            if (empty($addressList)) {
                return $this->fetchJson(['success' => false, 'message' => __('请至少填写一个网站地址（域名或域名/子路径）')]);
            }
            /** @var WebsiteDomain $domainModel */
            $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
            foreach ($addressList as $item) {
                $conflict = $domainModel->findConflict($item['domain'], $item['sub_path'], null);
                if ($conflict !== null) {
                    $addr = $item['domain'] . $item['sub_path'];
                    throw new \Exception(
                        $item['sub_path'] === ''
                            ? __('该域名根路径已被站点「%{1}」使用，请使用子路径（如 /shop）', [$conflict['website_name']])
                            : __('该地址 %{1} 已被站点「%{2}」使用', [$addr, $conflict['website_name']])
                    );
                }
            }
            $addressList = $this->orderAddressListPreferredUrl($addressList);
            $firstDomain = $addressList[0]['domain'];
            $firstSubPath = $addressList[0]['sub_path'];

            $existingWebsite = clone $this->website;
            $existingWebsite->clear()
                ->where(\Weline\Websites\Model\Website::schema_fields_CODE, $code)
                ->find()
                ->fetch();
            if ($existingWebsite->hasData(\Weline\Websites\Model\Website::schema_fields_ID)) {
                return $this->fetchJson(['success' => false, 'message' => __('站点代码已存在')]);
            }

            $primaryUrl = 'https://' . $firstDomain . $firstSubPath;
            $newWebsite = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $newWebsite->clearData()  // 清除所有数据
                ->setData(\Weline\Websites\Model\Website::schema_fields_NAME, $name)
                ->setData(\Weline\Websites\Model\Website::schema_fields_CODE, $code)
                ->setData(\Weline\Websites\Model\Website::schema_fields_URL, $primaryUrl)
                ->setData(\Weline\Websites\Model\Website::schema_fields_DEFAULT_TIMEZONE, $defaultTimezone);

            // 设置业务范围标识
            if (!empty($scope)) {
                $newWebsite->setData(\Weline\Websites\Model\Website::schema_fields_SCOPE, $scope);
            }

            // 确保主键字段被清除（防止主键冲突）
            if ($newWebsite->hasData(\Weline\Websites\Model\Website::schema_fields_ID)) {
                $newWebsite->unsetData(\Weline\Websites\Model\Website::schema_fields_ID);
            }

            $connection = $this->website->getConnection();
            $newWebsite->setConnection($connection);
            $websiteId = $this->transactions()->runWrite(
                $connection,
                function () use (
                    $connection,
                    $newWebsite,
                    $addressList,
                    $startPagePath,
                    $postData,
                ): int {
                    $this->cacheInvalidation()->beginDeferred($connection);
                    $newWebsite->save(true);
                    $websiteId = (int)$newWebsite->getId();
                    if ($websiteId <= \Weline\Websites\Model\Website::ID_DEFAULT) {
                        throw new \RuntimeException(__('网站保存失败，未能获取网站ID'));
                    }
                    $this->saveWebsiteDomains($websiteId, $addressList);
                    $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
                    $websiteCurrency->setConnection($connection);
                    $websiteCurrency->setWebsiteCurrencies($websiteId, []);
                    $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
                    $websiteLanguage->setConnection($connection);
                    $websiteLanguage->setWebsiteLanguages($websiteId, []);
                    $this->saveStartPagePathConfig(
                        $websiteId,
                        $newWebsite->getCode(),
                        $startPagePath,
                        $connection,
                        true,
                    );
                    $this->dispatchWebsiteSaveAfter(
                        $websiteId,
                        'quick_save',
                        $newWebsite->getData(),
                        $postData,
                        $addressList,
                    );
                    $after = $this->snapshots()->capture($websiteId, $connection);
                    if ($after === null) {
                        throw new \RuntimeException(__('网站保存后快照不存在'));
                    }
                    $this->publishWebsiteChange(
                        $connection,
                        $websiteId,
                        'quick_save',
                        null,
                        $after,
                    );
                    return $websiteId;
                },
            );

            return $this->fetchJson([
                'success' => true,
                'message' => __('站点创建成功'),
                'website' => [
                    'website_id' => $websiteId,
                    'name' => $newWebsite->getData(\Weline\Websites\Model\Website::schema_fields_NAME),
                    'code' => $newWebsite->getData(\Weline\Websites\Model\Website::schema_fields_CODE),
                    'url' => $primaryUrl,
                    'scope' => $newWebsite->getData(\Weline\Websites\Model\Website::schema_fields_SCOPE) ?? '',
                    'start_page_path' => $startPagePath,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('创建失败：') . $e->getMessage(),
            ]);
        }
    }

    #[Acl('Weline_Websites::website_delete', '删除网站', 'trash', '网站管理')]
    public function deleteDelete(): string
    {
        $rawWebsiteId = $this->request->getGet('id');
        try {
            $websiteId = $this->requireWebsiteId($rawWebsiteId);
            $connection = $this->website->getConnection();
            $this->transactions()->runWrite(
                $connection,
                function () use ($connection, $websiteId): void {
                    $this->cacheInvalidation()->beginDeferred($connection);
                    $before = $this->snapshots()->capture($websiteId, $connection);
                    if ($before === null) {
                        throw new \RuntimeException(__('网站不存在'));
                    }
                    $websiteCode = (string)($before[\Weline\Websites\Model\Website::schema_fields_CODE] ?? '');
                    if ($websiteId === \Weline\Websites\Model\Website::ID_DEFAULT
                        || $websiteCode === \Weline\Websites\Model\Website::CODE_DEFAULT) {
                        throw new \DomainException(__('默认网站不允许删除'));
                    }

                    $websiteDomain = ObjectManager::getInstance(WebsiteDomain::class, [], false);
                    $websiteDomain->setConnection($connection);
                    $websiteDomain->clearQuery()
                        ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
                        ->delete()
                        ->fetch();
                    $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class, [], false);
                    $websiteCurrency->setConnection($connection);
                    $websiteCurrency->clearQuery()
                        ->where(WebsiteCurrency::schema_fields_WEBSITE_ID, $websiteId)
                        ->delete()
                        ->fetch();
                    $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class, [], false);
                    $websiteLanguage->setConnection($connection);
                    $websiteLanguage->clearQuery()
                        ->where(WebsiteLanguage::schema_fields_WEBSITE_ID, $websiteId)
                        ->delete()
                        ->fetch();
                    $this->saveStartPagePathConfig($websiteId, $websiteCode, '', $connection, true);

                    $domainPool = ObjectManager::getInstance(DomainPool::class);
                    $domainPool->setConnection($connection);
                    $domainPool->syncSiteCreatedFromWebsiteDomainTable();
                    $this->website->clearData()->setData($this->websiteCoreData($before))->delete();
                    $this->publishWebsiteChange(
                        $connection,
                        $websiteId,
                        'delete',
                        $before,
                        null,
                    );
                },
            );
            return $this->fetchJson([
                'code' => 200,
                'success' => true,
                'msg' => __('网站删除成功'),
                'reload' => '1',
                'url' => '*/admin/website',
                'time' => '3',
            ]);
        } catch (\DomainException $e) {
            return $this->fetchJson([
                'success' => false,
                'code' => 403,
                'msg' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'code' => 500,
                'msg' => __('网站删除失败: %{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取所有启用的货币
     *
     * @return array
     */
    private function stripExtensionPostData(array &$data): void
    {
        unset(
            $data['extensions']
        );
    }

    /**
     * Normalize default/related currency + language POST values.
     *
     * Multiple-select components may post arrays for a scalar field when a prior
     * Taglib leaked `multiple=true`; keep the first non-empty code and ensure the
     * default stays inside the related list.
     *
     * @param array<string, mixed> $data
     * @return array{0: list<string>, 1: list<string>}
     */
    private function normalizeWebsiteLocaleMoneyPost(array &$data): array
    {
        $currencyCodes = $this->normalizeWebsiteCodeList($data['currency_codes'] ?? [], true);
        $languageCodes = $this->normalizeWebsiteCodeList($data['language_codes'] ?? [], false);
        $defaultCurrency = $this->normalizeWebsiteScalarCode($data['default_currency'] ?? '', true);
        $defaultLanguage = $this->normalizeWebsiteScalarCode($data['default_language'] ?? '', false);

        if ($defaultCurrency === '' && $currencyCodes !== []) {
            $defaultCurrency = $currencyCodes[0];
        }
        if ($defaultLanguage === '' && $languageCodes !== []) {
            $defaultLanguage = $languageCodes[0];
        }
        if ($defaultCurrency !== '' && !\in_array($defaultCurrency, $currencyCodes, true)) {
            \array_unshift($currencyCodes, $defaultCurrency);
        }
        if ($defaultLanguage !== '' && !\in_array($defaultLanguage, $languageCodes, true)) {
            \array_unshift($languageCodes, $defaultLanguage);
        }

        $data['default_currency'] = $defaultCurrency !== '' ? $defaultCurrency : null;
        $data['default_language'] = $defaultLanguage !== '' ? $defaultLanguage : null;

        return [$currencyCodes, $languageCodes];
    }

    private function normalizeWebsiteScalarCode(mixed $value, bool $upper): string
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                $normalized = $this->normalizeWebsiteScalarCode($item, $upper);
                if ($normalized !== '') {
                    return $normalized;
                }
            }

            return '';
        }
        if (!\is_scalar($value)) {
            return '';
        }
        $code = \trim((string)$value);
        if ($code === '') {
            return '';
        }

        return $upper ? \strtoupper($code) : $code;
    }

    /**
     * @return list<string>
     */
    private function normalizeWebsiteCodeList(mixed $value, bool $upper): array
    {
        if (!\is_array($value)) {
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                $value = \preg_split('/[\s,]+/', \trim((string)$value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            } else {
                $value = [];
            }
        }
        $result = [];
        foreach ($value as $item) {
            $code = $this->normalizeWebsiteScalarCode($item, $upper);
            if ($code !== '' && !\in_array($code, $result, true)) {
                $result[] = $code;
            }
        }

        return $result;
    }

    private function normalizeStartPagePath(string $path): string
    {
        $path = trim($path, '/ ');
        if ($path === '') {
            return '';
        }

        foreach ($this->getStartPageRouteOptions() as $option) {
            if (($option['value'] ?? '') === $path) {
                return $path;
            }
        }

        throw new \Exception(__('首页入口路由无效'));
    }

    private function saveStartPagePathConfig(
        int $websiteId,
        string $websiteCode,
        string $path,
        ?ConnectionFactory $connection = null,
        bool $deferNamespaceInvalidation = false,
    ): void
    {
        $websiteCode = trim($websiteCode);
        if ($websiteId < 0 || $websiteCode === '') {
            throw new \InvalidArgumentException(__('网站身份无效'));
        }

        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        if ($connection !== null) {
            $systemConfig->useConnection($connection);
        }
        $commonOptions = [
            'defer_namespace_invalidation' => $deferNamespaceInvalidation,
            'scope_identity' => ScopeIdentity::website($websiteId, $websiteCode),
        ];
        if ($path === '') {
            $frontendResult = $systemConfig->deleteScopedConfig(
                key: self::FRONTEND_START_PAGE_CONFIG_KEY,
                module: self::FRONTEND_START_PAGE_CONFIG_MODULE,
                area: SystemConfig::area_FRONTEND,
                scope: SystemConfig::SCOPE_GLOBAL,
                locale: SystemConfig::LOCALE_DEFAULT,
                options: $commonOptions + ['operation' => 'website_front_start_page_inherit']
            );
            if ($frontendResult !== true) {
                throw new \RuntimeException(__('前台首页入口配置删除失败'));
            }
            $backendResult = $systemConfig->deleteScopedConfig(
                key: KeysInterface::key_start_page_path,
                module: KeysInterface::start_module,
                area: SystemConfig::area_BACKEND,
                scope: SystemConfig::SCOPE_GLOBAL,
                locale: SystemConfig::LOCALE_DEFAULT,
                options: $commonOptions + ['operation' => 'website_start_page_inherit']
            );
            if ($backendResult !== true) {
                throw new \RuntimeException(__('后台首页入口配置删除失败'));
            }
            return;
        }

        $frontendResult = $systemConfig->setScopedConfig(
            key: self::FRONTEND_START_PAGE_CONFIG_KEY,
            value: $path,
            module: self::FRONTEND_START_PAGE_CONFIG_MODULE,
            area: SystemConfig::area_FRONTEND,
            scope: SystemConfig::SCOPE_GLOBAL,
            locale: SystemConfig::LOCALE_DEFAULT,
            options: $commonOptions + ['operation' => 'website_frontend_start_page_save']
        );
        if ($frontendResult !== true) {
            throw new \RuntimeException(__('前台首页入口配置保存失败'));
        }
        $backendResult = $systemConfig->setScopedConfig(
            key: KeysInterface::key_start_page_path,
            value: $path,
            module: KeysInterface::start_module,
            area: SystemConfig::area_BACKEND,
            scope: SystemConfig::SCOPE_GLOBAL,
            locale: SystemConfig::LOCALE_DEFAULT,
            options: $commonOptions + ['operation' => 'website_start_page_save']
        );
        if ($backendResult !== true) {
            throw new \RuntimeException(__('后台首页入口配置保存失败'));
        }
    }

    private function getStartPagePathForWebsite(int $websiteId, string $websiteCode): string
    {
        $websiteCode = trim($websiteCode);
        if ($websiteId < 0 || $websiteCode === '') {
            return '';
        }

        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        $identity = ScopeIdentity::website($websiteId, $websiteCode);
        $value = $systemConfig->resolveTypedConfig(
            key: self::FRONTEND_START_PAGE_CONFIG_KEY,
            module: self::FRONTEND_START_PAGE_CONFIG_MODULE,
            area: SystemConfig::area_FRONTEND,
            identity: $identity,
            locale: SystemConfig::LOCALE_DEFAULT,
            default: '',
        )->value;
        if (is_scalar($value) && trim((string)$value) !== '') {
            return trim((string)$value, '/ ');
        }

        $value = $systemConfig->resolveTypedConfig(
            key: KeysInterface::key_start_page_path,
            module: KeysInterface::start_module,
            area: SystemConfig::area_BACKEND,
            identity: $identity,
            locale: SystemConfig::LOCALE_DEFAULT,
            default: '',
        )->value;

        return is_scalar($value) ? trim((string)$value, '/ ') : '';
    }

    /**
     * @return array<int, array{value: string, label: string, module: string, controller: string, method: string}>
     */
    private function getStartPageRouteOptions(): array
    {
        try {
            $routers = is_file(Env::path_FRONTEND_PC_ROUTER_FILE)
                ? (array)include Env::path_FRONTEND_PC_ROUTER_FILE
                : [];
        } catch (\Throwable) {
            $routers = [];
        }

        $options = [];
        $seen = [];
        foreach ($routers as $path => $router) {
            if (!is_array($router)) {
                continue;
            }

            $startPagePath = $this->extractStartPagePath((string)$path);
            if ($startPagePath === '' || isset($seen[$startPagePath])) {
                continue;
            }

            $module = (string)($router['module'] ?? '');
            $class = is_array($router['class'] ?? null) ? $router['class'] : [];
            $controller = (string)($class['controller_name'] ?? '');
            $method = (string)($class['method'] ?? '');
            $options[] = [
                'value' => $startPagePath,
                'label' => trim(($module !== '' ? $module . ' / ' : '') . $startPagePath),
                'module' => $module,
                'controller' => $controller,
                'method' => $method,
            ];
            $seen[$startPagePath] = true;
        }

        usort($options, static function (array $left, array $right): int {
            return [$left['module'] ?? '', $left['value'] ?? ''] <=> [$right['module'] ?? '', $right['value'] ?? ''];
        });

        return $options;
    }

    private function extractStartPagePath(string $path): string
    {
        if (str_contains($path, '::')) {
            if (!str_ends_with($path, '::GET')) {
                return '';
            }
            $path = str_replace('::GET', '', $path);
        }

        return trim($path, '/ ');
    }

    /**
     * @param array<string, mixed> $websiteData
     * @param array<string, mixed> $postData
     * @param array<int, array<string, string>> $addressList
     */
    private function dispatchWebsiteSaveAfter(
        int $websiteId,
        string $action,
        array $websiteData,
        array $postData,
        array $addressList = []
    ): void {
        if ($websiteId < \Weline\Websites\Model\Website::ID_DEFAULT) {
            throw new \InvalidArgumentException(__('website_id 不能为负数'));
        }

        $eventData = [
            'website_id' => $websiteId,
            'website' => $websiteData,
            'post_data' => $postData,
            'address_list' => $addressList,
            'action' => $action,
            // Same owner connection as the outer Website write intent — observers must not rent a second PDO.
            'connection' => $this->website->getConnection(),
        ];
        ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class)
            ->dispatch('Weline_Websites::website_save_after', $eventData);
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    private function publishWebsiteChange(
        ConnectionFactory $connection,
        int $websiteId,
        string $entryAction,
        ?array $before,
        ?array $after,
    ): ResourceChange {
        $snapshot = $after ?? $before;
        if ($snapshot === null) {
            throw new \LogicException(__('网站资源变更必须包含 before 或 after 快照'));
        }
        $websiteCode = trim((string)($snapshot[\Weline\Websites\Model\Website::schema_fields_CODE] ?? ''));
        if ($websiteCode === '') {
            throw new \LogicException(__('网站资源变更缺少 website code'));
        }

        $beforeCode = trim((string)($before[\Weline\Websites\Model\Website::schema_fields_CODE] ?? ''));
        $previousCode = $beforeCode !== ''
            && ($after === null || $beforeCode !== $websiteCode)
            ? $beforeCode
            : null;
        $impact = $this->snapshots()->impact($before, $after);
        $revision = ObjectManager::getInstance(ResourceRevisionService::class)->next('website', $websiteId);
        $change = ObjectManager::getInstance(ResourceChangeFactory::class)->create(
            resourceType: 'website',
            resourceId: $websiteId,
            action: $after === null ? 'delete' : 'upsert',
            revision: $revision,
            websiteId: $websiteId,
            websiteCode: $websiteCode,
            before: $before ?? [],
            after: $after,
            changedFields: $this->snapshots()->changedFields($before, $after),
            impact: $impact,
            origin: ['entry' => 'website.' . $entryAction],
            previousWebsiteCode: $previousCode,
            siteId: $websiteId,
        );
        w_changed($change);
        $this->cacheInvalidation()->flushDeferred(
            $connection,
            array_values(array_unique(array_merge(
                $impact['namespaces'],
                $impact['previous_namespaces'],
            ))),
        );
        return $change;
    }

    private function transactions(): WriteIntentTransactionCoordinatorInterface
    {
        return ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
    }

    private function snapshots(): WebsiteChangeSnapshotFactory
    {
        return ObjectManager::getInstance(WebsiteChangeSnapshotFactory::class);
    }

    private function cacheInvalidation(): WebsiteCacheInvalidationService
    {
        return ObjectManager::getInstance(WebsiteCacheInvalidationService::class);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function websiteCoreData(array $snapshot): array
    {
        return array_intersect_key($snapshot, array_flip([
            \Weline\Websites\Model\Website::schema_fields_ID,
            \Weline\Websites\Model\Website::schema_fields_NAME,
            \Weline\Websites\Model\Website::schema_fields_DESCRIPTION,
            \Weline\Websites\Model\Website::schema_fields_CODE,
            \Weline\Websites\Model\Website::schema_fields_URL,
            \Weline\Websites\Model\Website::schema_fields_DEFAULT_CURRENCY,
            \Weline\Websites\Model\Website::schema_fields_DEFAULT_LANGUAGE,
            \Weline\Websites\Model\Website::schema_fields_DEFAULT_TIMEZONE,
            \Weline\Websites\Model\Website::schema_fields_SCOPE,
        ]));
    }

    private function getAllCurrencies(): array
    {
        try {
            $result = [];
            foreach ($this->currencyCatalog()->active() as $currency) {
                $result[] = [
                    'code' => $currency->code,
                    'name' => $currency->name,
                ];
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 获取所有i18n支持的语言
     *
     * @return array
     */
    private function getAllLocales(): array
    {
        $targetCode = Cookie::getLangLocal();
        $locales = [];
        foreach ($this->localeRepository()->installedActive($targetCode) as $locale) {
            $locales[] = [
                'code' => $locale->code,
                'name' => $locale->displayName,
                'target_code' => $locale->displayLocale,
                'flag' => $locale->flag,
                'is_active' => $locale->active ? 1 : 0,
                'is_install' => $locale->installed ? 1 : 0,
            ];
        }

        if ($locales === []) {
            MessageManager::error(__('当前语言没有对应语言包翻译，请前往i18n模块对%{1}语言的地区语言进行更新', $targetCode));
        }

        return $locales;
    }

    private function localeRepository(): LocaleRepositoryInterface
    {
        $repository = $this->runtimeProviders->resolve(LocaleRepositoryInterface::class);
        if (!$repository instanceof LocaleRepositoryInterface) {
            throw new \RuntimeException('Weline_I18n locale repository provider is unavailable.');
        }

        return $repository;
    }

    private function currencyCatalog(): CurrencyCatalogInterface
    {
        $catalog = $this->runtimeProviders->resolve(CurrencyCatalogInterface::class);
        if (!$catalog instanceof CurrencyCatalogInterface) {
            throw new \RuntimeException('Weline_Currency catalog provider is unavailable.');
        }

        return $catalog;
    }

    /**
     * 获取域名选项（用于多选，来自域名池）
     */
    private function getDomainOptions(): array
    {
        try {
            $pool = ObjectManager::getInstance(DomainPool::class);
            return $pool->getSelectOptions();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 对地址列表排序：当同时存在根域与 www 时，www 排在前（作为主 URL）
     */
    private function orderAddressListPreferredUrl(array $addressList): array
    {
        $domains = array_column($addressList, 'domain');
        $hasWww = false;
        $hasRoot = false;
        foreach ($domains as $d) {
            if (str_starts_with($d, 'www.')) {
                $hasWww = true;
                $root = substr($d, 4);
                if (in_array($root, $domains, true)) {
                    $hasRoot = true;
                    break;
                }
            }
        }
        if (!$hasWww || !$hasRoot) {
            return $addressList;
        }
        usort($addressList, function ($a, $b) {
            $da = $a['domain'];
            $db = $b['domain'];
            $rootA = str_starts_with($da, 'www.') ? substr($da, 4) : $da;
            $rootB = str_starts_with($db, 'www.') ? substr($db, 4) : $db;
            if ($rootA !== $rootB) {
                return 0;
            }
            return str_starts_with($da, 'www.') ? -1 : 1;
        });
        return $addressList;
    }

    /**
     * 解析「网站地址」多行文本为 [['domain' => string, 'sub_path' => string, 'pool_id' => int], ...]
     * 每行：域名 或 域名/子路径（子路径自动加前导 /）
     * 自动去重：相同 domain + sub_path 组合只保留一个
     *
     * v1.6.0: 支持 pool_ids 参数，从域名池关联域名
     * - 如果提供了 pool_ids，优先使用 pool_id 关联
     * - pool_ids 格式：逗号分隔的 pool_id 列表
     *
     * @param string $text 多行地址文本
     * @param string $poolIds 逗号分隔的 pool_id 列表（可选）
     */
    private function parseAddressLines(string $text, string $poolIds = ''): array
    {
        $list = [];
        $seen = [];  // 用于去重

        // v1.6.0: 如果提供了 pool_ids，从域名池获取域名
        if (!empty($poolIds)) {
            $poolIdArray = array_filter(array_map('intval', explode(',', $poolIds)));
            if (!empty($poolIdArray)) {
                /** @var DomainPool $poolModel */
                $poolModel = ObjectManager::getInstance(DomainPool::class);
                foreach ($poolIdArray as $poolId) {
                    $pool = ObjectManager::getInstance(DomainPool::class, [], false);
                    $pool->loadByPoolId($poolId);
                    if ($pool->getPoolId()) {
                        $domain = strtolower($pool->getDomain());
                        $key = $domain . '|';
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $list[] = [
                                'domain' => $domain,
                                'sub_path' => '',
                                'pool_id' => $poolId,
                            ];
                        }
                    }
                }
            }
        }

        // 解析多行文本（传统方式）
        $lines = \preg_split('/\r\n|\r|\n/', $text, -1, \PREG_SPLIT_NO_EMPTY);
        foreach ($lines as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }
            // 去掉协议前缀（http:// 或 https://）
            $line = \preg_replace('#^https?://#i', '', $line);
            $line = \trim($line, "/ \t");
            if ($line === '') {
                continue;
            }
            $pos = \strpos($line, '/');
            if ($pos === false) {
                $domain = \strtolower($line);
                $subPath = '';
            } else {
                $domain = \strtolower(\substr($line, 0, $pos));
                $subPath = '/' . \trim(\substr($line, $pos), '/');
                if ($subPath === '/') {
                    $subPath = '';
                }
            }
            if ($domain !== '') {
                // 去重：相同 domain + sub_path 只保留一个
                $key = $domain . '|' . $subPath;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    if ($subPath !== '') {
                        $subPath = $this->assertValidSubPath($subPath);
                    }
                    $list[] = ['domain' => $domain, 'sub_path' => $subPath, 'pool_id' => 0];
                }
            }
        }
        return $list;
    }

    /**
     * 从域名池选择构建站点地址列表，所有选中域名共享同一个子路径。
     */
    private function buildAddressListFromPoolSelection(array|string $poolIds, string $subPath = ''): array
    {
        $list = [];
        $seen = [];
        $subPath = $this->assertValidSubPath($subPath);
        $poolIdArray = \is_array($poolIds)
            ? \array_values(\array_filter(\array_map('intval', $poolIds)))
            : \array_values(\array_filter(\array_map('intval', \explode(',', (string) $poolIds))));
        foreach ($poolIdArray as $poolId) {
            /** @var DomainPool $pool */
            $pool = ObjectManager::getInstance(DomainPool::class, [], false);
            $pool->loadByPoolId((int) $poolId);
            if (!$pool->getPoolId()) {
                continue;
            }
            $domain = \strtolower(\trim((string) $pool->getDomain()));
            if ($domain === '') {
                continue;
            }
            $key = $domain . '|' . $subPath;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $list[] = [
                'domain' => $domain,
                'sub_path' => $subPath,
                'pool_id' => (int) $poolId,
            ];
        }
        return $list;
    }

    private function assertValidSubPath(string $subPath): string
    {
        return $this->subPathValidator()->assertValid($subPath);
    }

    private function normalizeSubPath(string $subPath): string
    {
        return WebsiteSubPathValidator::normalize($subPath);
    }

    private function assignSubPathBanCatalog(): void
    {
        $validator = $this->subPathValidator();
        $this->assign('sub_path_banned_language_codes', $validator->languageCodes());
        $this->assign('sub_path_banned_currency_codes', $validator->currencyCodes());
    }

    private function subPathValidator(): WebsiteSubPathValidator
    {
        return WebsiteSubPathValidator::fromLocalizationRegistry();
    }

    /**
     * 域名转网站 code：小写，点换下划线
     */
    private function domainToCode(string $domain): string
    {
        return \str_replace('.', '_', \strtolower(\trim($domain)));
    }

    /**
     * 保存站点的域名列表（先删后增，第一个为主域名）
     *
     * v1.6.0: 支持 pool_id 关联方式
     * - 如果 item 包含 pool_id，优先使用 pool_id 关联并从 DomainPool 同步数据
     * - 否则使用传统的 domain 字符串方式
     */
    private function saveWebsiteDomains(int $websiteId, array $addressList): void
    {
        /** @var WebsiteDomain $model */
        $model = ObjectManager::getInstance(WebsiteDomain::class, [], false);
        $connection = $this->website->getConnection();
        $model->setConnection($connection);
        $model->clearQuery()
            ->where(WebsiteDomain::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();
        $isFirst = true;
        foreach ($addressList as $item) {
            /** @var WebsiteDomain $newDomain */
            $newDomain = ObjectManager::getInstance(WebsiteDomain::class, [], false);
            $newDomain->setConnection($connection);
            $newDomain->setWebsiteId($websiteId);

            // v1.6.0: 支持 pool_id 关联
            $poolId = (int)($item['pool_id'] ?? 0);
            if ($poolId > 0) {
                $newDomain->setPoolId($poolId);
                $newDomain->syncFromPool();
            } else {
                $newDomain->setDomain($item['domain']);
            }

            $newDomain->setSubPath($item['sub_path']);
            $newDomain->setIsPrimary($isFirst);
            $newDomain->setStatus(WebsiteDomain::STATUS_ACTIVE);
            $newDomain->save();
            $isFirst = false;
        }
        // 同步域名池 site_created 状态（已建站的域名创建站点时不再展示）
        $pool = ObjectManager::getInstance(DomainPool::class);
        $pool->setConnection($connection);
        $pool->syncSiteCreatedFromWebsiteDomainTable();
    }

    /**
     * 将站点已有域名格式化为多行文本（用于编辑页 address_lines）
     */
    private function getPrimarySubPathForWebsite(int $websiteId): string
    {
        /** @var WebsiteDomain $model */
        $model = ObjectManager::getInstance(WebsiteDomain::class);
        $rows = $model->getWebsiteDomains($websiteId);
        foreach ($rows as $row) {
            $isPrimary = (bool) ($row[WebsiteDomain::schema_fields_IS_PRIMARY] ?? false);
            if ($isPrimary) {
                return $this->normalizeSubPath((string) ($row[WebsiteDomain::schema_fields_SUB_PATH] ?? ''));
            }
        }
        $first = $rows[0][WebsiteDomain::schema_fields_SUB_PATH] ?? '';
        return $this->normalizeSubPath((string) $first);
    }

    /**
     * @param array{
     *     kind:string,
     *     id:int,
     *     node:string,
     *     acl_ok:bool,
     *     website_id:int,
     *     store_id:int,
     *     channel_id:int,
     *     entity:array<string,mixed>,
     *     error:string
     * } $selection
     */
    private function prepareTreeEditor(array $selection): void
    {
        $kind = (string)$selection['kind'];
        if ($kind === 'website' && $selection['acl_ok']) {
            $websiteId = (int)$selection['website_id'];
            $this->website->load($websiteId);
            if (!$this->website->hasData(\Weline\Websites\Model\Website::schema_fields_ID)) {
                $this->assign('editor_error', (string)__('网站不存在'));
                $this->assign('editor_acl_ok', false);
                return;
            }
            $this->assignWebsiteEditorFormData($websiteId);
            return;
        }

        /** @var StoreChannelAdminService $admin */
        $admin = ObjectManager::getInstance(StoreChannelAdminService::class);

        if ($kind === 'store') {
            $row = $admin->getStore((int)$selection['store_id']);
            $this->assign('entity', $row ?? []);
            $this->assign('website_id', (int)$selection['website_id']);
            $this->assign('error', $row === null ? (string)__('商店不存在') : (string)$selection['error']);
            $this->assign('tree_embed', true);
            return;
        }

        if ($kind === 'channel') {
            $row = $admin->getChannel((int)$selection['channel_id']);
            $this->assign('entity', $row ?? []);
            $this->assign('website_id', (int)$selection['website_id']);
            $this->assign('error', $row === null ? (string)__('渠道不存在') : (string)$selection['error']);
            $this->assign('tree_embed', true);
            return;
        }

        if ($kind === 'new_store') {
            $this->assign('website_id', (int)$selection['website_id']);
            $this->assign('tree_embed', true);
            return;
        }

        if ($kind === 'new_channel') {
            $this->assign('website_id', (int)$selection['website_id']);
            $this->assign('store_id', (int)$selection['store_id']);
            $this->assign('tree_embed', true);
        }
    }

    private function assignWebsiteEditorFormData(int $websiteId): void
    {
        $selectedCurrencies = [];
        $selectedLanguages = [];

        try {
            $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
            $selectedCurrencies = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
        } catch (\Exception $e) {
            $selectedCurrencies = [];
        }

        try {
            $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
            $selectedLanguages = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
        } catch (\Exception $e) {
            $selectedLanguages = [];
        }

        $websiteData = $this->website->getData();
        $this->assign('website', $websiteData);
        $this->assign('selected_currencies', $selectedCurrencies);
        $this->assign('selected_languages', $selectedLanguages);
        $selectedPoolIds = [];
        $selectedDomainNames = [];
        try {
            $websiteDomain = ObjectManager::getInstance(WebsiteDomain::class);
            $domains = $websiteDomain->getWebsiteDomains($websiteId);
            foreach ($domains as $domain) {
                $poolId = (int)($domain[WebsiteDomain::schema_fields_POOL_ID] ?? 0);
                if ($poolId > 0) {
                    $selectedPoolIds[] = $poolId;
                }
                $domainName = strtolower(trim((string)($domain[WebsiteDomain::schema_fields_DOMAIN] ?? '')));
                if ($domainName !== '' && !in_array($domainName, $selectedDomainNames, true)) {
                    $selectedDomainNames[] = $domainName;
                }
            }
        } catch (\Exception $e) {
            $selectedPoolIds = [];
            $selectedDomainNames = [];
        }
        $this->assign('selected_pool_ids', $selectedPoolIds);
        $this->assign('selected_domain_names', $selectedDomainNames);
        $this->assign('domain_options', $this->getDomainOptions());
        $this->assign('sub_path', $this->getPrimarySubPathForWebsite($websiteId));
        $this->assign('start_page_route_options', $this->getStartPageRouteOptions());
        $this->assign(
            'selected_start_page_path',
            $this->getStartPagePathForWebsite(
                $websiteId,
                (string)($websiteData['code'] ?? ''),
            ),
        );
        $this->assign('store_channel_directory', $this->storeChannelDirectory->forWebsite($websiteId));
        $this->assignSubPathBanCatalog();
        $this->assign('currencies', $this->getAllCurrencies());
        $this->assign('locales', $this->getAllLocales());
        $timezones = \DateTimeZone::listIdentifiers();
        sort($timezones);
        $this->assign('timezones', $timezones);
    }

    /**
     * Api / XHR / worker 请求走 JSON，禁止 302（避免 redirect:manual 触发无限重试风暴）。
     * OffCanvas iframe 普通表单仍走 redirect 结果页。
     */
    private function prefersApiJsonResponse(): bool
    {
        if ($this->request->isAjax()) {
            return true;
        }

        $server = $this->request->getServerBag();
        $apiFlag = strtolower(trim((string)$server->getHeader('X-Weline-Api', '')));
        if ($apiFlag === '1' || $apiFlag === 'true') {
            return true;
        }

        $accept = strtolower(trim((string)$server->getHeader('Accept', '')));
        if ($accept !== ''
            && str_contains($accept, 'application/json')
            && !str_contains($accept, 'text/html')
        ) {
            return true;
        }

        // Dedicated Worker 代发时 Referer 指向 weline-api-worker.js；无需等客户端 JS 热更新即可止血。
        $referer = strtolower(trim((string)$server->getHeader('Referer', '')));
        if ($referer !== '' && str_contains($referer, 'weline-api-worker.js')) {
            return true;
        }

        return false;
    }

    private function respondWebsiteOffcanvasError(string $message, string $reload = '0', string $time = '3'): void
    {
        if ($this->prefersApiJsonResponse()) {
            $this->noteApiWebsiteMutationCall('error');
            $this->fetchJson([
                'success' => false,
                'message' => $message,
                'reload' => $reload === '1',
            ]);
            return;
        }

        $this->redirect('component/backend/offcanvas/getError', [
            'msg' => $message,
            'reload' => $reload,
            'time' => $time,
        ]);
    }

    private function noteApiWebsiteMutationCall(string $phase): void
    {
        static $logged = false;
        if ($logged) {
            return;
        }
        $logged = true;

        $server = $this->request->getServerBag();
        w_log_warning(sprintf(
            '[Websites] Api-style website mutation (%s): method=%s uri=%s referer=%s ua=%s x-weline-api=%s',
            $phase,
            (string)$this->request->getMethod(),
            (string)$server->get('REQUEST_URI', ''),
            (string)$server->getHeader('Referer', ''),
            (string)$server->getHeader('User-Agent', ''),
            (string)$server->getHeader('X-Weline-Api', ''),
        ));
    }

    private function finishWebsiteMutation(string $successMsg, string $errorMsg, ?int $websiteId, bool $isError): void
    {
        $returnTo = trim((string)$this->request->getPost('return_to', ''));
        $returnNode = trim((string)$this->request->getPost('return_node', ''));
        $message = $isError
            ? ($errorMsg !== '' ? $errorMsg : (string)__('操作失败'))
            : ($successMsg !== '' ? $successMsg : (string)__('操作成功'));

        if ($this->prefersApiJsonResponse()) {
            $this->noteApiWebsiteMutationCall($isError ? 'error' : 'success');
            $payload = [
                'success' => !$isError,
                'message' => $message,
                'website_id' => $websiteId,
            ];
            if ($returnTo === 'tree') {
                $params = [];
                if ($returnNode !== '') {
                    $params['node'] = $returnNode;
                } elseif ($websiteId !== null) {
                    $params['node'] = WebsiteScopeTreeService::formatNode('website', $websiteId);
                }
                // Success: flash once after reload. Error: JSON toast only — do not also
                // MessageManager+reload (that stacked two identical「错误！」toasts).
                if ($isError) {
                    $payload['reload'] = false;
                } else {
                    $this->getMessageManager()->addSuccess($message);
                    $payload['reload'] = true;
                    $payload['redirect_url'] = $this->resolveTreeReturnTarget((string)($params['node'] ?? ''));
                }
            } else {
                $payload['reload'] = !$isError;
                if (!$isError) {
                    $payload['redirect_url'] = $this->getUrl($this->websitesAdminWebsiteIndexPath());
                }
            }
            $this->fetchJson($payload);
            return;
        }

        if ($returnTo === 'tree') {
            if ($isError) {
                $this->getMessageManager()->addError($message);
            } else {
                $this->getMessageManager()->addSuccess($message);
            }
            $params = [];
            if ($returnNode !== '') {
                $params['node'] = $returnNode;
            } elseif ($websiteId !== null) {
                $params['node'] = WebsiteScopeTreeService::formatNode('website', $websiteId);
            }
            $this->redirect($this->resolveTreeReturnTarget((string)($params['node'] ?? '')));
            return;
        }

        if ($isError) {
            $this->redirect('component/backend/offcanvas/getError', [
                'msg' => $message,
                'url' => '/',
                'reload' => '0',
                'time' => '10',
            ]);
            return;
        }

        $this->redirect('component/backend/offcanvas/getSuccess', [
            'msg' => $message,
            'url' => $this->websitesAdminWebsiteIndexPath(),
            'reload' => '1',
            'time' => '3',
        ]);
    }

    /**
     * 树编辑保存后回跳路径：{router}/admin/website，并保留当前模块 frontName。
     */
    private function websitesAdminWebsiteIndexPath(): string
    {
        $router = trim((string)($this->request->getRouterData('router') ?? ''));
        if ($router === '') {
            $router = 'websites';
        }

        return $router . '/admin/website';
    }

    /**
     * 优先用表单带回的 return_url（渲染时的浏览器地址，含语种段），否则再拼 getBackendUrl。
     * 避免 POST 落到 /USD/.../edit 后 State 前缀变成 en_US，302 丢掉 zh_Hans_CN。
     */
    private function resolveTreeReturnTarget(string $node): string
    {
        $posted = trim((string)$this->request->getPost('return_url', ''));
        if ($posted !== '' && $this->isSafeWebsitesTreeReturnUrl($posted)) {
            return $this->withTreeNodeQuery($posted, $node);
        }

        $params = [];
        if ($node !== '') {
            $params['node'] = $node;
        }

        return $this->getUrl($this->websitesAdminWebsiteIndexPath(), $params);
    }

    /**
     * 用当前页 ORIGIN_REQUEST_URI 拼树深链，保留地址栏语种/货币段。
     */
    private function buildLocalePreservingWebsitesTreeUrl(string $node): string
    {
        try {
            $current = (string)$this->request->getUrlBuilder()->getCurrentUrl([], false);
        } catch (\Throwable) {
            $current = '';
        }
        if ($current !== '' && $this->isSafeWebsitesTreeReturnUrl($current)) {
            return $this->withTreeNodeQuery($current, $node);
        }

        $params = [];
        if ($node !== '') {
            $params['node'] = $node;
        }

        return (string)$this->getUrl($this->websitesAdminWebsiteIndexPath(), $params);
    }

    private function withTreeNodeQuery(string $url, string $node): string
    {
        if ($node === '') {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        $query['node'] = $node;
        $rebuild = '';
        if (!empty($parts['scheme'])) {
            $rebuild .= $parts['scheme'] . '://';
        }
        if (!empty($parts['host'])) {
            $rebuild .= $parts['host'];
            if (isset($parts['port'])) {
                $rebuild .= ':' . (int)$parts['port'];
            }
        }
        $rebuild .= (string)($parts['path'] ?? '');
        $rebuild .= '?' . http_build_query($query);

        return $rebuild;
    }

    private function isSafeWebsitesTreeReturnUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);

            return (bool)preg_match('#/websites/admin/website(?:/index)?$#', rtrim($path, '/'));
        }

        if (!preg_match('#^https?://#i', $url)) {
            return (bool)preg_match('#^websites/admin/website(?:/index)?(?:\?|$)#', $url);
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        try {
            $reqHost = (string)(parse_url(
                (string)$this->request->getUrlBuilder()->getCurrentUrl([], false),
                PHP_URL_HOST
            ) ?: '');
        } catch (\Throwable) {
            $reqHost = '';
        }
        if ($reqHost === '' || strcasecmp((string)$parts['host'], $reqHost) !== 0) {
            return false;
        }

        $path = rtrim((string)($parts['path'] ?? ''), '/');

        return (bool)preg_match('#/websites/admin/website(?:/index)?$#', $path);
    }
}
