<?php

declare(strict_types=1);

namespace Weline\Payment\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Payment\Service\PayPalOAuthService;
use Weline\Payment\Service\PayPalPlatformCredentialService;
use Weline\Payment\Service\PaymentRedirectUriCatalog;

/**
 * Weline 维护者写入/更新平台 PayPal 沙箱 REST App（可重复编辑）。
 */
#[Acl('Weline_Payment::payment_method', '支付方式管理', 'edit', '支付方式管理', 'Weline_Backend::payment_group')]
final class PlatformSandboxSetup extends BackendController
{
    public function __construct(
        private readonly PayPalPlatformCredentialService $credentials,
        private readonly PayPalOAuthService $oauth,
        private readonly PaymentRedirectUriCatalog $redirectCatalog,
    ) {
    }

    #[Acl('Weline_Payment::paypal_platform_setup', 'PayPal 平台沙箱初始化', 'settings', 'PayPal 平台沙箱 REST App 一次性初始化')]
    public function index(): string
    {
        if (!$this->credentials->shouldUseBundledSandboxCredentials()) {
            $this->getMessageManager()->addError((string) __('当前环境不支持平台沙箱自动初始化。'));

            return $this->redirect($this->oauth->configUrl(null, ['website_code' => 'default']));
        }

        if ($this->request->isPost()) {
            return $this->save();
        }

        $platform = $this->credentials->getPlatformSandboxCredentials();
        $ready = $this->credentials->hasPlatformSandboxCredentials();
        $redirectUris = $this->redirectCatalog->suggestedSandboxRedirectUris();
        $pageTitle = (string) __('PayPal 平台沙箱 REST App 凭据');
        $this->assign('title', $pageTitle);
        $this->assign('page_title', $pageTitle);

        return $this->fetch('Weline_Payment::templates/Backend/PayPal/platform-sandbox-setup.phtml', [
            'redirect_uris' => $redirectUris,
            'developer_url' => 'https://developer.paypal.com/dashboard/applications/sandbox',
            'credentials_ready' => $ready,
            'client_id' => (string) ($platform['client_id'] ?? ''),
            'local_file' => $this->credentials->getLocalCredentialsFilePath(),
            'config_url_global' => $this->oauth->configUrl(),
            'config_url_default_website' => $this->oauth->configUrl(null, ['website_code' => 'default']),
        ]);
    }

    private function save(): string
    {
        $clientId = trim((string) $this->request->getPost('client_id', ''));
        $clientSecret = trim((string) $this->request->getPost('client_secret', ''));
        $existing = $this->credentials->getPlatformSandboxCredentials();
        if ($clientSecret === '' && trim((string) ($existing['client_secret'] ?? '')) !== '') {
            // 已配置时 Secret 留空 = 保留原值（避免误清空）。
            $clientSecret = (string) $existing['client_secret'];
        }

        try {
            $this->credentials->writeLocalPlatformSandboxCredentials($clientId, $clientSecret);
        } catch (\Throwable $exception) {
            $this->getMessageManager()->addError($exception->getMessage());

            return $this->redirect('payment/backend/platform-sandbox-setup/index');
        }

        $this->getMessageManager()->addSuccess((string) __(
            '平台 PayPal 沙箱凭据已保存。可在配置中心 Global 或默认 Website 下点击「沙箱一键授权」。'
        ));

        // 保存后留在本页，方便继续核对/改写；需要授权再点下方链接。
        return $this->redirect('payment/backend/platform-sandbox-setup/index');
    }
}
