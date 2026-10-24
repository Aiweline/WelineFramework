<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiExtensionAudit;
use Weline\Ai\Model\AiExtensionGrant;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Ai::ai_extension_grants', 'AI 扩展授权', 'circle', 'AI 浏览器扩展授权管理', 'Weline_Backend::ai_group')]
class ExtensionGrants extends BackendController
{
    #[Acl('Weline_Ai::ai_extension_grants', '查看 AI 扩展授权', 'list', '查看当前账号授权设备')]
    public function index(): string
    {
        $userId = (int)$this->session->getUserId();
        $grantModel = $this->grantModel();
        $grants = $grantModel->clear()
            ->where(AiExtensionGrant::schema_fields_USER_ID, $userId)
            ->order(AiExtensionGrant::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch()->all();
        $pairingCode = '';
        $hasActive = false;
        foreach ($grants as $grant) {
            if ((string)($grant[AiExtensionGrant::schema_fields_STATUS] ?? '') === AiExtensionGrant::STATUS_ACTIVE) {
                $hasActive = true;
                break;
            }
        }
        if (!$hasActive) {
            $pairingCode = bin2hex(random_bytes(16));
            $this->audit(AiExtensionAudit::ACTION_PAIRING, 'success', $pairingCode);
        }
        $this->assign('activeTab', 'extension');
        $this->assign('grants', $grants);
        $this->assign('pairingCode', $pairingCode);
        return $this->fetch();
    }

    #[Acl('Weline_Ai::ai_extension_grant_revoke', '撤销 AI 扩展授权', 'delete', '撤销当前账号授权设备')]
    public function postRevoke(): string
    {
        $grantId = trim((string)$this->bodyValue('grant_id', ''));
        if ($grantId === '') {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('授权ID不能为空。')], 400);
        }
        $grantModel = $this->grantModel()->clear()->load($grantId);
        if ((string)$grantModel->getData(AiExtensionGrant::schema_fields_USER_ID) !== (string)$this->session->getUserId()) {
            return $this->jsonResponse(['success' => false, 'message' => (string)__('无权撤销该授权。')], 403);
        }
        try {
            $grantModel->setData(AiExtensionGrant::schema_fields_STATUS, AiExtensionGrant::STATUS_REVOKED)->save();
            $this->audit(AiExtensionAudit::ACTION_REVOKE, 'success', $grantId);
            return $this->jsonResponse(['success' => true]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'message' => $throwable->getMessage()], 400);
        }
    }

    private function grantModel(): AiExtensionGrant
    {
        return ObjectManager::getInstance(AiExtensionGrant::class);
    }

    private function audit(string $action, string $result, string $grantId): void
    {
        /** @var AiExtensionAudit $audit */
        $audit = ObjectManager::getInstance(AiExtensionAudit::class);
        $audit->clear();
        $audit->setData(AiExtensionAudit::schema_fields_GRANT_ID, $grantId);
        $audit->setData(AiExtensionAudit::schema_fields_ACTION, $action);
        $audit->setData(AiExtensionAudit::schema_fields_RESULT, $result);
        $audit->setData(AiExtensionAudit::schema_fields_IP, (string)($this->request->getClientIp() ?? ''));
        $audit->setData(AiExtensionAudit::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
        $audit->save();
    }

    private function bodyValue(string $key, mixed $default = null): mixed
    {
        return $this->request->getBodyParam($key, $this->request->getPost($key, $default));
    }

    private function jsonResponse(array $data, int $statusCode = 200): string
    {
        $this->request->getResponse()->setHttpResponseCode($statusCode);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
