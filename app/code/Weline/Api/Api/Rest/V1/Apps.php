<?php
declare(strict_types=1);

namespace Weline\Api\Api\Rest\V1;

use Weline\Api\Model\ApiApp;
use Weline\Api\Service\ApiAppService;
use Weline\Api\Service\ApiAppTokenService;
use Weline\Api\Service\ApiScopeCatalogService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Manager\ObjectManager;

class Apps extends FrontendRestController
{
    private ?ApiAppService $appService = null;
    private ?ApiAppTokenService $tokenService = null;
    private ?ApiScopeCatalogService $scopeCatalogService = null;

    #[Acl('Weline_Api::app_create', '创建第三方应用', 'fa fa-plus', '创建第三方应用', 'Weline_Api::integration', accessMode: 'edit')]
    public function postCreate(): array|string
    {
        try {
            [$app, $clientSecret] = $this->getAppService()->createApp(
                $this->getStringParam('name'),
                $this->getStringParam('redirect_uri'),
                $this->getStringParam('status') ?: ApiApp::STATUS_ACTIVE
            );

            return $this->success(__('应用创建成功'), [
                'app_id' => $app->getId(),
                'client_id' => $app->getClientId(),
                'client_secret' => $clientSecret,
                'name' => $app->getName(),
                'redirect_uri' => $app->getRedirectUri(),
                'status' => $app->getStatus(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), '', 400);
        }
    }

    #[Acl('Weline_Api::app_authorize_read', '查看应用授权范围', 'fa fa-key', '查看应用授权范围', 'Weline_Api::integration', accessMode: 'read')]
    public function getAuthorize(): array|string
    {
        $clientId = $this->getStringParam('client_id');
        $app = $clientId !== '' ? $this->getAppService()->loadActiveAppByClientId($clientId) : null;

        return $this->success(__('应用授权范围'), [
            'app' => $app ? [
                'app_id' => $app->getId(),
                'client_id' => $app->getClientId(),
                'name' => $app->getName(),
                'redirect_uri' => $app->getRedirectUri(),
            ] : null,
            'catalog' => $this->getScopeCatalogService()->listExposableSources(),
        ]);
    }

    #[Acl('Weline_Api::app_authorize', '批准应用授权', 'fa fa-key', '批准应用授权', 'Weline_Api::integration', accessMode: 'edit')]
    public function postAuthorize(): array|string
    {
        try {
            if ($this->request->getData('api_app_actor') !== null) {
                return $this->error(__('应用 token 不能批准新的应用授权'), '', 403);
            }

            $authorizationCode = $this->getAppService()->authorize(
                $this->getStringParam('client_id'),
                $this->getStringParam('redirect_uri'),
                $this->getStringParam('subject_type') ?: 'global',
                $this->getStringParam('subject_id'),
                $this->getArrayParam('scopes')
            );

            return $this->success(__('应用授权成功'), [
                'code' => $authorizationCode->getCode(),
                'expires_at' => $authorizationCode->getExpiresAt(),
                'redirect_uri' => $authorizationCode->getRedirectUri(),
                'redirect_url' => $this->buildRedirectUrl($authorizationCode->getRedirectUri(), $authorizationCode->getCode()),
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), '', 400);
        }
    }

    public function postToken(): array|string
    {
        $result = $this->getTokenService()->exchangeCode(
            $this->getStringParam('client_id'),
            $this->getStringParam('client_secret'),
            $this->getStringParam('code'),
            $this->getStringParam('redirect_uri')
        );
        if (!$result) {
            return $this->error(__('授权码或客户端凭证无效'), '', 401);
        }

        return $this->success(__('Token 发放成功'), $result);
    }

    public function postRefresh(): array|string
    {
        $result = $this->getTokenService()->refresh(
            $this->getStringParam('client_id'),
            $this->getStringParam('client_secret'),
            $this->getStringParam('refresh_token')
        );
        if (!$result) {
            return $this->error(__('刷新 token 无效'), '', 401);
        }

        return $this->success(__('Token 刷新成功'), $result);
    }

    public function postRevoke(): array|string
    {
        $token = $this->getStringParam('token');
        if ($token === '') {
            $token = (string)($this->request->getAuth('bearer') ?? '');
        }
        if ($token === '') {
            return $this->error(__('Token 不能为空'), '', 400);
        }

        if (!$this->getTokenService()->revoke($token)) {
            return $this->error(__('Token 不存在或已失效'), '', 404);
        }

        return $this->success(__('Token 已撤销'));
    }

    private function getAppService(): ApiAppService
    {
        if ($this->appService === null) {
            $this->appService = ObjectManager::getInstance(ApiAppService::class);
        }
        return $this->appService;
    }

    private function getTokenService(): ApiAppTokenService
    {
        if ($this->tokenService === null) {
            $this->tokenService = ObjectManager::getInstance(ApiAppTokenService::class);
        }
        return $this->tokenService;
    }

    private function getScopeCatalogService(): ApiScopeCatalogService
    {
        if ($this->scopeCatalogService === null) {
            $this->scopeCatalogService = ObjectManager::getInstance(ApiScopeCatalogService::class);
        }
        return $this->scopeCatalogService;
    }

    private function getStringParam(string $key): string
    {
        $value = $this->request->getBodyParam($key);
        if ($value === null) {
            $value = $this->request->getPost($key);
        }
        if ($value === null) {
            $value = $this->request->getParam($key);
        }
        return trim((string)($value ?? ''));
    }

    private function getArrayParam(string $key): array
    {
        $value = $this->request->getBodyParam($key);
        if ($value === null) {
            $value = $this->request->getPost($key);
        }
        if ($value === null) {
            $value = $this->request->getParam($key);
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return array_filter(array_map('trim', explode(',', $value)));
        }
        return [];
    }

    private function buildRedirectUrl(string $redirectUri, string $code): string
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        return $redirectUri . $separator . 'code=' . rawurlencode($code);
    }
}
