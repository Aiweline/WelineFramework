<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Admin;

use Weline\Backend\Api\Config\KeysInterface;
use Weline\Currency\Api\CurrencyCatalogInterface;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
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
use Weline\Websites\Service\WebsiteBackendEntryBridgeService;
use Weline\Websites\Service\WebsiteCacheInvalidationService;
use Weline\Websites\Service\WebsiteChangeSnapshotFactory;
use Weline\Websites\Service\WebsiteEntryUrlService;
use Weline\Websites\Service\WebsiteStoreChannelDirectory;

#[Acl('Weline_Websites::website', '网站管理', 'mdi mdi-web', '网站管理', 'Weline_Websites::website_service')]
class Website extends BackendController
{
    private const FRONTEND_START_PAGE_CONFIG_KEY = 'frontend_start_page_path';
    private const FRONTEND_START_PAGE_CONFIG_MODULE = 'Weline_Websites';

    private \Weline\Websites\Model\Website $website;

    public function __construct(
        \Weline\Websites\Model\Website $website,
        private readonly RuntimeProviderResolver $runtimeProviders,
        private readonly WebsiteStoreChannelDirectory $storeChannelDirectory,
    ) {
        $this->website = $website;
    }

    #[Acl('Weline_Websites::website_list', '网站列表', 'mdi mdi-view-list', '网站管理')]
    public function index()
    {
        // 保留既有 AJAX 响应兼容面；当前官方模板只使用普通 GET 页面搜索。
        if ($this->request->isAjax()) {
            return $this->searchAjax();
        }

        // 搜索功能
        $search = trim((string)$this->request->getGet('search', ''));
        $websiteModel = $this->createWebsiteListingModel();
        $this->applyWebsiteSearch($websiteModel, $search);

        $websites = $websiteModel->order()->pagination()->select()->fetch();
        $items = $websites->getItems();

        $this->enrichWebsiteListingItems($items);

        $this->assign('websites', $items);
        $this->assign('pagination', $websites->getPagination());
        $this->assign('search', $search);
        return $this->fetch();
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

            $this->assign('websites', $items);
            $this->assign('pagination', $websites->getPagination());
            $this->assign('search', $search);
            $tableHtml = $this->template('Weline_Websites::templates/Admin/Website/table.phtml');
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

    #[Acl('Weline_Websites::website_enter_backend', '直进子站后台', 'mdi mdi-login-variant', '主站直进已授权子站后台', 'Weline_Websites::website_list')]
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

    #[Acl('Weline_Websites::website_add', '添加网站', 'mdi mdi-plus', '网站管理')]
    public function add()
    {
        // 使用空白布局（适用于 offcanvas/弹窗）
        $this->layoutType = 'default.blank';

        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $postData = $data;
            try {
                $poolIds = $data['pool_ids'] ?? '';
                $subPath = $this->normalizeSubPath((string)($data['sub_path'] ?? ''));
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

                // 处理关联货币和语言
                $currencyCodes = $data['currency_codes'] ?? [];
                $languageCodes = $data['language_codes'] ?? [];

                if (empty($data['default_currency']) && !empty($currencyCodes)) {
                    $data['default_currency'] = $currencyCodes[0];
                }
                if (empty($data['default_language']) && !empty($languageCodes)) {
                    $data['default_language'] = $languageCodes[0];
                }
                $startPagePath = $this->normalizeStartPagePath((string)($data['start_page_path'] ?? ''));

                if (isset($data['website_id'])) {
                    unset($data['website_id']);
                }
                unset($data['address_lines'], $data['domain_values'], $data['pool_ids'], $data['sub_path'], $data['start_page_path']);
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

                $this->redirect('component/backend/offcanvas/getSuccess', [
                    'msg' => __('网站添加成功'),
                    'url' => '*/admin/website',
                    'reload' => '1',
                    'time' => '3',
                ]);
            } catch (\Throwable $e) {
                $errorMsg = $e->getMessage();
                // 开发环境显示完整堆栈
                if (DEV) {
                    $errorMsg .= "\n\n[File] " . $e->getFile() . ':' . $e->getLine();
                }
                $this->redirect('component/backend/offcanvas/getError', [
                    'msg' => __('网站添加失败: %{1}', [$errorMsg]),
                    'url' => '/',
                    'reload' => '0',
                    'time' => '10',
                ]);
            }
        }

        // 初始化空网站数据，避免模板中访问未定义变量
        $this->assign('website', []);
        $this->assign('selected_currencies', []);
        $this->assign('selected_languages', []);
        $this->assign('selected_pool_ids', []);
        $this->assign('domain_options', $this->getDomainOptions());
        $this->assign('sub_path', '');
        $this->assign('start_page_route_options', $this->getStartPageRouteOptions());
        $this->assign('selected_start_page_path', '');
        $this->assign('store_channel_directory', []);

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

    #[Acl('Weline_Websites::website_edit', '编辑网站', 'mdi mdi-pencil', '网站管理')]
    public function edit()
    {
        // 使用空白布局（适用于 offcanvas/弹窗）
        $this->layoutType = 'default.blank';

        try {
            $websiteId = $this->requireWebsiteId($this->request->getParam('id'));
        } catch (\InvalidArgumentException $exception) {
            $this->redirect('component/backend/offcanvas/getError', [
                'msg' => $exception->getMessage(),
                'reload' => '0',
                'time' => '3',
            ]);
            return;
        }

        $this->website->load($websiteId);

        // 检查网站是否存在
        if (!$this->website->hasData(\Weline\Websites\Model\Website::schema_fields_ID)) {
            $this->redirect('component/backend/offcanvas/getError', [
                'msg' => __('网站不存在'),
                'reload' => '0',
                'time' => '3',
            ]);
            return;
        }

        if ($this->request->isPost()) {
            $data = $this->request->getPost();
            $postData = $data;

            // 从 POST 数据中获取 website_id，如果没有则从 URL 参数中获取 id，最后使用已加载的 websiteId
            $postWebsiteId = $data['website_id'] ?? null;
            if ($postWebsiteId === null || $postWebsiteId === '') {
                $postWebsiteId = $this->request->getParam('id');
            }
            if ($postWebsiteId === null || $postWebsiteId === '') {
                $postWebsiteId = $websiteId;
            }

            try {
                $postWebsiteId = $this->requireWebsiteId($postWebsiteId);
                if ($postWebsiteId !== $websiteId) {
                    throw new \InvalidArgumentException(__('网站ID与当前编辑目标不一致'));
                }
                $poolIds = $data['pool_ids'] ?? '';
                $subPath = $this->normalizeSubPath((string)($data['sub_path'] ?? ''));
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

                // 处理关联货币和语言
                $currencyCodes = $data['currency_codes'] ?? [];
                $languageCodes = $data['language_codes'] ?? [];

                if (empty($data['default_currency']) && !empty($currencyCodes)) {
                    $data['default_currency'] = $currencyCodes[0];
                }
                if (empty($data['default_language']) && !empty($languageCodes)) {
                    $data['default_language'] = $languageCodes[0];
                }
                $startPagePath = $this->normalizeStartPagePath((string)($data['start_page_path'] ?? ''));

                $data['website_id'] = $postWebsiteId;
                unset($data['address_lines'], $data['domain_values'], $data['pool_ids'], $data['sub_path'], $data['start_page_path']);
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
                            $this->website->getCode(),
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

                $this->redirect('component/backend/offcanvas/getSuccess', [
                    'msg' => __('网站更新成功'),
                    'url' => '*/admin/website',
                    'reload' => '1',
                    'time' => '3',
                ]);
            } catch (\Throwable $e) {
                $this->redirect('component/backend/offcanvas/getError', [
                    'msg' => $e->getMessage(),
                    'reload' => '0',
                    'time' => '5',
                ]);
            }
        }

        // 获取网站的关联货币和语言
        $selectedCurrencies = [];
        $selectedLanguages = [];

        try {
            $websiteCurrency = ObjectManager::getInstance(WebsiteCurrency::class);
            $selectedCurrencies = $websiteCurrency->getWebsiteCurrencyCodes($websiteId);
        } catch (\Exception $e) {
            // 如果关联表不存在，使用空数组
            $selectedCurrencies = [];
        }

        try {
            $websiteLanguage = ObjectManager::getInstance(WebsiteLanguage::class);
            $selectedLanguages = $websiteLanguage->getWebsiteLanguageCodes($websiteId);
        } catch (\Exception $e) {
            // 如果关联表不存在，使用空数组
            $selectedLanguages = [];
        }

        $websiteData = $this->website->getData();
        $this->assign('website', $websiteData);
        $this->assign('selected_currencies', $selectedCurrencies);
        $this->assign('selected_languages', $selectedLanguages);
        $selectedPoolIds = [];
        try {
            $websiteDomain = ObjectManager::getInstance(WebsiteDomain::class);
            $domains = $websiteDomain->getWebsiteDomains($websiteId);
            foreach ($domains as $domain) {
                $poolId = (int)($domain[WebsiteDomain::schema_fields_POOL_ID] ?? 0);
                if ($poolId > 0) {
                    $selectedPoolIds[] = $poolId;
                }
            }
        } catch (\Exception $e) {
            $selectedPoolIds = [];
        }
        $this->assign('selected_pool_ids', $selectedPoolIds);
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

    #[Acl('Weline_Websites::website_delete', '删除网站', 'mdi mdi-delete', '网站管理')]
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
        $subPath = $this->normalizeSubPath($subPath);
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

    private function normalizeSubPath(string $subPath): string
    {
        $subPath = \trim($subPath);
        if ($subPath === '' || $subPath === '/') {
            return '';
        }
        $subPath = '/' . \trim($subPath, '/');
        return $subPath === '/' ? '' : $subPath;
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
}
