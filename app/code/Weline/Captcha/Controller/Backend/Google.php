<?php

declare(strict_types=1);

namespace Weline\Captcha\Controller\Backend;

use Weline\Captcha\Service\GoogleOAuthService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\Message;

#[Acl('Weline_Captcha::config', '人机验证配置', 'mdi mdi-shield-check', '系统配置')]
final class Google extends BackendController
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly Url $url,
    ) {
    }

    #[Acl('Weline_Captcha::google_authorize', '授权 Google reCAPTCHA Enterprise', 'mdi mdi-google', '人机验证配置')]
    public function authorize(): string
    {
        try {
            $scope = \trim((string)$this->request->getGet('scope', '')) ?: null;
            if ($scope === null) {
                $referer = (string)$this->request->getServer('HTTP_REFERER');
                $refererQuery = \parse_url($referer, PHP_URL_QUERY);
                $refererParams = [];
                if (\is_string($refererQuery)) {
                    \parse_str($refererQuery, $refererParams);
                }
                $candidate = \trim((string)($refererParams['scope'] ?? ''));
                if ($candidate !== '' && \preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/D', $candidate) === 1) {
                    $scope = $candidate;
                }
            }
            $result = $this->oauth->start($scope);
            return $this->redirect($result['authorization_url']);
        } catch (\Throwable $exception) {
            Message::error($exception->getMessage());
            return $this->redirect($this->configUrl());
        }
    }

    #[Acl('Weline_Captcha::google_callback', '处理 Google reCAPTCHA Enterprise 回调', 'mdi mdi-login-variant', '人机验证配置')]
    public function callback(): string
    {
        $result = [];
        try {
            $params = $this->request->getParams();
            if (!\is_array($params)) {
                $params = [];
            }
            if (!empty($params['error'])) {
                throw new \RuntimeException((string)($params['error_description'] ?? $params['error']));
            }
            $result = $this->oauth->complete($params);
            Message::success((string)($result['message'] ?? __('Google 授权成功')));
        } catch (\Throwable $exception) {
            Message::error($exception->getMessage());
        }
        if (($result['needs_project'] ?? false) === true) {
            return $this->redirect($this->url->getBackendUrl(
                'captcha/backend/google/projects',
                ['scope' => (string)($result['scope'] ?? '')],
            ));
        }
        return $this->redirect($this->configUrl());
    }

    #[Acl('Weline_Captcha::google_projects', '选择 Google Cloud Project', 'mdi mdi-cloud-outline', '人机验证配置')]
    public function projects(): string
    {
        $this->assign('config_scope', \trim((string)$this->request->getGet('scope', '')));
        return $this->fetch('Weline_Captcha::templates/Backend/Google/projects.phtml');
    }

    #[Acl('Weline_Captcha::google_test', '测试 Google reCAPTCHA Enterprise 连接', 'mdi mdi-connection', '人机验证配置')]
    public function test(): string
    {
        try {
            $result = $this->oauth->testConnection();
            Message::success((string)($result['message'] ?? __('Google 连接测试成功')));
        } catch (\Throwable $exception) {
            Message::error($exception->getMessage());
        }
        return $this->redirect($this->configUrl());
    }

    #[Acl('Weline_Captcha::google_revoke', '撤销 Google reCAPTCHA Enterprise 授权', 'mdi mdi-link-off', '人机验证配置')]
    public function revoke(): string
    {
        return $this->fetch('Weline_Captcha::templates/Backend/Google/revoke.phtml');
    }

    private function configUrl(): string
    {
        return $this->url->getBackendUrl('weline_systemconfig/backend/config', [
            'module' => 'Weline_Captcha',
            'area' => 'backend',
        ]);
    }
}
