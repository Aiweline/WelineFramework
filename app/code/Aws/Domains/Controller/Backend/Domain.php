<?php

declare(strict_types=1);

/*
 * AWS Domains 管理模块
 * 域名管理控制器
 */

namespace Aws\Domains\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Aws\Domains\Model\AwsConfig;
use Aws\Domains\Service\Route53DomainsService;

/**
 * 域名管理后台控制器
 */
#[AclAttribute('Aws_Domains::domain', '域名管理', 'mdi-dns-outline', '域名管理', '')]
class Domain extends BackendController
{
    private function getDomainsService(): Route53DomainsService
    {
        return ObjectManager::getInstance(Route53DomainsService::class);
    }

    private function getConfigModel(): AwsConfig
    {
        return ObjectManager::getInstance(AwsConfig::class);
    }

    /**
     * 域名列表
     */
    #[AclAttribute('Aws_Domains::domain_index', '查看域名列表', 'mdi-view-list', '查看域名列表')]
    public function index(): string
    {
        $configId = (int)$this->request->getGet('config_id');
        $marker = $this->request->getGet('marker');

        $configs = AwsConfig::getActiveConfigs();
        $domains = [];
        $nextMarker = null;
        $error = null;

        if (!empty($configs)) {
            $config = null;
            if ($configId) {
                foreach ($configs as $c) {
                    if ((int)$c['config_id'] === $configId) {
                        $configModel = $this->getConfigModel()->reset();
                        $configModel->setData($c);
                        $config = $configModel;
                        break;
                    }
                }
            }

            if ($config === null) {
                $config = AwsConfig::getDefaultConfig();
            }

            if ($config !== null) {
                $service = $this->getDomainsService();
                $service->setConfig($config);

                $result = $service->listDomains($marker, 50);

                if ($result['success']) {
                    $domains = $result['domains'];
                    $nextMarker = $result['next_marker'];
                } else {
                    $error = $result['error'] ?? '获取域名列表失败';
                }

                $configId = (int)$config->getId();
            } else {
                $error = '请先配置 AWS 凭证';
            }
        } else {
            $error = '请先配置 AWS 凭证';
        }

        $this->assign('configs', $configs);
        $this->assign('config_id', $configId);
        $this->assign('domains', $domains);
        $this->assign('next_marker', $nextMarker);
        $this->assign('marker', $marker);
        $this->assign('error', $error);

        return $this->fetch();
    }

    /**
     * 域名详情
     */
    #[AclAttribute('Aws_Domains::domain_detail', '查看域名详情', 'mdi-information-outline', '查看域名详情')]
    public function detail(): string
    {
        $domainName = trim((string)$this->request->getGet('domain'));
        $configId = (int)$this->request->getGet('config_id');

        if ($domainName === '') {
            Message::error(__('域名不能为空'));
            return $this->redirect('aws/backend/domain/index');
        }

        $config = null;
        if ($configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
            if (!$config->getId()) {
                $config = null;
            }
        }

        if ($config === null) {
            $config = AwsConfig::getDefaultConfig();
        }

        if ($config === null) {
            Message::error(__('请先配置 AWS 凭证'));
            return $this->redirect('aws/backend/config/index');
        }

        $service = $this->getDomainsService();
        $service->setConfig($config);

        $result = $service->getDomainDetail($domainName);

        if (!$result['success']) {
            Message::error(__('获取域名详情失败：%1', $result['error'] ?? '未知错误'));
        }

        $this->assign('domain', $result['domain'] ?? []);
        $this->assign('domain_name', $domainName);
        $this->assign('config_id', (int)$config->getId());
        $this->assign('success', $result['success']);
        $this->assign('error', $result['error'] ?? null);

        return $this->fetch();
    }

    /**
     * 域名可用性检查页面
     */
    #[AclAttribute('Aws_Domains::domain_check', '域名可用性检查', 'mdi-magnify', '域名可用性检查')]
    public function check(): string
    {
        $configs = AwsConfig::getActiveConfigs();
        $this->assign('configs', $configs);

        return $this->fetch();
    }

    /**
     * 执行域名可用性检查 (AJAX)
     */
    #[AclAttribute('Aws_Domains::domain_check_api', '域名可用性检查API', 'mdi-magnify', '域名可用性检查API')]
    public function checkAvailability(): string
    {
        $domainName = trim((string)$this->request->getPost('domain'));
        $configId = (int)$this->request->getPost('config_id');

        if ($domainName === '') {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名不能为空'),
            ]);
        }

        $config = null;
        if ($configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
            if (!$config->getId()) {
                $config = null;
            }
        }

        if ($config === null) {
            $config = AwsConfig::getDefaultConfig();
        }

        if ($config === null) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先配置 AWS 凭证'),
            ]);
        }

        try {
            $service = $this->getDomainsService();
            $service->setConfig($config);

            $result = $service->checkDomainAvailability($domainName);

            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'domain' => $domainName,
                    'available' => $result['available'],
                    'availability' => $result['availability'],
                    'message' => $result['available']
                        ? __('域名 %1 可以注册', $domainName)
                        : __('域名 %1 不可用（%2）', $domainName, $result['availability']),
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['error'] ?? __('检查失败'),
                ]);
            }
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取域名建议 (AJAX)
     */
    #[AclAttribute('Aws_Domains::domain_suggestions', '获取域名建议', 'mdi-lightbulb-outline', '获取域名建议')]
    public function suggestions(): string
    {
        $domainName = trim((string)$this->request->getPost('domain'));
        $configId = (int)$this->request->getPost('config_id');

        if ($domainName === '') {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名不能为空'),
            ]);
        }

        $config = null;
        if ($configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
        }

        if ($config === null || !$config->getId()) {
            $config = AwsConfig::getDefaultConfig();
        }

        if ($config === null) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先配置 AWS 凭证'),
            ]);
        }

        try {
            $service = $this->getDomainsService();
            $service->setConfig($config);

            $result = $service->getDomainSuggestions($domainName, 20, true);

            return $this->jsonResponse([
                'success' => $result['success'],
                'suggestions' => $result['suggestions'] ?? [],
                'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 购买域名页面
     */
    #[AclAttribute('Aws_Domains::domain_register', '购买域名', 'mdi-cart-plus', '购买域名')]
    public function register(): string
    {
        $domainName = trim((string)$this->request->getGet('domain', ''));
        $configId = (int)$this->request->getGet('config_id');

        $configs = AwsConfig::getActiveConfigs();

        $this->assign('configs', $configs);
        $this->assign('domain_name', $domainName);
        $this->assign('config_id', $configId);

        return $this->fetch();
    }

    /**
     * 执行域名注册 (AJAX)
     */
    #[AclAttribute('Aws_Domains::domain_register_api', '执行域名注册', 'mdi-cart-plus', '执行域名注册')]
    public function doRegister(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $data = $this->request->getPost();

        $domainName = trim((string)($data['domain_name'] ?? ''));
        $durationYears = (int)($data['duration_years'] ?? 1);
        $configId = (int)($data['config_id'] ?? 0);
        $autoRenew = !empty($data['auto_renew']);
        $privacyProtect = !empty($data['privacy_protect']);

        if ($domainName === '') {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名不能为空'),
            ]);
        }

        if ($durationYears < 1 || $durationYears > 10) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('注册年限必须在 1-10 年之间'),
            ]);
        }

        $config = null;
        if ($configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
        }

        if ($config === null || !$config->getId()) {
            $config = AwsConfig::getDefaultConfig();
        }

        if ($config === null) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先配置 AWS 凭证'),
            ]);
        }

        // 联系人信息
        $contact = [
            'first_name' => trim((string)($data['first_name'] ?? '')),
            'last_name' => trim((string)($data['last_name'] ?? '')),
            'contact_type' => $data['contact_type'] ?? 'PERSON',
            'organization' => trim((string)($data['organization'] ?? '')),
            'address1' => trim((string)($data['address1'] ?? '')),
            'address2' => trim((string)($data['address2'] ?? '')),
            'city' => trim((string)($data['city'] ?? '')),
            'state' => trim((string)($data['state'] ?? '')),
            'country_code' => trim((string)($data['country_code'] ?? '')),
            'zip_code' => trim((string)($data['zip_code'] ?? '')),
            'phone' => trim((string)($data['phone'] ?? '')),
            'email' => trim((string)($data['email'] ?? '')),
        ];

        // 验证必填字段
        $requiredFields = ['first_name', 'last_name', 'address1', 'city', 'country_code', 'zip_code', 'phone', 'email'];
        foreach ($requiredFields as $field) {
            if (empty($contact[$field])) {
                $fieldNames = [
                    'first_name' => '名',
                    'last_name' => '姓',
                    'address1' => '地址',
                    'city' => '城市',
                    'country_code' => '国家代码',
                    'zip_code' => '邮编',
                    'phone' => '电话',
                    'email' => '邮箱',
                ];
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('%1 不能为空', $fieldNames[$field] ?? $field),
                ]);
            }
        }

        try {
            $service = $this->getDomainsService();
            $service->setConfig($config);

            // 先检查域名是否可用
            $checkResult = $service->checkDomainAvailability($domainName);
            if (!$checkResult['available']) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('域名 %1 不可用（%2）', $domainName, $checkResult['availability'] ?? 'UNAVAILABLE'),
                ]);
            }

            // 注册域名
            $result = $service->registerDomain(
                $domainName,
                $durationYears,
                $contact,
                $contact,
                $contact,
                $autoRenew,
                $privacyProtect
            );

            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => __('域名注册请求已提交，操作ID：%1', $result['operation_id'] ?? ''),
                    'operation_id' => $result['operation_id'] ?? '',
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['error'] ?? __('注册失败'),
                ]);
            }
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 续费域名页面
     */
    #[AclAttribute('Aws_Domains::domain_renew', '续费域名', 'mdi-refresh', '续费域名')]
    public function renew(): string
    {
        $domainName = trim((string)$this->request->getGet('domain', ''));
        $configId = (int)$this->request->getGet('config_id');

        $configs = AwsConfig::getActiveConfigs();
        $domainDetail = null;

        if ($domainName !== '' && $configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
            if ($config->getId()) {
                $service = $this->getDomainsService();
                $service->setConfig($config);
                $result = $service->getDomainDetail($domainName);
                if ($result['success']) {
                    $domainDetail = $result['domain'];
                }
            }
        }

        $this->assign('configs', $configs);
        $this->assign('domain_name', $domainName);
        $this->assign('config_id', $configId);
        $this->assign('domain_detail', $domainDetail);

        return $this->fetch();
    }

    /**
     * 执行域名续费 (AJAX)
     */
    #[AclAttribute('Aws_Domains::domain_renew_api', '执行域名续费', 'mdi-refresh', '执行域名续费')]
    public function doRenew(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $domainName = trim((string)$this->request->getPost('domain_name', ''));
        $durationYears = (int)$this->request->getPost('duration_years', 1);
        $currentExpiryYear = (int)$this->request->getPost('current_expiry_year', 0);
        $configId = (int)$this->request->getPost('config_id', 0);

        if ($domainName === '') {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名不能为空'),
            ]);
        }

        if ($currentExpiryYear < 2020) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请提供正确的当前到期年份'),
            ]);
        }

        $config = null;
        if ($configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
        }

        if ($config === null || !$config->getId()) {
            $config = AwsConfig::getDefaultConfig();
        }

        if ($config === null) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先配置 AWS 凭证'),
            ]);
        }

        try {
            $service = $this->getDomainsService();
            $service->setConfig($config);

            $result = $service->renewDomain($domainName, $durationYears, $currentExpiryYear);

            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => __('域名续费请求已提交，操作ID：%1', $result['operation_id'] ?? ''),
                    'operation_id' => $result['operation_id'] ?? '',
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['error'] ?? __('续费失败'),
                ]);
            }
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 更新域名服务器页面
     */
    #[AclAttribute('Aws_Domains::domain_nameservers', '更新域名服务器', 'mdi-server-network', '更新域名服务器')]
    public function nameservers(): string
    {
        $domainName = trim((string)$this->request->getGet('domain', ''));
        $configId = (int)$this->request->getGet('config_id');

        if ($domainName === '') {
            Message::error(__('域名不能为空'));
            return $this->redirect('aws/backend/domain/index');
        }

        $config = null;
        if ($configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
        }

        if ($config === null || !$config->getId()) {
            $config = AwsConfig::getDefaultConfig();
        }

        $domainDetail = null;
        if ($config !== null) {
            $service = $this->getDomainsService();
            $service->setConfig($config);
            $result = $service->getDomainDetail($domainName);
            if ($result['success']) {
                $domainDetail = $result['domain'];
            }
        }

        $configs = AwsConfig::getActiveConfigs();

        $this->assign('configs', $configs);
        $this->assign('domain_name', $domainName);
        $this->assign('config_id', $configId ?: ($config ? $config->getId() : 0));
        $this->assign('domain_detail', $domainDetail);

        return $this->fetch();
    }

    /**
     * 更新域名服务器 (AJAX)
     */
    #[AclAttribute('Aws_Domains::domain_update_nameservers', '更新域名服务器', 'mdi-server-network', '更新域名服务器')]
    public function updateNameservers(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法'),
            ]);
        }

        $domainName = trim((string)$this->request->getPost('domain_name', ''));
        $configId = (int)$this->request->getPost('config_id', 0);
        $nameservers = $this->request->getPost('nameservers', []);

        if ($domainName === '') {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名不能为空'),
            ]);
        }

        if (empty($nameservers) || !is_array($nameservers)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请提供至少一个域名服务器'),
            ]);
        }

        $config = null;
        if ($configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
        }

        if ($config === null || !$config->getId()) {
            $config = AwsConfig::getDefaultConfig();
        }

        if ($config === null) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先配置 AWS 凭证'),
            ]);
        }

        try {
            $service = $this->getDomainsService();
            $service->setConfig($config);

            $formattedNameservers = [];
            foreach ($nameservers as $ns) {
                $name = trim((string)($ns['name'] ?? $ns ?? ''));
                if ($name !== '') {
                    $formattedNameservers[] = [
                        'Name' => $name,
                        'GlueIps' => [],
                    ];
                }
            }

            if (empty($formattedNameservers)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('请提供有效的域名服务器'),
                ]);
            }

            $result = $service->updateDomainNameservers($domainName, $formattedNameservers);

            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => __('域名服务器更新成功'),
                    'operation_id' => $result['operation_id'] ?? '',
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['error'] ?? __('更新失败'),
                ]);
            }
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 切换自动续费状态 (AJAX)
     */
    #[AclAttribute('Aws_Domains::domain_toggle_autorenew', '切换自动续费', 'mdi-autorenew', '切换自动续费')]
    public function toggleAutoRenew(): string
    {
        $domainName = trim((string)$this->request->getPost('domain_name', ''));
        $configId = (int)$this->request->getPost('config_id', 0);
        $enable = !empty($this->request->getPost('enable'));

        if ($domainName === '') {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('域名不能为空'),
            ]);
        }

        $config = null;
        if ($configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
        }

        if ($config === null || !$config->getId()) {
            $config = AwsConfig::getDefaultConfig();
        }

        if ($config === null) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先配置 AWS 凭证'),
            ]);
        }

        try {
            $service = $this->getDomainsService();
            $service->setConfig($config);

            $result = $service->updateAutoRenew($domainName, $enable);

            if ($result['success']) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => $enable ? __('自动续费已开启') : __('自动续费已关闭'),
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => $result['error'] ?? __('操作失败'),
                ]);
            }
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取价格列表 (AJAX)
     */
    #[AclAttribute('Aws_Domains::domain_prices', '获取价格列表', 'mdi-currency-usd', '获取价格列表')]
    public function prices(): string
    {
        $configId = (int)$this->request->getPost('config_id', 0);
        $tld = trim((string)$this->request->getPost('tld', ''));

        $config = null;
        if ($configId) {
            $config = $this->getConfigModel()->reset()->load($configId);
        }

        if ($config === null || !$config->getId()) {
            $config = AwsConfig::getDefaultConfig();
        }

        if ($config === null) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先配置 AWS 凭证'),
            ]);
        }

        try {
            $service = $this->getDomainsService();
            $service->setConfig($config);

            if ($tld !== '') {
                $result = $service->getDomainPrices($tld);
            } else {
                $result = $service->listAllPrices(null, 100);
            }

            return $this->jsonResponse([
                'success' => $result['success'],
                'prices' => $result['prices'] ?? [],
                'error' => $result['error'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * JSON 响应工具
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
