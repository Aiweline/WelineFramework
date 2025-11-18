<?php

declare(strict_types=1);

namespace Weline\Frontend\Controller\Account;

use Weline\Frontend\Session\FrontendUserSession;

class CheckLogin extends \Weline\Framework\App\Controller\FrontendController
{
    private FrontendUserSession $session;

    public function __construct(FrontendUserSession $session)
    {
        $this->session = $session;
    }

    public function getIndex(): string
    {
        header('Content-Type: application/json');
        if (!$this->session->isLogin()) {
            return json_encode([
                'success' => false,
                'isLogin' => false,
            ], JSON_UNESCAPED_UNICODE);
        }

        $user = $this->session->getLoginUser();
        return json_encode([
            'success' => true,
            'isLogin' => true,
            'user' => [
                'user_id' => $user->getId(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'avatar' => $user->getAvatar(),
                'is_sandbox' => method_exists($user, 'isSandboxAccount') ? $user->isSandboxAccount() : false,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}


