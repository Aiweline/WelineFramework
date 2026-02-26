<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Service\QuickBuildAggregator;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

/**
 * @DESC | PageBuilder 域名管理控制器 - 一站式域名管理（账户管理 + 域名列表 + 批量购买）
 */
#[Acl('GuoLaiRen_PageBuilder::domain_management', '域名管理', 'mdi-dns', '域名管理', 'GuoLaiRen_PageBuilder::website_management')]
class DomainManagement extends BaseController
{
    private QuickBuildAggregator $aggregator;

    public function __construct(QuickBuildAggregator $aggregator)
    {
        $this->aggregator = $aggregator;
    }

    #[Acl('GuoLaiRen_PageBuilder::domain_management_index', '域名管理首页', 'mdi-dns', '查看域名管理')]
    public function index(): string
    {
        $accounts = $this->aggregator->queryRegistrarAccounts([]);
        $registrars = $this->aggregator->queryRegistrars();

        // DEBUG: 检查数据
        \Weline\Framework\App\Env::log_warning('domain_debug', 'accounts count: ' . count($accounts) . ', registrars count: ' . count($registrars));

        $this->assign('title', __('域名管理'));
        $this->assign('accounts', $accounts);
        $this->assign('registrars', $registrars);

        return $this->fetch();
    }

    /**
     * AJAX: 获取可用域名商类型列表
     */
    public function postGetRegistrars(): string
    {
        try {
            $registrars = $this->aggregator->queryRegistrars();
            return $this->fetchJson(['success' => true, 'data' => $registrars]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 获取域名商适配器的配置字段
     */
    public function postGetConfigFields(): string
    {
        $registrarCode = trim($this->request->getPost('registrar_code', '') ?? '');
        if ($registrarCode === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('缺少域名商代码')]);
        }

        try {
            $fields = $this->aggregator->queryRegistrarConfigFields($registrarCode);
            return $this->fetchJson(['success' => true, 'data' => $fields]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 获取域名商完整信息（配置字段 + 帮助说明 + 默认值）
     */
    public function postGetRegistrarInfo(): string
    {
        $registrarCode = trim($this->request->getPost('registrar_code', '') ?? '');
        if ($registrarCode === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('缺少域名商代码')]);
        }

        try {
            $info = $this->aggregator->queryRegistrarInfo($registrarCode);
            return $this->fetchJson(['success' => true, 'data' => $info]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 保存域名商账号
     */
    public function postSaveAccount(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        $registrarCode = trim($this->request->getPost('registrar_code', '') ?? '');
        $accountName = trim($this->request->getPost('account_name', '') ?? '');
        $apiKey = trim($this->request->getPost('api_key', '') ?? '');
        $apiSecret = trim($this->request->getPost('api_secret', '') ?? '');
        $region = trim($this->request->getPost('region', '') ?? '');
        $status = trim($this->request->getPost('status', 'active') ?? '');

        $extraFields = $this->request->getPost('extra_config', []);
        if (\is_string($extraFields)) {
            $extraFields = json_decode($extraFields, true) ?: [];
        }

        if ($registrarCode === '' || $accountName === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('域名商类型和账号名称不能为空')]);
        }

        if ($accountId <= 0 && ($apiKey === '' || $apiSecret === '')) {
            return $this->fetchJson(['success' => false, 'msg' => __('API Key 和 API Secret 不能为空')]);
        }

        $data = [
            'account_id' => $accountId,
            'registrar_code' => $registrarCode,
            'account_name' => $accountName,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'region' => $region,
            'extra_config' => $extraFields,
            'status' => $status,
        ];

        try {
            $result = $this->aggregator->saveRegistrarAccount($data);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('保存失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 删除域名商账号
     */
    public function postDeleteAccount(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        if ($accountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('账号 ID 无效')]);
        }

        try {
            $result = $this->aggregator->deleteRegistrarAccount($accountId);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('删除失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 测试域名商连接
     */
    public function postTestConnection(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        if ($accountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('账号 ID 无效')]);
        }

        try {
            $result = $this->aggregator->testRegistrarConnection($accountId);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: 查询域名列表
     */
    public function postGetDomains(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        if ($accountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择域名商账号')]);
        }

        try {
            $domains = $this->aggregator->queryDomainList($accountId);
            return $this->fetchJson(['success' => true, 'data' => $domains]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('查询失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 检查域名可用性
     */
    public function postCheckAvailability(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        $domainsRaw = trim($this->request->getPost('domains', '') ?? '');

        if ($accountId <= 0 || $domainsRaw === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择域名商账号并输入域名')]);
        }

        $domains = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $domainsRaw))));
        if ($domains === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('域名列表为空')]);
        }

        try {
            $results = $this->aggregator->checkAvailability($accountId, $domains);
            return $this->fetchJson(['success' => true, 'data' => $results]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('检查失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 批量购买域名
     */
    public function postBatchPurchase(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        $domainsRaw = $this->request->getPost('domains', '');

        if ($accountId <= 0) {
            return $this->fetchJson(['success' => false, 'msg' => __('请选择域名商账号')]);
        }

        $domainItems = \is_string($domainsRaw) ? json_decode($domainsRaw, true) : $domainsRaw;
        if (!\is_array($domainItems) || $domainItems === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('购买域名列表为空')]);
        }

        $items = [];
        foreach ($domainItems as $item) {
            $domain = trim((string) ($item['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $items[] = [
                'domain' => $domain,
                'years' => max(1, (int) ($item['years'] ?? 1)),
                'website_id' => (int) ($item['website_id'] ?? 0) ?: null,
            ];
        }

        if ($items === []) {
            return $this->fetchJson(['success' => false, 'msg' => __('无有效域名')]);
        }

        try {
            $result = $this->aggregator->purchaseDomain($accountId, $items);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('购买失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * AJAX: 单域名购买
     */
    public function postPurchase(): string
    {
        $accountId = (int) $this->request->getPost('account_id', 0);
        $domain = trim($this->request->getPost('domain', '') ?? '');
        $years = (int) $this->request->getPost('years', 1);

        if ($accountId <= 0 || $domain === '') {
            return $this->fetchJson(['success' => false, 'msg' => __('参数不完整')]);
        }

        try {
            $items = [['domain' => $domain, 'years' => max(1, $years)]];
            $result = $this->aggregator->purchaseDomain($accountId, $items);
            return $this->fetchJson($result);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('购买失败：%{1}', [$e->getMessage()])]);
        }
    }
}
