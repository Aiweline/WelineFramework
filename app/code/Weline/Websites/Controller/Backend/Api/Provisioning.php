<?php
declare(strict_types=1);

namespace Weline\Websites\Controller\Backend\Api;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\ProvisioningOrder;
use Weline\Websites\Service\DomainLifecycleOrchestrationService;
use Weline\Websites\Service\DomainProvisioningService;

/** 一站式配置 SSE 实时进度 API（URL: websites/backend/api/provisioning/stream） */
#[Acl('Weline_Websites::provisioning_api', '配置API', 'mdi-api', '一站式配置API', 'Weline_Websites::provisioning')]
class Provisioning extends BaseController
{
    public function __construct(
        private readonly DomainProvisioningService $provisioningService,
        private readonly ProvisioningOrder $orderModel
    ) {
    }

    #[Acl('Weline_Websites::provisioning_stream', '配置进度流', 'mdi-progress-clock')]
    public function stream(): string
    {
        $domain = trim($this->request->getGet('domain', '') ?? '');
        $registrarAccountId = (int) $this->request->getGet('registrar_account_id', 0);
        $cdnVendor = trim($this->request->getGet('cdn_vendor', '') ?? '');
        $cdnAccountId = (int) $this->request->getGet('cdn_account_id', 0);
        $applySsl = (bool) $this->request->getGet('apply_ssl', 1);
        $skipPurchase = (bool) $this->request->getGet('skip_purchase', 0);

        $sse = new SseWriter();
        $sse->start();

        if ($domain === '') {
            $sse->sendEvent('failed', ['message' => __('域名不能为空')]);
            $sse->close();
            return '';
        }

        if ($registrarAccountId <= 0) {
            $sse->sendEvent('failed', ['message' => __('请选择域名商账号')]);
            $sse->close();
            return '';
        }

        try {
            if ($skipPurchase) {
                $sse->sendEvent('step_skipped', [
                    'step' => 'purchase',
                    'message' => __('跳过购买（使用已有域名）'),
                ]);
            } else {
                $sse->sendEvent('step_start', [
                    'step' => 'purchase',
                    'message' => __('开始购买域名: %{1}', [$domain]),
                ]);
            }

            $options = [
                'cdn_vendor' => $cdnVendor,
                'cdn_account_id' => $cdnAccountId > 0 ? $cdnAccountId : null,
                'apply_ssl' => $applySsl,
                'skip_purchase' => $skipPurchase,
            ];

            $result = $this->provisioningService->startProvisioning($domain, $registrarAccountId, $options);

            if (!($result['success'] ?? false)) {
                $sse->sendEvent('step_failed', [
                    'step' => 'purchase',
                    'message' => $result['message'] ?? __('创建配置订单失败'),
                ]);
                $sse->sendEvent('failed', ['message' => $result['message'] ?? __('配置失败')]);
                $sse->close();
                return '';
            }

            $orderId = (int) ($result['order_id'] ?? 0);

            if ($skipPurchase) {
                $sse->sendEvent('step_info', [
                    'step' => 'purchase',
                    'message' => __('配置订单已创建，订单号: #%{1}', [$orderId]),
                ]);
            } else {
                $sse->sendEvent('step_success', [
                    'step' => 'purchase',
                    'message' => __('域名购买成功，订单号: #%{1}', [$orderId]),
                ]);
            }

            $sse->sendEvent('step_start', [
                'step' => 'dns',
                'message' => __('开始配置 DNS...'),
            ]);

            $dnsResult = $this->provisioningService->runStepDns($orderId);

            if ($dnsResult['success'] ?? false) {
                $sse->sendEvent('step_success', [
                    'step' => 'dns',
                    'message' => $dnsResult['message'] ?? __('DNS 配置完成'),
                ]);
            } else {
                $sse->sendEvent('step_warning', [
                    'step' => 'dns',
                    'message' => $dnsResult['message'] ?? __('DNS 配置需要手动处理'),
                ]);
            }

            $sse->sendEvent('step_start', [
                'step' => 'cdn',
                'message' => __('开始绑定 CDN...'),
            ]);

            $cdnResult = $this->provisioningService->runStepCdn($orderId, $cdnVendor !== '' ? $cdnVendor : null, $cdnAccountId > 0 ? $cdnAccountId : null);

            if ($cdnResult['success'] ?? false) {
                $sse->sendEvent('step_success', [
                    'step' => 'cdn',
                    'message' => $cdnResult['message'] ?? __('CDN 绑定成功'),
                ]);

                if (!empty($cdnResult['nameservers'])) {
                    $sse->sendEvent('step_info', [
                        'step' => 'cdn',
                        'message' => __('正在切换 NS 服务器...'),
                    ]);
                    $nsResult = $this->provisioningService->switchNameservers($orderId, $cdnResult['nameservers']);
                    if ($nsResult['success'] ?? false) {
                        $sse->sendEvent('step_info', [
                            'step' => 'cdn',
                            'message' => __('NS 服务器切换成功'),
                        ]);
                    } else {
                        $sse->sendEvent('step_warning', [
                            'step' => 'cdn',
                            'message' => __('NS 切换失败: %{1}', [$nsResult['message'] ?? '']),
                        ]);
                    }
                }
            } else {
                $sse->sendEvent('step_failed', [
                    'step' => 'cdn',
                    'message' => $cdnResult['message'] ?? __('CDN 绑定失败'),
                ]);
            }

            $sse->sendEvent('step_start', [
                'step' => 'resolve',
                'message' => __('验证域名解析...'),
            ]);

            $serverIp = $this->provisioningService->getPublicIp();
            $sse->sendEvent('step_info', [
                'step' => 'resolve',
                'message' => __('服务器 IP: %{1}', [$serverIp]),
            ]);

            $resolved = false;
            for ($i = 0; $i < 6; $i++) {
                $resolvedIp = $this->resolveDomain($domain);
                if ($resolvedIp === $serverIp || $resolvedIp !== '') {
                    $resolved = true;
                    $sse->sendEvent('step_success', [
                        'step' => 'resolve',
                        'message' => __('域名解析成功: %{1} -> %{2}', [$domain, $resolvedIp]),
                    ]);
                    break;
                }
                $sse->sendEvent('step_info', [
                    'step' => 'resolve',
                    'message' => __('等待 DNS 生效... (%{1}/6)', [$i + 1]),
                ]);
                sleep(5);
            }

            if (!$resolved) {
                $sse->sendEvent('step_warning', [
                    'step' => 'resolve',
                    'message' => __('DNS 可能尚未生效，请稍后手动验证'),
                ]);
            }

            $sse->sendEvent('step_start', [
                'step' => 'verify',
                'message' => __('验证网站可访问性...'),
            ]);

            $accessible = $this->checkAccessibility($domain);
            if ($accessible) {
                $sse->sendEvent('step_success', [
                    'step' => 'verify',
                    'message' => __('网站可正常访问'),
                ]);
            } else {
                $sse->sendEvent('step_warning', [
                    'step' => 'verify',
                    'message' => __('网站暂时无法访问，DNS 可能仍在传播中'),
                ]);
            }

            if ($applySsl) {
                $sse->sendEvent('step_start', [
                    'step' => 'ssl',
                    'message' => __('开始申请 SSL 证书...'),
                ]);

                $sslResult = $this->provisioningService->runStepSsl($orderId);

                if ($sslResult['success'] ?? false) {
                    $sse->sendEvent('step_success', [
                        'step' => 'ssl',
                        'message' => $sslResult['message'] ?? __('SSL 证书申请成功'),
                    ]);
                } else {
                    $sse->sendEvent('step_warning', [
                        'step' => 'ssl',
                        'message' => $sslResult['message'] ?? __('SSL 证书申请需要进一步验证'),
                    ]);
                }
            } else {
                $sse->sendEvent('step_skipped', [
                    'step' => 'ssl',
                    'message' => __('跳过 SSL 证书申请'),
                ]);
            }

            $order = clone $this->orderModel;
            $order->load($orderId);
            if ($order->getOrderId() > 0) {
                $order->setData(ProvisioningOrder::schema_fields_STATUS, ProvisioningOrder::STATUS_COMPLETED);
                $order->setData(ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
                $order->save();
                try {
                    ObjectManager::getInstance(DomainLifecycleOrchestrationService::class)
                        ->syncRootDomainStatusWhenOrderCompleted($order);
                } catch (\Throwable $e) {
                    // 非致命
                }
            }

            $sse->sendEvent('done', [
                'message' => __('一站式配置完成！'),
                'order_id' => $orderId,
                'domain' => $domain,
            ]);
        } catch (\Throwable $e) {
            $sse->sendEvent('step_error', [
                'step' => 'unknown',
                'message' => $e->getMessage(),
            ]);
            $sse->sendEvent('failed', [
                'message' => __('配置过程发生错误: %{1}', [$e->getMessage()]),
            ]);
        }

        $sse->close();
        return '';
    }

    private function resolveDomain(string $domain): string
    {
        $ip = @gethostbyname($domain);

        return ($ip !== $domain) ? $ip : '';
    }

    private function checkAccessibility(string $domain): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'http://' . $domain,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_NOBODY => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 500;
    }
}
