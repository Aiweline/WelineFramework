<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Controller\Frontend\Auth;

use WeShop\GoogleAuth\Service\GoogleOAuthService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;
use Weline\Framework\Session\SessionFactory;

class Start extends FrontendController
{
    public function __construct(
        private readonly GoogleOAuthService $googleOAuthService,
        private readonly Url $url
    ) {
    }

    public function index(): void
    {
        $area = strtolower(trim((string) ($this->request->getParam('area') ?? 'frontend')));
        $mode = strtolower(trim((string) ($this->request->getParam('mode') ?? 'login')));
        $redirectUrl = (string) ($this->request->getParam('redirect_url') ?? $this->request->getParam('redirect') ?? '');

        try {
            $localUserId = $this->resolveLocalUserId($area, $mode);
            $this->redirect($this->googleOAuthService->beginAuthorization($area, $mode, $localUserId, $redirectUrl));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
            $this->redirect($this->getFallbackUrl($area, $mode));
        }
    }

    private function resolveLocalUserId(string $area, string $mode): int
    {
        if ($mode !== 'bind') {
            return 0;
        }

        if ($area === 'backend') {
            $session = SessionFactory::backend();
            $session->start(null);
            if (!$session->isLoggedIn()) {
                throw new \RuntimeException((string) __('Please sign in to your backend account first.'));
            }

            return (int) $session->getUserId();
        }

        $session = SessionFactory::frontend();
        $session->start(null);
        if (!$session->isLoggedIn()) {
            throw new \RuntimeException((string) __('Please sign in to your customer account first.'));
        }

        return (int) $session->getUserId();
    }

    private function getFallbackUrl(string $area, string $mode): string
    {
        if ($area === 'backend' && $mode === 'bind') {
            return $this->url->getBackendUrl('weshop_googleauth/backend/auth/binding');
        }

        if ($area === 'backend') {
            return $this->url->getBackendUrl('admin/login');
        }

        if ($mode === 'bind') {
            return $this->url->getFrontendUrl('weshop/customer/account/index');
        }

        return $this->url->getFrontendUrl('weshop/customer/account/login');
    }
}
