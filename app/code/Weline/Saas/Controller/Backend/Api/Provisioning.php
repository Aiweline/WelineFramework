<?php
declare(strict_types=1);

namespace Weline\Saas\Controller\Backend\Api;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Saas\Service\DomainProvisioningService;
use Weline\Saas\Model\ProvisioningOrder;

/**
 * 一站式配置 SSE 实时进度 API
 */
#[Acl('Weline_Saas::saas_provisioning_api', '配置API', 'mdi-api', '一站式配置API', 'Weline_Saas::saas_provisioning')]
class Provisioning extends BaseController
{
    private DomainProvisioningService $provisioningService;
    private ProvisioningOrder $orderModel;

    public function __construct(
        DomainProvisioningService $provisioningService,
        ProvisioningOrder $orderModel
    ) {
        $this->provisioningService = $provisioningService;
        $this->orderModel = $orderModel;
    }

    /**
     * SSE 端点：启动并实时跟踪配置进度
     * 
     * URL: /saas/backend/api/provisioning/stream
     * 参数: domain, registrar_account_id, cdn_vendor, cdn_account_id, apply_ssl, skip_purchase
     */
    #[Acl('Weline_Saas::saas_provisioning_stream', '配置进度流', 'mdi-progress-clock')]
    public function stream(): string
    {
        $domain = trim($this->request->getGet('domain', '') ?? '');
        $registrarAccountId = (int)$this->request->getGet('registrar_account_id', 0);
        $cdnVendor = trim($this->request->getGet('cdn_vendor', '') ?? '');
        $cdnAccountId = (int)$this->request->getGet('cdn_account_id', 0);
        $applySsl = (bool)$this->request->getGet('apply_ssl', 1);
        $skipPurchase = (bool)$this->request->getGet('skip_purchase', 0);

        $sse = new SseWriter();
        $sse->start();

        // 参数验证
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
            // 步骤 1: 创建订单/购买域名
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

            $orderId = $result['order_id'] ?? 0;
            
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

            // 步骤 2: DNS 配置
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

            // 步骤 3: CDN 绑定
            $sse->sendEvent('step_start', [
                'step' => 'cdn',
                'message' => __('开始绑定 CDN...'),
            ]);

            $cdnResult = $this->provisioningService->runStepCdn($orderId, $cdnVendor ?: null, $cdnAccountId > 0 ? $cdnAccountId : null);

            if ($cdnResult['success'] ?? false) {
                $sse->sendEvent('step_success', [
                    'step' => 'cdn',
                    'message' => $cdnResult['message'] ?? __('CDN 绑定成功'),
                ]);
                
                // 检查是否需要切换 NS
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

            // 步骤 4: 域名解析验证
            $sse->sendEvent('step_start', [
                'step' => 'resolve',
                'message' => __('验证域名解析...'),
            ]);

            $serverIp = $this->provisioningService->getPublicIp();
            $sse->sendEvent('step_info', [
                'step' => 'resolve',
                'message' => __('服务器 IP: %{1}', [$serverIp]),
            ]);

            // 检查域名解析（最多等待 30 秒）
            $resolved = false;
            for ($i = 0; $i < 6; $i++) {
                $resolvedIp = $this->resolveDomain($domain);
                if ($resolvedIp === $serverIp || !empty($resolvedIp)) {
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

            // 步骤 5: 访问性验证
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

            // 步骤 6: SSL 证书
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

            // 更新订单状态为已完成
            $order = $this->orderModel->load($orderId);
            if ($order->getOrderId() > 0) {
                $order->setData(\Weline\Saas\Model\ProvisioningOrder::schema_fields_STATUS, \Weline\Saas\Model\ProvisioningOrder::STATUS_COMPLETED);
                $order->setData(\Weline\Saas\Model\ProvisioningOrder::schema_fields_ERROR_MESSAGE, '');
                $order->save();
            }

            // 完成
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

    /**
     * 解析域名获取 IP
     */
    private function resolveDomain(string $domain): string
    {
        $ip = @gethostbyname($domain);
        return ($ip !== $domain) ? $ip : '';
    }

    /**
     * 检查网站可访问性
     */
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
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 500;
    }
}
