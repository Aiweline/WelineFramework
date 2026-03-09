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
        
        // CDN 适配器和账户
        $cdnAdapters = $this->aggregator->queryCdnAdapters();
        $cdnAccounts = $this->aggregator->queryCdnAccounts(['status' => 'active']);

        $this->assign('title', __('快速建站'));
        $this->assign('services', $services);
        $this->assign('accounts', $accounts);
        $this->assign('cdnAdapters', $cdnAdapters);
        $this->assign('cdnAccounts', $cdnAccounts);

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
        $autoResolve = $this->request->getPost('auto_resolve', '0') === '1';
        $options = [
            'resolve_to_local' => (string) $this->request->getPost('resolve_to_local', $autoResolve ? 'yes' : 'no'),
            'subdomains' => $this->request->getPost('subdomains', '@,www'),
            'dns_choice' => (string) $this->request->getPost('dns_choice', 'follow_registrar'),
            'dns_nameservers' => (string) $this->request->getPost('dns_nameservers', ''),
            'cdn_choice' => (string) $this->request->getPost('cdn_choice', 'follow_registrar'),
            'start_lifecycle' => (string) $this->request->getPost('start_lifecycle', '1'),
        ];

        if ($accountId <= 0 || $domain === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('参数不完整')]);
        }

        try {
            $items = [['domain' => $domain, 'years' => $years, 'website_id' => $websiteId > 0 ? $websiteId : null]];
            $result = $this->aggregator->purchaseDomain($accountId, $items, $autoResolve, $options);
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
        // 是否使用已有域名（跳过购买步骤）
        $skipPurchase = !empty($this->request->getPost('skip_purchase', 0));
        $domainOwned = !empty($this->request->getPost('domain_owned', 0));

        if ($domain === '' || $registrarAccountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('参数不完整：域名=%{1}，账号ID=%{2}', [$domain, $registrarAccountId])]);
        }

        try {
            $options = [
                'cdn_vendor' => $cdnVendor,
                'cdn_account_id' => $cdnAccountId > 0 ? $cdnAccountId : null,
                'website_id' => $websiteId > 0 ? $websiteId : null,
                'apply_ssl' => $applySsl,
                'skip_purchase' => $skipPurchase || $domainOwned,
                'domain_owned' => $domainOwned,
            ];
            $result = $this->aggregator->startProvisioning($domain, $registrarAccountId, $options);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }
}
