<?php
declare(strict_types=1);

/**
 * 域名管理控制器
 *
 * 域名服务主控制器，提供 Tab 页面管理：
 * - Tab1：域名商管理（域名商账号 CRUD、测试连接）
 * - Tab2：域名购买（批量检查可用性、批量购买、绑定站点）
 * - Tab3：证书管理（通过 Hook 由 Server 模块注入）
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Controller\Admin;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\DomainRegistrarResolverService;

#[Acl('Weline_Websites::domain_service', '域名服务', 'mdi mdi-domain', '域名服务管理')]
class Domain extends BackendController
{
    private DomainRegistrar $registrar;
    private DomainRegistrarAccount $registrarAccount;
    private DomainRegistrarResolverService $resolverService;

    public function __construct(
        DomainRegistrar $registrar,
        DomainRegistrarAccount $registrarAccount,
        DomainRegistrarResolverService $resolverService
    ) {
        $this->registrar = $registrar;
        $this->registrarAccount = $registrarAccount;
        $this->resolverService = $resolverService;
    }

    // ============================================================
    // 域名管理主页
    // ============================================================

    /**
     * 域名管理主页（Tab 布局）
     */
    #[Acl('Weline_Websites::domain_index', '域名管理', 'mdi mdi-domain', '域名管理首页')]
    public function index()
    {
        // 获取已注册的适配器列表（供前端下拉选择使用）
        $adapterOptions = $this->resolverService->getAdapterOptions();
        $this->assign('adapter_options', $adapterOptions);

        // 获取所有账号（含域名商信息）
        $accounts = $this->registrarAccount->getAccountsWithRegistrar();
        $this->assign('accounts', $accounts);

        // 获取所有域名商
        $registrars = $this->registrar->getAllRegistrars();
        $this->assign('registrars', $registrars);

        // 获取网站列表（供购买时绑定选择）
        $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
        $websites = $websiteModel->clearQuery()->order('name', 'ASC')->select()->fetchArray();
        $this->assign('websites', $websites);

        // 当前 Tab
        $this->assign('active_tab', $this->request->getGet('tab', 'registrar'));

        return $this->fetch();
    }

    // ============================================================
    // 域名商管理 API
    // ============================================================

    /**
     * 获取域名商列表（AJAX）
     */
    public function getRegistrars()
    {
        try {
            $registrars = $this->registrar->getAllRegistrars();
            return $this->fetchJson([
                'code' => 200,
                'data' => $registrars,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取域名商列表失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取某域名商的账号列表（AJAX）
     */
    public function getAccounts()
    {
        try {
            $registrarId = (int) $this->request->getGet('registrar_id', 0);
            if ($registrarId > 0) {
                $accounts = $this->registrarAccount->getAccountsByRegistrarId($registrarId);
            } else {
                $accounts = $this->registrarAccount->getAccountsWithRegistrar();
            }
            return $this->fetchJson([
                'code' => 200,
                'data' => $accounts,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取账号列表失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 保存域名商账号（新增或编辑）
     */
    public function postSaveAccount()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            $registrarCode = (string) $this->request->getPost('registrar_code', '');
            $accountName = (string) $this->request->getPost('account_name', '');
            $apiKey = (string) $this->request->getPost('api_key', '');
            $apiSecret = (string) $this->request->getPost('api_secret', '');
            $region = (string) $this->request->getPost('region', '');
            $extraConfig = (string) $this->request->getPost('extra_config', '');
            $status = (string) $this->request->getPost('status', DomainRegistrarAccount::STATUS_ACTIVE);

            if (empty($registrarCode) || empty($accountName)) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名商渠道和账号名称不能为空'),
                ]);
            }

            // 查找或创建域名商记录
            $registrarModel = clone $this->registrar;
            $registrarModel->loadByCode($registrarCode);
            if (!$registrarModel->getRegistrarId()) {
                // 从适配器获取信息并自动创建
                $adapter = $this->resolverService->getAdapter($registrarCode);
                if (!$adapter) {
                    return $this->fetchJson([
                        'code' => 400,
                        'msg' => __('未找到域名商适配器：%{code}', ['code' => $registrarCode]),
                    ]);
                }
                $registrarModel->clearData();
                $registrarModel->setCode($registrarCode);
                $registrarModel->setName($adapter->getRegistrarName());
                $registrarModel->setDescription($adapter->getDescription());
                $registrarModel->setStatus(DomainRegistrar::STATUS_ACTIVE);
                $registrarModel->save();
            }

            // 保存账号
            $accountModel = clone $this->registrarAccount;
            if ($accountId > 0) {
                $accountModel->load($accountId);
                if (!$accountModel->getAccountId()) {
                    return $this->fetchJson([
                        'code' => 404,
                        'msg' => __('账号不存在'),
                    ]);
                }
            }

            $accountModel->setRegistrarId($registrarModel->getRegistrarId());
            $accountModel->setAccountName($accountName);

            // 仅在提供了新值时更新凭据（编辑时可能不改密码）
            if (!empty($apiKey)) {
                $accountModel->setApiKey($apiKey);
            }
            if (!empty($apiSecret)) {
                $accountModel->setApiSecret($apiSecret);
            }

            $accountModel->setRegion($region);
            $accountModel->setStatus($status);

            if (!empty($extraConfig)) {
                $decoded = \json_decode($extraConfig, true);
                if (\is_array($decoded)) {
                    $accountModel->setExtraConfig($decoded);
                }
            }

            $accountModel->save();

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功'),
                'data' => [
                    'account_id' => $accountModel->getAccountId(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('保存失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 删除域名商账号
     */
    public function deleteAccount()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            if ($accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('账号 ID 不能为空'),
                ]);
            }

            $accountModel = clone $this->registrarAccount;
            $accountModel->load($accountId);
            if (!$accountModel->getAccountId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('账号不存在'),
                ]);
            }

            $accountModel->delete()->fetch();

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('删除成功'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('删除失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 测试 API 连通性
     */
    public function postTestConnection()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            $registrarCode = (string) $this->request->getPost('registrar_code', '');

            // 获取适配器
            $adapter = $this->resolverService->getAdapter($registrarCode);
            if (!$adapter) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('未找到域名商适配器：%{code}', ['code' => $registrarCode]),
                ]);
            }

            // 获取凭据
            $credentials = [];
            if ($accountId > 0) {
                $accountModel = clone $this->registrarAccount;
                $accountModel->load($accountId);
                if ($accountModel->getAccountId()) {
                    $credentials = $accountModel->getCredentials();
                }
            } else {
                // 从 POST 参数获取临时凭据
                $credentials = [
                    'api_key' => (string) $this->request->getPost('api_key', ''),
                    'api_secret' => (string) $this->request->getPost('api_secret', ''),
                    'region' => (string) $this->request->getPost('region', ''),
                    'extra' => [],
                ];
            }

            $result = $adapter->testConnection($credentials);

            return $this->fetchJson([
                'code' => $result ? 200 : 400,
                'msg' => $result ? __('连接测试成功') : __('连接测试失败'),
            ]);
        } catch (\RuntimeException $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('连接测试失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('连接测试异常：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取适配器列表（供前端下拉使用，AJAX）
     */
    public function getAdapterOptions()
    {
        try {
            $options = $this->resolverService->getAdapterOptions();
            return $this->fetchJson([
                'code' => 200,
                'data' => $options,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取适配器列表失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    // ============================================================
    // 域名购买 API
    // ============================================================

    /**
     * 批量检查域名可用性（AJAX）
     */
    public function postCheckAvailability()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            $domainsText = (string) $this->request->getPost('domains', '');

            if (empty($domainsText) || $accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择域名商账号并输入域名'),
                ]);
            }

            // 解析域名列表
            $domains = \array_filter(\array_map('trim', \explode("\n", $domainsText)));
            if (empty($domains)) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('域名列表不能为空'),
                ]);
            }

            // 获取账号和适配器
            $accountModel = clone $this->registrarAccount;
            $accountModel->load($accountId);
            if (!$accountModel->getAccountId()) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('域名商账号不存在'),
                ]);
            }

            $registrarModel = clone $this->registrar;
            $registrarModel->load($accountModel->getRegistrarId());
            $adapter = $this->resolverService->getAdapter($registrarModel->getCode());
            if (!$adapter) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('未找到对应的域名商适配器'),
                ]);
            }

            $credentials = $accountModel->getCredentials();
            $results = $adapter->batchCheckAvailability($domains, $credentials);

            return $this->fetchJson([
                'code' => 200,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('检查可用性失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 提交批量购买（AJAX）
     */
    public function postPurchase()
    {
        try {
            $accountId = (int) $this->request->getPost('account_id', 0);
            $items = $this->request->getPost('items', []);

            if (empty($items) || $accountId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('请选择域名商账号并添加购买域名'),
                ]);
            }

            // 获取购买服务
            $purchaseService = ObjectManager::getInstance(
                \Weline\Websites\Service\DomainPurchaseService::class
            );

            $result = $purchaseService->createAndProcessOrder($accountId, $items);

            return $this->fetchJson([
                'code' => $result['success'] ? 200 : 400,
                'msg' => $result['message'],
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('购买失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取购买订单列表（AJAX）
     */
    public function getOrders()
    {
        try {
            $orderModel = ObjectManager::getInstance(
                \Weline\Websites\Model\DomainPurchaseOrder::class
            );
            $orders = $orderModel->clearQuery()
                ->order('created_at', 'DESC')
                ->pagination()
                ->select()
                ->fetch();

            return $this->fetchJson([
                'code' => 200,
                'data' => $orders->getItems(),
                'pagination' => $orders->getPagination(),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取订单列表失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 获取订单详情（AJAX）
     */
    public function getOrderDetail()
    {
        try {
            $orderId = (int) $this->request->getGet('order_id', 0);
            if ($orderId <= 0) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('订单 ID 不能为空'),
                ]);
            }

            $orderModel = ObjectManager::getInstance(
                \Weline\Websites\Model\DomainPurchaseOrder::class
            );
            $orderModel->load($orderId);
            if (!$orderModel->getData('order_id')) {
                return $this->fetchJson([
                    'code' => 404,
                    'msg' => __('订单不存在'),
                ]);
            }

            $itemModel = ObjectManager::getInstance(
                \Weline\Websites\Model\DomainPurchaseItem::class
            );
            $items = $itemModel->clearQuery()
                ->where('order_id', $orderId)
                ->select()
                ->fetchArray();

            return $this->fetchJson([
                'code' => 200,
                'data' => [
                    'order' => $orderModel->getData(),
                    'items' => $items,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('获取订单详情失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
