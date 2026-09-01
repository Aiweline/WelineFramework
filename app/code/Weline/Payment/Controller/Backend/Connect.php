<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\RedirectException;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentConnectDispatcher;
use Weline\Payment\Service\PayPalPlatformCredentialService;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * Shell-unified connect entry: authorize / test / revoke by method_code.
 */
#[Acl('Weline_Payment::payment_method', '支付方式管理', 'edit', '支付方式管理', 'Weline_Backend::payment_group')]
final class Connect extends BackendController
{
    public function __construct(
        private readonly PaymentConnectDispatcher $connectDispatcher,
    ) {
    }

    #[Acl('Weline_Payment::connect_authorize', '支付一键授权', 'circle', '按 method_code 发起 Provider OAuth')]
    public function authorize(): string
    {
        $methodCode = strtolower(trim((string) $this->request->getGet('method_code', '')));
        try {
            if ($methodCode === '') {
                throw new \InvalidArgumentException((string) __('缺少 method_code'));
            }

            $scope = $this->resolveWritableConfigScope();
            $environment = trim((string) $this->request->getGet('environment', 'sandbox'));
            if ($methodCode === 'paypal' && $this->normalizeEnvironment($environment) === 'sandbox') {
                /** @var PayPalPlatformCredentialService $platform */
                $platform = ObjectManager::getInstance(PayPalPlatformCredentialService::class);
                if (!$platform->hasPlatformSandboxCredentials() && $platform->shouldUseBundledSandboxCredentials()) {
                    $this->getMessageManager()->addWarning((string) __(
                        '首次使用需完成 PayPal 沙箱平台应用一次性初始化（维护者操作，商户无需手填 Client ID/Secret）。'
                    ));

                    return $this->redirect('payment/backend/platform-sandbox-setup/index');
                }
            }

            $started = $this->connectDispatcher->start(
                $methodCode,
                $environment,
                $scope,
                $this->resolveConfigScopeContext(),
            );

            throw new RedirectException((string) $started['authorization_url'], 302);
        } catch (ResponseTerminateException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError($exception->getMessage());
            if ($methodCode !== '') {
                try {
                    $connect = $this->connectDispatcher->resolveConnect($methodCode);

                    return $this->redirect($connect->connectConfigUrl(null, $this->resolveConfigScopeContext()));
                } catch (\Throwable) {
                    // fall through
                }
            }

            return $this->redirectMethodListFallback();
        }
    }

    #[Acl('Weline_Payment::connect_test', '支付连接测试', 'link', '按 method_code 测连')]
    public function test(): string
    {
        $methodCode = strtolower(trim((string) $this->request->getGet('method_code', '')));
        try {
            $connect = $this->connectDispatcher->resolveConnect($methodCode);
            $environment = trim((string) $this->request->getGet('environment', 'sandbox'));
            $result = $connect->testConnect($environment, $this->resolveWritableConfigScope());
            if (!empty($result['success'])) {
                $this->getMessageManager()->addSuccess((string) ($result['message'] ?? __('连接测试成功')));
            } else {
                $this->getMessageManager()->addError((string) ($result['message'] ?? __('连接测试失败')));
            }

            return $this->redirect($connect->connectConfigUrl(null, $this->resolveConfigScopeContext()));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError($exception->getMessage());

            return $this->redirectMethodListFallback();
        }
    }

    #[Acl('Weline_Payment::connect_revoke', '撤销支付授权', 'link', '按 method_code 撤销 OAuth')]
    public function revoke(): string
    {
        $methodCode = strtolower(trim((string) $this->request->getGet('method_code', '')));
        try {
            $connect = $this->connectDispatcher->resolveConnect($methodCode);
            $environment = trim((string) $this->request->getGet('environment', 'sandbox'));
            $connect->revokeConnect($environment, $this->resolveWritableConfigScope());
            $this->getMessageManager()->addSuccess((string) __('授权已撤销'));

            return $this->redirect($connect->connectConfigUrl(null, $this->resolveConfigScopeContext()));
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError($exception->getMessage());

            return $this->redirectMethodListFallback();
        }
    }

    private function redirectMethodListFallback(): string
    {
        return $this->redirect('payment/backend/method', [
            'target_scope' => $this->fallbackMethodTargetScope(),
        ]);
    }

    private function fallbackMethodTargetScope(): string
    {
        try {
            $scope = $this->resolveWritableConfigScope();
            if ($scope === SystemConfig::SCOPE_GLOBAL
                || \preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+){2}$/D', $scope) === 1
            ) {
                return $scope;
            }
        } catch (\Throwable) {
            // fall through to default website
        }

        return 'default.default.default';
    }

    /**
     * @return array{scope:?string,website_code:string,store_code:string,channel_code:string,locale:string}
     */
    private function resolveConfigScopeContext(): array
    {
        $scope = trim((string) $this->request->getGet('scope', ''));
        $websiteCode = strtolower(trim((string) $this->request->getGet('website_code', '')));
        $storeCode = strtolower(trim((string) $this->request->getGet('store_code', '')));
        $channelCode = strtolower(trim((string) $this->request->getGet('channel_code', '')));
        $locale = trim((string) $this->request->getGet('locale', ''));

        if ($scope === '' && ($websiteCode !== '' || $storeCode !== '' || $channelCode !== '')) {
            /** @var SystemConfigTargetScopeService $targetScopeService */
            $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);
            $target = $targetScopeService->resolveFromInput([
                'website_code' => $websiteCode,
                'store_code' => $storeCode,
                'channel_code' => $channelCode,
            ], false);
            $scope = (string) ($target['storage_scope'] ?? SystemConfig::SCOPE_GLOBAL);
            if ($websiteCode === '' && ($target['kind'] ?? '') !== SystemConfigTargetScopeService::KIND_GLOBAL) {
                $websiteCode = strtolower(trim((string) ($target['website_code'] ?? '')));
            }
            if ($storeCode === '' && ($target['kind'] ?? '') !== SystemConfigTargetScopeService::KIND_GLOBAL) {
                $storeCode = strtolower(trim((string) ($target['store_code'] ?? '')));
            }
            if ($channelCode === '' && ($target['kind'] ?? '') !== SystemConfigTargetScopeService::KIND_GLOBAL) {
                $channelCode = strtolower(trim((string) ($target['channel_code'] ?? '')));
            }
        }

        if ($scope === '') {
            $referer = (string) $this->request->getServer('HTTP_REFERER');
            $refererQuery = parse_url($referer, PHP_URL_QUERY);
            $refererParams = [];
            if (\is_string($refererQuery)) {
                parse_str($refererQuery, $refererParams);
            }
            $scope = trim((string) ($refererParams['scope'] ?? ''));
            if ($websiteCode === '') {
                $websiteCode = strtolower(trim((string) ($refererParams['website_code'] ?? '')));
            }
            if ($storeCode === '') {
                $storeCode = strtolower(trim((string) ($refererParams['store_code'] ?? '')));
            }
            if ($channelCode === '') {
                $channelCode = strtolower(trim((string) ($refererParams['channel_code'] ?? '')));
            }
            if ($locale === '') {
                $locale = trim((string) ($refererParams['locale'] ?? ''));
            }
        }

        return [
            'scope' => $scope,
            'website_code' => $websiteCode,
            'store_code' => $storeCode,
            'channel_code' => $channelCode,
            'locale' => $locale,
        ];
    }

    private function resolveWritableConfigScope(): string
    {
        $context = $this->resolveConfigScopeContext();
        /** @var SystemConfigTargetScopeService $targetScopeService */
        $targetScopeService = ObjectManager::getInstance(SystemConfigTargetScopeService::class);

        $websiteCode = strtolower(trim((string) ($context['website_code'] ?? '')));
        $storeCode = strtolower(trim((string) ($context['store_code'] ?? '')));
        $channelCode = strtolower(trim((string) ($context['channel_code'] ?? '')));
        $scope = trim((string) ($context['scope'] ?? ''));

        // 写路径禁止短 scope（如 default）；优先 website/store/channel 段，否则仅接受三段 storage_scope。
        // 禁止空参静默写 Global——店铺一点授权却落到 default.default.default。
        if ($websiteCode !== '' || $storeCode !== '' || $channelCode !== '') {
            $target = $targetScopeService->resolveFromInput([
                'website_code' => $websiteCode,
                'store_code' => $storeCode,
                'channel_code' => $channelCode,
            ], false);
        } elseif ($scope !== '' && substr_count($scope, '.') === 2) {
            $target = $targetScopeService->resolveFromInput([
                'scope' => $scope,
            ], false);
        } else {
            throw new \InvalidArgumentException((string) __(
                '一键授权缺少显式配置范围：请从配置中心选择 Website/Store/Channel（或 Global）后再授权。'
            ));
        }

        return (string) ($target['storage_scope'] ?? '');
    }

    private function normalizeEnvironment(string $environment): string
    {
        return strtolower(trim($environment)) === 'live' ? 'live' : 'sandbox';
    }
}
