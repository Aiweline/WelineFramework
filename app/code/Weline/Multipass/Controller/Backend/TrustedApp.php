<?php
declare(strict_types=1);

namespace Weline\Multipass\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Model\TrustedApp as TrustedAppModel;
use Weline\Multipass\Service\IdentityBridgeService;

#[Acl('Weline_Multipass::trusted_app_management', '互通应用管理', 'mdi mdi-application-cog', '管理可信互通应用', 'Weline_Multipass::menu_multipass_management')]
class TrustedApp extends BackendController
{
    private ?IdentityBridgeService $identityBridgeService = null;

    public function index()
    {
        $apps = $this->getIdentityBridgeService()->listTrustedApps();
        $bindingCounts = [];
        foreach ($apps as $app) {
            if ($app instanceof TrustedAppModel) {
                $bindingCounts[$app->getId()] = $this->getIdentityBridgeService()->countBindingsForApp($app->getId());
            }
        }

        $this->assign([
            'apps' => $apps,
            'binding_counts' => $bindingCounts,
            'developer_application_auto_approve' => $this->getIdentityBridgeService()->isDeveloperApplicationAutoApproveEnabled(),
            'page_title' => __('互通应用'),
        ]);

        return $this->fetch();
    }

    public function save()
    {
        try {
            $appId = (int) $this->request->getParam('app_id', 0);
            $allowedScopes = $this->getScopesParam();
            if ($appId > 0) {
                $app = $this->getIdentityBridgeService()->updateTrustedApp(
                    $appId,
                    $this->getStringParam('name'),
                    $this->getStringParam('redirect_uri'),
                    $this->getStringParam('trusted_domain'),
                    $this->getStringParam('app_type') ?: 'app',
                    $allowedScopes,
                    $this->getStringParam('status') ?: TrustedAppModel::STATUS_ACTIVE,
                    $this->getStringParam('application_status') ?: TrustedAppModel::APPLICATION_APPROVED
                );

                return $this->fetchJson([
                    'success' => true,
                    'message' => __('互通应用已保存'),
                    'app' => $this->formatApp($app),
                ]);
            }

            [$app, $clientSecret] = $this->getIdentityBridgeService()->createTrustedApp(
                $this->getStringParam('name'),
                $this->getStringParam('redirect_uri'),
                $this->getStringParam('trusted_domain'),
                $this->getStringParam('app_type') ?: 'app',
                $allowedScopes,
                $this->getStringParam('status') ?: TrustedAppModel::STATUS_ACTIVE,
                0,
                $this->getStringParam('application_status') ?: TrustedAppModel::APPLICATION_APPROVED
            );

            return $this->fetchJson([
                'success' => true,
                'message' => __('互通应用已创建，请立即保存 client_secret'),
                'app' => $this->formatApp($app),
                'client_secret' => $clientSecret,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function rotateSecret()
    {
        try {
            $appId = (int) $this->request->getParam('app_id', 0);
            if ($appId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('应用 ID 不能为空'),
                ]);
            }

            [$app, $clientSecret] = $this->getIdentityBridgeService()->rotateClientSecret($appId);

            return $this->fetchJson([
                'success' => true,
                'message' => __('client_secret 已轮换，请立即保存'),
                'app' => $this->formatApp($app),
                'client_secret' => $clientSecret,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function getIdentityBridgeService(): IdentityBridgeService
    {
        if ($this->identityBridgeService === null) {
            $this->identityBridgeService = ObjectManager::getInstance(IdentityBridgeService::class);
        }

        return $this->identityBridgeService;
    }

    private function getStringParam(string $key): string
    {
        return trim((string) ($this->request->getParam($key, '') ?? ''));
    }

    private function getScopesParam(): array
    {
        $value = $this->request->getParam('allowed_scopes', []);
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn($scope) => trim((string) $scope), $value)));
        }
        if (is_string($value) && trim($value) !== '') {
            return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $value) ?: [])));
        }

        return [];
    }

    private function formatApp(TrustedAppModel $app): array
    {
        return [
            'app_id' => $app->getId(),
            'client_id' => $app->getClientId(),
            'name' => $app->getName(),
            'app_type' => $app->getAppType(),
            'trusted_domain' => $app->getTrustedDomain(),
            'redirect_uri' => $app->getRedirectUri(),
            'allowed_scopes' => $app->getAllowedScopes(),
            'applicant_customer_id' => $app->getApplicantCustomerId(),
            'application_status' => $app->getApplicationStatus(),
            'status' => $app->getStatus(),
        ];
    }
}
