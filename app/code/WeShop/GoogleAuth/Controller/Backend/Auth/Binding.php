<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Controller\Backend\Auth;

use WeShop\GoogleAuth\Service\GoogleLoginService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Url;

class Binding extends BackendController
{
    public function __construct(
        private readonly GoogleLoginService $googleLoginService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        $userId = (int) $this->session->getUserId();
        $binding = $this->googleLoginService->getBinding('backend', $userId);

        $this->assign('page_title', __('Google Account Security'));
        $this->assign('binding', $binding);
        $this->assign('bind_url', $this->url->getFrontendUrl('weshop_googleauth/frontend/auth/start', [
            'area' => 'backend',
            'mode' => 'bind',
        ]));
        $this->assign('post_url', $this->url->getBackendUrl('weshop_googleauth/backend/auth/binding'));

        return $this->fetch('WeShop_GoogleAuth::templates/Backend/Auth/binding.phtml');
    }

    public function postIndex(): void
    {
        $userId = (int) $this->session->getUserId();
        $action = trim((string) ($this->request->getPost('form_action') ?? ''));

        try {
            if ($action === 'unbind') {
                if ($this->googleLoginService->unbind('backend', $userId)) {
                    $this->getMessageManager()->addSuccess(__('Google account unbound successfully.'));
                } else {
                    $this->getMessageManager()->addWarning(__('No Google binding was found for this backend user.'));
                }
            } else {
                $this->getMessageManager()->addWarning(__('Unsupported binding action.'));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        $this->redirect($this->url->getBackendUrl('weshop_googleauth/backend/auth/binding'));
    }
}
