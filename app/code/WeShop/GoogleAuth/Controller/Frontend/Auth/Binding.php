<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Controller\Frontend\Auth;

use WeShop\Customer\Session\CustomerSession;
use WeShop\GoogleAuth\Service\GoogleLoginService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Binding extends FrontendController
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly GoogleLoginService $googleLoginService,
        private readonly Url $url
    ) {
    }

    public function index(): void
    {
        $this->redirect($this->resolveRedirectUrl((string) ($this->request->getParam('redirect_url') ?? '')));
    }

    public function postIndex(): void
    {
        $redirectUrl = $this->resolveRedirectUrl((string) ($this->request->getPost('redirect_url') ?? ''));
        if (!$this->customerSession->isLoggedIn()) {
            $this->getMessageManager()->addError(__('Please sign in to your customer account first.'));
            $this->redirect($this->url->getFrontendUrl('weshop/customer/account/login', [
                'redirect' => $redirectUrl,
            ]));
            return;
        }

        $userId = (int) $this->customerSession->getUserId();
        $action = trim((string) ($this->request->getPost('form_action') ?? ''));

        try {
            if ($action === 'unbind') {
                if ($this->googleLoginService->unbind('frontend', $userId)) {
                    $this->getMessageManager()->addSuccess(__('Google account unbound successfully.'));
                } else {
                    $this->getMessageManager()->addWarning(__('No Google binding was found for this customer account.'));
                }
            } else {
                $this->getMessageManager()->addWarning(__('Unsupported binding action.'));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        $this->redirect($redirectUrl);
    }

    private function resolveRedirectUrl(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        if ($redirectUrl === '') {
            return $this->url->getFrontendUrl('weshop/customer/account/index');
        }

        if ($this->url->isLink($redirectUrl)) {
            return Url::is_same_site($redirectUrl)
                ? $redirectUrl
                : $this->url->getFrontendUrl('weshop/customer/account/index');
        }

        return $this->url->getFrontendUrl(ltrim($redirectUrl, '/'));
    }
}
