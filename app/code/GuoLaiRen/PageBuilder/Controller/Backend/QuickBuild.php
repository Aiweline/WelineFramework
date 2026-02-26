<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Service\QuickBuildAggregator;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

/**
 * @DESC | 快速建站向导控制器
 */
#[Acl('GuoLaiRen_PageBuilder::quick_build', '快速建站', 'mdi-rocket-launch', '快速建站向导', 'GuoLaiRen_PageBuilder::menu_page_management')]
class QuickBuild extends BaseController
{
    private QuickBuildAggregator $aggregator;

    public function __construct(QuickBuildAggregator $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    /**
     * 快速建站向导页面
     */
    #[Acl('GuoLaiRen_PageBuilder::quick_build_wizard', '新建站点向导', 'mdi-creation', '新建站点向导')]
    public function wizard(): string
    {
        $services = $this->aggregator->queryServices('all');
        $accounts = $this->aggregator->queryRegistrarAccounts(['status' => 'active']);

        $this->assign('title', __('快速建站'));
        $this->assign('services', $services);
        $this->assign('accounts', $accounts);

        return $this->fetch();
    }

    /**
     * 配置订单列表页面
     */
    #[Acl('GuoLaiRen_PageBuilder::quick_build_orders', '配置订单', 'mdi-clipboard-list', '查看配置订单')]
    public function orders(): string
    {
        $orders = $this->aggregator->queryProvisioningOrders();

        $this->assign('title', __('配置订单'));
        $this->assign('orders', $orders);

        return $this->fetch();
    }

    /**
     * AJAX: 检查域名可用性
     */
    public function postCheckDomain(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        $domainsRaw = trim($this->request->getPost('domains', '') ?? '');

        if ($accountId <= 0 || $domainsRaw === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('参数不完整')]);
        }

        $domains = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $domainsRaw)));

        try {
            $results = $this->aggregator->checkAvailability($accountId, $domains);
            return $this->fetchJson(['success' => true, 'data' => $results]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 购买域名
     */
    public function postPurchaseDomain(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        $domain = trim($this->request->getPost('domain', '') ?? '');
        $years = max(1, (int) $this->request->getPost('years', 1));
        $websiteId = (int) $this->request->getPost('website_id', 0);

        if ($accountId <= 0 || $domain === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('参数不完整')]);
        }

        try {
            $items = [['domain' => $domain, 'years' => $years, 'website_id' => $websiteId > 0 ? $websiteId : null]];
            $result = $this->aggregator->purchaseDomain($accountId, $items);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 启动一站式配置
     */
    public function postStartProvisioning(): string
    {
        $domain = trim($this->request->getPost('domain', '') ?? '');
        $registrarAccountId = (int) $this->request->getPost('registrar_account_id', 0);
        $cdnVendor = trim($this->request->getPost('cdn_vendor', 'cloudflare') ?? '');
        $cdnAccountId = (int) $this->request->getPost('cdn_account_id', 0);
        $websiteId = (int) $this->request->getPost('website_id', 0);
        $applySsl = (bool) $this->request->getPost('apply_ssl', 1);

        if ($domain === '' || $registrarAccountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('参数不完整')]);
        }

        try {
            $options = [
                'cdn_vendor' => $cdnVendor,
                'cdn_account_id' => $cdnAccountId > 0 ? $cdnAccountId : null,
                'website_id' => $websiteId > 0 ? $websiteId : null,
                'apply_ssl' => $applySsl,
            ];
            $result = $this->aggregator->startProvisioning($domain, $registrarAccountId, $options);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }
}
