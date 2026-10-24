<?php
declare(strict_types=1);
namespace Weline\Ai\Controller\Backend\Extension;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Ai\Model\AiExtensionGrant;
use Weline\Ai\Model\AiExtensionAudit;

use Weline\Ai\Service\Extension\ExtensionTokenService;

#[Acl('Weline_Ai::ai_extension_grant_revoke', 'AI 扩展撤销', 'delete', '撤销浏览器扩展授权', 'Weline_Ai::ai_extension_grants')]
class Revoke extends BackendController
{
    public function execute()
    {
        $session = SessionFactory::getInstance()->createBackendSession();
        if (!$session->isLoggedIn()) {
            return $this->errorJsonExt('backend_session_required', 401);
        }
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $grant = ObjectManager::getInstance(AiExtensionGrant::class);
        $grant->load((string)($input['grant_id'] ?? ''));
        if (!$grant->getId()) {
            return $this->errorJsonExt('grant_revoked', 403);
        }
        $grant->setData(AiExtensionGrant::schema_fields_STATUS, AiExtensionGrant::STATUS_REVOKED)->save();
        $audit = ObjectManager::getInstance(AiExtensionAudit::class);
        $audit->setData([
            AiExtensionAudit::schema_fields_GRANT_ID => $grant->getId(),
            AiExtensionAudit::schema_fields_ACTION => AiExtensionAudit::ACTION_REVOKE,
            AiExtensionAudit::schema_fields_RESULT => 'success',
            AiExtensionAudit::schema_fields_IP => (string)$this->request->getClientIp(),
            AiExtensionAudit::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
        return $this->json(['revoked' => true]);
    }

    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function errorJsonExt(string $code, int $status): string
    {
        http_response_code($status);
        header('Content-Type: application/json');
        return json_encode(['error' => $code]);
    }
}
