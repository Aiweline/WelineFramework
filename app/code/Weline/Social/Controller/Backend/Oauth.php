<?php

declare(strict_types=1);

namespace Weline\Social\Controller\Backend;

use Weline\Admin\Api\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Url;
use Weline\Social\Service\SocialOauthService;

#[Acl('Weline_Social::social_oauth', '融媒体 OAuth 回调', 'mdi mdi-shield-key-outline', '处理社媒平台一键授权回调', 'Weline_Social::social')]
class Oauth extends BaseController
{
    public function __construct(
        private readonly SocialOauthService $oauthService,
        private readonly Url $url
    ) {
    }

    #[Acl('Weline_Social::social_oauth_callback', '处理融媒体授权回调', 'mdi mdi-login-variant', '接收平台 OAuth 回调并保存账户凭据')]
    public function callback(): string
    {
        $params = $this->request->getParams() ?: [];
        if (!\is_array($params)) {
            $params = [];
        }
        $returnUrl = $this->url->getBackendUrl('weline_social/backend/social');

        try {
            if (!empty($params['error'])) {
                $message = (string)($params['error_description'] ?? $params['error'] ?? __('授权被拒绝。'));
                $this->getMessageManager()->addError($message);
                return $this->redirect($returnUrl);
            }

            $result = $this->oauthService->complete($params);
            if (!empty($result['success'])) {
                $this->getMessageManager()->addSuccess((string)($result['message'] ?? __('授权完成。')));
            } else {
                $this->getMessageManager()->addError((string)($result['message'] ?? __('授权失败。')));
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect($returnUrl);
    }
}
