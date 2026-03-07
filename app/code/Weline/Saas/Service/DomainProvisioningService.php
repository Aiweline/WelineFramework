<?php

declare(strict_types=1);

namespace Weline\Saas\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Saas\Model\ProvisioningOrder;
use Weline\Saas\Model\ProvisioningStep;

/**
 * 一站式配置编排：购买域名 → 绑定 DNS → 绑定 CDN → 申请 SSL
 *
 * 默认 DNS/CDN 跟随购买域名的供应商；若选择不同 CDN 供应商则自动切换 DNS 到该供应商。
 *
 * 模块间通信使用 w_query() 统一查询器，不直接依赖 Cdn/Websites 模块的服务类。
 */
class DomainProvisioningService
{
    private ProvisioningOrder $orderModel;
    private ProvisioningStep $stepModel;
    private EventsManager $eventsManager;

    public function __construct(
        ProvisioningOrder $orderModel,
        ProvisioningStep $stepModel,
        EventsManager $eventsManager
    ) {
        $this->orderModel = $orderModel;
        $this->stepModel = $stepModel;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 创建配置订单并执行第一步：购买域名（或跳过购买使用已有域名）
     *
     * @param string $domain 域名
     * @param int $registrarAccountId 域名商账号 ID
     * @param array $options years, website_id, auto_create_site, dns_vendor, dns_account_id, cdn_vendor, cdn_account_id, apply_ssl, skip_purchase
     * @return array{success: bool, message: string, order_id?: int, results?: array}
     */
    public function startProvisioning(string $domain, int $registrarAccountId, array $options = []): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return ['success' => false, 'message' => __('域名不能为空')];
        }

        $existing = clone $this->orderModel;
        $existing->reset()->where(ProvisioningOrder::schema_fields_DOMAIN, $domain)->find()->fetch();
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
        // 是否跳过购买步骤（使用已有域名）
        $skipPurchase = !empty($options['skip_purchase']) || !empty($options['domain_owned']);

        // 确定初始步骤
        $initialStep = $skipPurchase ? ProvisioningOrder::STEP_DNS : ProvisioningOrder::STEP_PURCHASE;
        $initialStatus = $skipPurchase ? ProvisioningOrder::STATUS_STEP_DNS : ProvisioningOrder::STATUS_STEP_PURCHASE;

        $order = clone $this->orderModel;
        $order->clearData();
        $order->setData(ProvisioningOrder::schema_fields_DOMAIN, $domain);
        $order->setData(ProvisioningOrder::schema_fields_STATUS, $initialStatus);
        $order->setData(ProvisioningOrder::schema_fields_REGISTRAR_ACCOUNT_ID, $registrarAccountId);
        $order->setData(ProvisioningOrder::schema_fields_DNS_VENDOR, $dnsVendor);
        $order->setData(ProvisioningOrder::schema_fields_DNS_ACCOUNT_ID, $dnsAccountId);
        $order->setData(ProvisioningOrder::schema_fields_CDN_VENDOR, $cdnVendor);
        $order->setData(ProvisioningOrder::schema_fields_CDN_ACCOUNT_ID, $cdnAccountId);
        $order->setData(ProvisioningOrder::schema_fields_WEBSITE_ID, $websiteId);
        $order->setData(ProvisioningOrder::schema_fields_APPLY_SSL, $applySsl ? 1 : 0);
        $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, $initialStep);
        $order->save();

        $orderId = $order->getOrderId();

        // 如果跳过购买（使用已有域名），直接返回成功并记录
        if ($skipPurchase) {
            $this->recordStep($orderId, ProvisioningOrder::STEP_PURCHASE, 'skipped', '', 0, ['reason' => 'domain_owned']);
            return [
                'success' => true,
                'message' => __('配置订单已创建（使用已有域名），请继续 DNS/CDN/证书 步骤'),
                'order_id' => $orderId,
                'skipped_purchase' => true,
            ];
        }

        // 执行购买流程
        $this->recordStep($orderId, ProvisioningOrder::STEP_PURCHASE, 'running', '', 0, []);

        $items = [
            [
                'domain' => $domain,
                'years' => $years,
                'website_id' => $websiteId,
                'auto_create_site' => $autoCreateSite,
            ],
        ];
        $result = w_query('websites', 'purchaseDomain', [
            'account_id' => $registrarAccountId,
            'items'      => $items,
        ]);

        if (!($result['success'] ?? false)) {
            $errorMsg = $result['message'] ?? __('购买失败');
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, $errorMsg);
            $order->save();
            $this->recordStep($orderId, ProvisioningOrder::STEP_PURCHASE, 'failed', $errorMsg, 0, $result);
            return [
                'success' => false,
                'message' => $errorMsg,
                'order_id' => $orderId,
            ];
        }

        $first = $result['results'][0] ?? [];
        if (!($first['success'] ?? false)) {
            $errorMsg = $first['message'] ?? __('购买失败');
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, $errorMsg);
            $order->save();
            $this->recordStep($orderId, ProvisioningOrder::STEP_PURCHASE, 'failed', $errorMsg, 0, $result);
            return [
                'success' => false,
                'message' => $errorMsg,
                'order_id' => $orderId,
                'results' => $result['results'] ?? [],
            ];
        }

        $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_PURCHASE);
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
        $order->reset()->where(ProvisioningOrder::schema_fields_DOMAIN, $domain)->find()->fetch();
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
        $dnsVendor = (string) $order->getData(ProvisioningOrder::schema_fields_DNS_VENDOR);
        $dnsAccountId = (int) $order->getData(ProvisioningOrder::schema_fields_DNS_ACCOUNT_ID);

        if ($dnsVendor === '' || $dnsAccountId <= 0) {
            $cdnVendor = (string) $order->getData(ProvisioningOrder::schema_fields_CDN_VENDOR);
            $cdnAccountId = (int) $order->getData(ProvisioningOrder::schema_fields_CDN_ACCOUNT_ID);
            if ($cdnVendor !== '' && $cdnAccountId > 0) {
                $order->setData(ProvisioningOrder::schema_fields_DNS_VENDOR, $cdnVendor);
                $order->setData(ProvisioningOrder::schema_fields_DNS_ACCOUNT_ID, $cdnAccountId);
                $order->save();
                $dnsVendor = $cdnVendor;
                $dnsAccountId = $cdnAccountId;
            }
        }

        $this->recordStep($orderId, ProvisioningOrder::STEP_DNS, 'running', '', $dnsAccountId, []);
        $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_STEP_DNS);
        $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_DNS);
        $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
        $order->save();

        $eventData = [
            'data' => [
                'provisioning_order_id' => $orderId,
                'domain' => $domain,
                'dns_vendor' => $dnsVendor,
                'dns_account_id' => $dnsAccountId,
                'website_id' => (int) $order->getData(ProvisioningOrder::schema_fields_WEBSITE_ID),
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
        $currentDnsVendor = (string) $order->getData(ProvisioningOrder::schema_fields_DNS_VENDOR);
        $currentCdnVendor = (string) $order->getData(ProvisioningOrder::schema_fields_CDN_VENDOR);
        $currentCdnAccountId = (int) $order->getData(ProvisioningOrder::schema_fields_CDN_ACCOUNT_ID);

        $vendor = $cdnVendor ?? $currentCdnVendor;
        $accountId = $cdnAccountId ?? $currentCdnAccountId;

        if ($vendor !== '' && $vendor !== $currentDnsVendor) {
            $order->setData(ProvisioningOrder::schema_fields_DNS_VENDOR, $vendor);
            $order->setData(ProvisioningOrder::schema_fields_DNS_ACCOUNT_ID, $accountId);
            $order->save();
        }
        if ($vendor !== '') {
            $order->setData(ProvisioningOrder::schema_fields_CDN_VENDOR, $vendor);
            $order->setData(ProvisioningOrder::schema_fields_CDN_ACCOUNT_ID, $accountId);
            $order->save();
        }

        if ($vendor === '' || $accountId <= 0) {
            return [
                'success' => false,
                'message' => __('请指定 CDN 供应商与账户，或先在订单中配置 cdn_vendor/cdn_account_id'),
            ];
        }

        $adapterInfo = w_query('cdn', 'getAdapterInfo', ['adapter' => $vendor]);
        if ($adapterInfo === null) {
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, __('CDN 适配器不存在：%{1}', [$vendor]));
            $order->save();
            return ['success' => false, 'message' => __('CDN 适配器不存在：%{1}', [$vendor])];
        }

        $accountInfo = w_query('cdn', 'getAccount', ['account_id' => $accountId]);
        if ($accountInfo === null) {
            $defaultAccount = w_query('cdn', 'getDefaultAccount', ['adapter' => $vendor]);
            if ($defaultAccount !== null && (int)$defaultAccount['account_id'] !== $accountId) {
                $accountId = (int)$defaultAccount['account_id'];
                $accountInfo = $defaultAccount;
            }
        }
        if ($accountInfo === null) {
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, __('CDN 账户不存在'));
            $order->save();
            return ['success' => false, 'message' => __('CDN 账户不存在')];
        }

        $this->recordStep($orderId, ProvisioningOrder::STEP_CDN, 'running', $vendor, $accountId, []);
        $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_STEP_CDN);
        $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_CDN);
        $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
        $order->save();

        try {
            $zoneResult = w_query('cdn', 'ensureZone', [
                'domain'     => $domain,
                'account_id' => $accountId,
            ]);

            if (!($zoneResult['success'] ?? false)) {
                throw new \RuntimeException($zoneResult['message'] ?? __('ensureZone 失败'));
            }

            $zoneId = $zoneResult['zone_id'] ?? '';
            if ($zoneId === '') {
                throw new \RuntimeException(__('ensureZone 未返回 zone_id'));
            }

            $this->recordStep($orderId, ProvisioningOrder::STEP_CDN, 'success', $vendor, $accountId, ['zone_id' => $zoneId]);
            $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_CDN);
            $order->save();
            return ['success' => true, 'message' => __('CDN 绑定成功'), 'zone_id' => $zoneId];
        } catch (\Throwable $e) {
            $this->recordStep($orderId, ProvisioningOrder::STEP_CDN, 'failed', $vendor, $accountId, [], $e->getMessage());
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_FAILED);
            $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, $e->getMessage());
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
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_COMPLETED);
            $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, '');
            $order->save();
            return ['success' => true, 'message' => __('已跳过 SSL，流程完成')];
        }

        $domain = $order->getDomain();
        $websiteId = (int) $order->getData(ProvisioningOrder::schema_fields_WEBSITE_ID);
        $webroot = \defined('PUB') ? PUB : ((\defined('BP') ? BP : '') . 'pub');
        $email = Env::get('admin_email', 'admin@' . $domain);

        $this->recordStep($orderId, ProvisioningOrder::STEP_SSL, 'running', '', 0, []);
        $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_STEP_SSL);
        $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, ProvisioningOrder::STEP_SSL);
        $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
        $order->save();

        $result = w_query('server', 'requestCertificate', [
            'domain' => $domain,
            'webroot' => $webroot,
            'email' => $email,
            'website_id' => $websiteId,
            'provider' => 'letsencrypt',
        ]);

        if ($result['success'] ?? false) {
            $this->recordStep($orderId, ProvisioningOrder::STEP_SSL, 'success', '', 0, $result);
            $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_COMPLETED);
            $order->setData(ProvisioningOrder::schema_fields_CURRENT_STEP, '');
            $order->save();
            return ['success' => true, 'message' => __('证书申请成功'), 'cert' => $result['cert'] ?? null];
        }

        $msg = $result['message'] ?? __('证书申请失败');
        $this->recordStep($orderId, ProvisioningOrder::STEP_SSL, 'failed', '', 0, $result, $msg);
        $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_FAILED);
        $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, $msg);
        $order->save();
        return ['success' => false, 'message' => $msg];
    }

    /**
     * 在 CDN 绑定完成后，自动将域名 NS 切换到 CDN 供应商提供的 NS
     *
     * 支持 GName 等域名商通过适配器的 modifyDns() 方法切换 NS。
     *
     * @param int $orderId 配置订单 ID
     * @param array $nameServers CDN 供应商返回的 NS 列表
     * @return array{success: bool, message: string}
     */
    public function switchNameservers(int $orderId, array $nameServers): array
    {
        $order = clone $this->orderModel;
        $order->load($orderId);
        if (!$order->getOrderId()) {
            return ['success' => false, 'message' => __('配置订单不存在')];
        }

        $domain = $order->getDomain();
        $registrarAccountId = (int) $order->getData(ProvisioningOrder::schema_fields_REGISTRAR_ACCOUNT_ID);

        if ($registrarAccountId <= 0) {
            return ['success' => false, 'message' => __('域名商账号未配置')];
        }

        $nsString = \implode(',', $nameServers);

        try {
            $result = w_query('websites', 'modifyDns', [
                'account_id'  => $registrarAccountId,
                'domain'      => $domain,
                'nameservers' => $nsString,
            ]);
            $this->recordStep($orderId, 'ns_switch', ($result['success'] ?? false) ? 'success' : 'failed', '', $registrarAccountId, $result);
            return $result;
        } catch (\Throwable $e) {
            $this->recordStep($orderId, 'ns_switch', 'failed', '', $registrarAccountId, [], $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 获取当前服务器的公网 IPv4 地址
     */
    public function getPublicIp(): string
    {
        $endpoints = [
            'https://api-ipv4.ip.sb/ip',
            'https://api.ipify.org',
            'https://checkip.amazonaws.com',
            'https://ifconfig.me/ip',
        ];

        foreach ($endpoints as $url) {
            $ip = $this->fetchUrl($url);
            if ($ip !== null) {
                $ip = \trim($ip);
                if (\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ip;
                }
                if (\preg_match('/(\d{1,3}\.){3}\d{1,3}/', $ip, $m)) {
                    return $m[0];
                }
            }
        }

        return '';
    }

    private function fetchUrl(string $url): ?string
    {
        try {
            $ch = \curl_init($url);
            \curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Weline-Provisioning/1.0',
            ]);
            $body = \curl_exec($ch);
            $errno = \curl_errno($ch);
            \curl_close($ch);
            return ($errno === 0 && \is_string($body)) ? $body : null;
        } catch (\Throwable) {
            return null;
        }
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
        $step->setData(ProvisioningStep::schema_fields_PROVISIONING_ORDER_ID, $orderId);
        $step->setData(ProvisioningStep::schema_fields_STEP_NAME, $stepName);
        $step->setData(ProvisioningStep::schema_fields_STATUS, $status);
        $step->setData(ProvisioningStep::schema_fields_VENDOR, $vendor);
        $step->setData(ProvisioningStep::schema_fields_ACCOUNT_ID, $accountId);
        $step->setData(ProvisioningStep::schema_fields_RESULT_JSON, $result === [] ? '' : json_encode($result, JSON_UNESCAPED_UNICODE));
        $step->setData(ProvisioningStep::schema_fields_ERROR_MESSAGE, $errorMessage);
        $step->save();
    }
}
