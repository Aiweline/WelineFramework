<?php

declare(strict_types=1);

namespace Weline\Saas\Service;

use Weline\Cdn\Model\Account as CdnAccount;
use Weline\Cdn\Model\Domain as CdnDomain;
use Weline\Cdn\Service\AdapterResolver as CdnAdapterResolver;
use Weline\Cdn\Service\AccountManager as CdnAccountManager;
use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Saas\Model\ProvisioningOrder;
use Weline\Saas\Model\ProvisioningStep;
use Weline\Server\Service\SslCertificateService;
use Weline\Websites\Service\DomainPurchaseService;

/**
 * 一站式配置编排：购买域名 → 绑定 DNS → 绑定 CDN → 申请 SSL
 *
 * 默认 DNS/CDN 跟随购买域名的供应商；若选择不同 CDN 供应商则自动切换 DNS 到该供应商。
 */
class DomainProvisioningService
{
    private ProvisioningOrder $orderModel;
    private ProvisioningStep $stepModel;
    private DomainPurchaseService $domainPurchaseService;
    private CdnAdapterResolver $cdnAdapterResolver;
    private CdnAccountManager $cdnAccountManager;
    private SslCertificateService $sslCertificateService;
    private EventsManager $eventsManager;

    public function __construct(
        ProvisioningOrder $orderModel,
        ProvisioningStep $stepModel,
        DomainPurchaseService $domainPurchaseService,
        CdnAdapterResolver $cdnAdapterResolver,
        CdnAccountManager $cdnAccountManager,
        SslCertificateService $sslCertificateService,
        EventsManager $eventsManager
    ) {
        $this->orderModel = $orderModel;
        $this->stepModel = $stepModel;
        $this->domainPurchaseService = $domainPurchaseService;
        $this->cdnAdapterResolver = $cdnAdapterResolver;
        $this->cdnAccountManager = $cdnAccountManager;
        $this->sslCertificateService = $sslCertificateService;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 创建配置订单并执行第一步：购买域名
     *
     * @param string $domain 域名
     * @param int $registrarAccountId 域名商账号 ID
     * @param array $options years, website_id, auto_create_site, dns_vendor, dns_account_id, cdn_vendor, cdn_account_id, apply_ssl
     * @return array{success: bool, message: string, order_id?: int, results?: array}
     */
    public function startProvisioning(string $domain, int $registrarAccountId, array $options = []): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return ['success' => false, 'message' => __('域名不能为空')];
        }

        $existing = clone $this->orderModel;
        $existing->reset()->where(ProvisioningOrder::fields_DOMAIN, $domain)->find()->fetch();
        if ($existing->getOrderId() > 0 && $existing->getStatus() !== ProvisioningOrder::STATUS_FAILED) {
            return [
                'success' => false,
                'message' => __('该域名已有进行中的配置流程'),
                'order_id' => $existing->getOrderId(),
            ];
        }

        $years = (int) ($options['years'] ?? 1);
        $websiteId = (int) ($options['website_id'] ?? 0);
        $autoCreateSite = ($options['auto_create_site'] ?? 'no') === 'yes' ? 'yes' : 'no';
        $dnsVendor = trim((string) ($options['dns_vendor'] ?? ''));
        $dnsAccountId = (int) ($options['dns_account_id'] ?? 0);
        $cdnVendor = trim((string) ($options['cdn_vendor'] ?? ''));
        $cdnAccountId = (int) ($options['cdn_account_id'] ?? 0);
        $applySsl = !isset($options['apply_ssl']) || (bool) $options['apply_ssl'];

        $order = clone $this->orderModel;
        $order->clearData();
        $order->setData(ProvisioningOrder::fields_DOMAIN, $domain);
        $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_STEP_PURCHASE);
        $order->setData(ProvisioningOrder::fields_REGISTRAR_ACCOUNT_ID, $registrarAccountId);
        $order->setData(ProvisioningOrder::fields_DNS_VENDOR, $dnsVendor);
        $order->setData(ProvisioningOrder::fields_DNS_ACCOUNT_ID, $dnsAccountId);
        $order->setData(ProvisioningOrder::fields_CDN_VENDOR, $cdnVendor);
        $order->setData(ProvisioningOrder::fields_CDN_ACCOUNT_ID, $cdnAccountId);
        $order->setData(ProvisioningOrder::fields_WEBSITE_ID, $websiteId);
        $order->setData(ProvisioningOrder::fields_APPLY_SSL, $applySsl ? 1 : 0);
        $order->setData(ProvisioningOrder::fields_CURRENT_STEP, ProvisioningOrder::STEP_PURCHASE);
        $order->save();

        $orderId = $order->getOrderId();
        $this->recordStep($orderId, ProvisioningOrder::STEP_PURCHASE, 'running', '', 0, []);

        $items = [
            [
                'domain' => $domain,
                'years' => $years,
                'website_id' => $websiteId,
                'auto_create_site' => $autoCreateSite,
            ],
        ];
        $result = $this->domainPurchaseService->createAndProcessOrder($registrarAccountId, $items);

        if (!($result['success'] ?? false)) {
            $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, $result['message'] ?? __('购买失败'));
            $order->save();
            $this->recordStep($orderId, ProvisioningOrder::STEP_PURCHASE, 'failed', $result['message'] ?? '', 0, $result);
            return $result;
        }

        $first = $result['results'][0] ?? [];
        if (!($first['success'] ?? false)) {
            $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, $first['message'] ?? __('购买失败'));
            $order->save();
            $this->recordStep($orderId, ProvisioningOrder::STEP_PURCHASE, 'failed', $first['message'] ?? '', 0, $result);
            return [
                'success' => false,
                'message' => $first['message'] ?? __('购买失败'),
                'order_id' => $orderId,
                'results' => $result['results'] ?? [],
            ];
        }

        $order->setData(ProvisioningOrder::fields_CURRENT_STEP, ProvisioningOrder::STEP_PURCHASE);
        $order->save();
        $this->recordStep($orderId, ProvisioningOrder::STEP_PURCHASE, 'success', '', 0, $result);
        return [
            'success' => true,
            'message' => __('域名购买成功，请继续 DNS/CDN/证书 步骤'),
            'order_id' => $orderId,
            'results' => $result['results'] ?? [],
        ];
    }

    /**
     * 根据域名加载配置订单（用于事件观察者）
     */
    public function getOrderByDomain(string $domain): ?ProvisioningOrder
    {
        $domain = strtolower(trim($domain));
        $order = clone $this->orderModel;
        $order->reset()->where(ProvisioningOrder::fields_DOMAIN, $domain)->find()->fetch();
        return $order->getOrderId() > 0 ? $order : null;
    }

    /**
     * 执行 DNS 绑定步骤（默认通过事件交由 Terraform/CDN 等模块处理）
     */
    public function runStepDns(int $orderId): array
    {
        $order = clone $this->orderModel;
        $order->load($orderId);
        if (!$order->getOrderId()) {
            return ['success' => false, 'message' => __('配置订单不存在')];
        }

        $domain = $order->getDomain();
        $dnsVendor = (string) $order->getData(ProvisioningOrder::fields_DNS_VENDOR);
        $dnsAccountId = (int) $order->getData(ProvisioningOrder::fields_DNS_ACCOUNT_ID);

        if ($dnsVendor === '' || $dnsAccountId <= 0) {
            $cdnVendor = (string) $order->getData(ProvisioningOrder::fields_CDN_VENDOR);
            $cdnAccountId = (int) $order->getData(ProvisioningOrder::fields_CDN_ACCOUNT_ID);
            if ($cdnVendor !== '' && $cdnAccountId > 0) {
                $order->setData(ProvisioningOrder::fields_DNS_VENDOR, $cdnVendor);
                $order->setData(ProvisioningOrder::fields_DNS_ACCOUNT_ID, $cdnAccountId);
                $order->save();
                $dnsVendor = $cdnVendor;
                $dnsAccountId = $cdnAccountId;
            }
        }

        $this->recordStep($orderId, ProvisioningOrder::STEP_DNS, 'running', '', $dnsAccountId, []);
        $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_STEP_DNS);
        $order->setData(ProvisioningOrder::fields_CURRENT_STEP, ProvisioningOrder::STEP_DNS);
        $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, '');
        $order->save();

        $eventData = [
            'data' => [
                'provisioning_order_id' => $orderId,
                'domain' => $domain,
                'dns_vendor' => $dnsVendor,
                'dns_account_id' => $dnsAccountId,
                'website_id' => (int) $order->getData(ProvisioningOrder::fields_WEBSITE_ID),
            ],
        ];
        $this->eventsManager->dispatch('Weline_Saas::provisioning::bind_dns', $eventData);

        $handled = $eventData['data']['handled'] ?? false;
        if ($handled) {
            $this->recordStep($orderId, ProvisioningOrder::STEP_DNS, 'success', '', $dnsAccountId, $eventData['data']);
            return ['success' => true, 'message' => __('DNS 绑定已提交')];
        }

        $this->recordStep($orderId, ProvisioningOrder::STEP_DNS, 'success', '', $dnsAccountId, []);
        return ['success' => true, 'message' => __('DNS 步骤已记录，可由 Terraform 等模块执行')];
    }

    /**
     * 执行 CDN 绑定步骤；若 CDN 供应商与当前 DNS 不同则先切换 DNS 再绑定
     */
    public function runStepCdn(int $orderId, ?string $cdnVendor = null, ?int $cdnAccountId = null): array
    {
        $order = clone $this->orderModel;
        $order->load($orderId);
        if (!$order->getOrderId()) {
            return ['success' => false, 'message' => __('配置订单不存在')];
        }

        $domain = $order->getDomain();
        $currentDnsVendor = (string) $order->getData(ProvisioningOrder::fields_DNS_VENDOR);
        $currentCdnVendor = (string) $order->getData(ProvisioningOrder::fields_CDN_VENDOR);
        $currentCdnAccountId = (int) $order->getData(ProvisioningOrder::fields_CDN_ACCOUNT_ID);

        $vendor = $cdnVendor ?? $currentCdnVendor;
        $accountId = $cdnAccountId ?? $currentCdnAccountId;

        if ($vendor !== '' && $vendor !== $currentDnsVendor) {
            $order->setData(ProvisioningOrder::fields_DNS_VENDOR, $vendor);
            $order->setData(ProvisioningOrder::fields_DNS_ACCOUNT_ID, $accountId);
            $order->save();
        }
        if ($vendor !== '') {
            $order->setData(ProvisioningOrder::fields_CDN_VENDOR, $vendor);
            $order->setData(ProvisioningOrder::fields_CDN_ACCOUNT_ID, $accountId);
            $order->save();
        }

        if ($vendor === '' || $accountId <= 0) {
            return [
                'success' => false,
                'message' => __('请指定 CDN 供应商与账户，或先在订单中配置 cdn_vendor/cdn_account_id'),
            ];
        }

        $adapter = $this->cdnAdapterResolver->getAdapter($vendor);
        if (!$adapter) {
            $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, __('CDN 适配器不存在：%{1}', [$vendor]));
            $order->save();
            return ['success' => false, 'message' => __('CDN 适配器不存在：%{1}', [$vendor])];
        }

        $account = $this->cdnAccountManager->getDefaultAccount($vendor);
        if (!$account || (int) $account->getData(CdnAccount::fields_ACCOUNT_ID) !== $accountId) {
            $account = ObjectManager::getInstance(CdnAccount::class)->reset()->load($accountId);
        }
        if (!$account || !$account->getId()) {
            $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, __('CDN 账户不存在'));
            $order->save();
            return ['success' => false, 'message' => __('CDN 账户不存在')];
        }

        $this->recordStep($orderId, ProvisioningOrder::STEP_CDN, 'running', $vendor, $accountId, []);
        $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_STEP_CDN);
        $order->setData(ProvisioningOrder::fields_CURRENT_STEP, ProvisioningOrder::STEP_CDN);
        $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, '');
        $order->save();

        try {
            $credentials = $account->getCredentialsArray();
            $zoneInfo = $adapter->ensureZone($domain, $credentials);
            $zoneId = $zoneInfo['zone_id'] ?? '';
            if ($zoneId === '') {
                throw new \RuntimeException(__('ensureZone 未返回 zone_id'));
            }

            $siteId = (int) $order->getData(ProvisioningOrder::fields_WEBSITE_ID);
            if ($siteId <= 0) {
                $siteId = 1;
            }

            $cdnDomainModel = ObjectManager::getInstance(CdnDomain::class);
            $existing = $cdnDomainModel->reset()->where(CdnDomain::fields_DOMAIN_NAME, $domain)->find()->fetch();
            if ($existing->getData(CdnDomain::fields_DOMAIN_ID)) {
                $existing->setData(CdnDomain::fields_ZONE_ID, $zoneId);
                $existing->setData(CdnDomain::fields_ACCOUNT_ID, $accountId);
                $existing->setData(CdnDomain::fields_ADAPTER, $vendor);
                $existing->save();
            } else {
                $newDomain = ObjectManager::getInstance(CdnDomain::class);
                $newDomain->clearData();
                $newDomain->setData(CdnDomain::fields_SITE_ID, $siteId);
                $newDomain->setData(CdnDomain::fields_ADAPTER, $vendor);
                $newDomain->setData(CdnDomain::fields_ZONE_ID, $zoneId);
                $newDomain->setData(CdnDomain::fields_DOMAIN_NAME, $domain);
                $newDomain->setData(CdnDomain::fields_ACCOUNT_ID, $accountId);
                $newDomain->setData(CdnDomain::fields_INHERIT_DEFAULT, 0);
                $newDomain->setData(CdnDomain::fields_ENABLED, 1);
                $newDomain->save();
            }

            $this->recordStep($orderId, ProvisioningOrder::STEP_CDN, 'success', $vendor, $accountId, ['zone_id' => $zoneId]);
            $order->setData(ProvisioningOrder::fields_CURRENT_STEP, ProvisioningOrder::STEP_CDN);
            $order->save();
            return ['success' => true, 'message' => __('CDN 绑定成功'), 'zone_id' => $zoneId];
        } catch (\Throwable $e) {
            $this->recordStep($orderId, ProvisioningOrder::STEP_CDN, 'failed', $vendor, $accountId, [], $e->getMessage());
            $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, $e->getMessage());
            $order->save();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 执行 SSL 证书申请步骤
     */
    public function runStepSsl(int $orderId): array
    {
        $order = clone $this->orderModel;
        $order->load($orderId);
        if (!$order->getOrderId()) {
            return ['success' => false, 'message' => __('配置订单不存在')];
        }

        if (!$order->getApplySsl()) {
            $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_COMPLETED);
            $order->setData(ProvisioningOrder::fields_CURRENT_STEP, '');
            $order->save();
            return ['success' => true, 'message' => __('已跳过 SSL，流程完成')];
        }

        $domain = $order->getDomain();
        $websiteId = (int) $order->getData(ProvisioningOrder::fields_WEBSITE_ID);
        $webroot = \defined('PUB') ? PUB : ((\defined('BP') ? BP : '') . 'pub');
        $email = Env::get('admin_email', 'admin@' . $domain);

        $this->recordStep($orderId, ProvisioningOrder::STEP_SSL, 'running', '', 0, []);
        $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_STEP_SSL);
        $order->setData(ProvisioningOrder::fields_CURRENT_STEP, ProvisioningOrder::STEP_SSL);
        $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, '');
        $order->save();

        $result = $this->sslCertificateService->requestCertificate($domain, $webroot, $email, $websiteId);

        if ($result['success'] ?? false) {
            $this->recordStep($orderId, ProvisioningOrder::STEP_SSL, 'success', '', 0, $result);
            $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_COMPLETED);
            $order->setData(ProvisioningOrder::fields_CURRENT_STEP, '');
            $order->save();
            return ['success' => true, 'message' => __('证书申请成功'), 'cert' => $result['cert'] ?? null];
        }

        $msg = $result['message'] ?? __('证书申请失败');
        $this->recordStep($orderId, ProvisioningOrder::STEP_SSL, 'failed', '', 0, $result, $msg);
        $order->setData(ProvisioningOrder::fields_STATUS, ProvisioningOrder::STATUS_FAILED);
        $order->setData(ProvisioningOrder::fields_ERROR_MESSAGE, $msg);
        $order->save();
        return ['success' => false, 'message' => $msg];
    }

    /**
     * 记录步骤状态
     */
    private function recordStep(
        int $orderId,
        string $stepName,
        string $status,
        string $vendor,
        int $accountId,
        array $result = [],
        string $errorMessage = ''
    ): void {
        $step = clone $this->stepModel;
        $step->clearData();
        $step->setData(ProvisioningStep::fields_PROVISIONING_ORDER_ID, $orderId);
        $step->setData(ProvisioningStep::fields_STEP_NAME, $stepName);
        $step->setData(ProvisioningStep::fields_STATUS, $status);
        $step->setData(ProvisioningStep::fields_VENDOR, $vendor);
        $step->setData(ProvisioningStep::fields_ACCOUNT_ID, $accountId);
        $step->setData(ProvisioningStep::fields_RESULT_JSON, $result === [] ? '' : json_encode($result, JSON_UNESCAPED_UNICODE));
        $step->setData(ProvisioningStep::fields_ERROR_MESSAGE, $errorMessage);
        $step->save();
    }
}
