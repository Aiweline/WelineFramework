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

#[Acl('Weline_Ai::ai_extension_pairing', 'AI 扩展配对', 'add', '创建浏览器扩展授权配对', 'Weline_Ai::ai_extension_grants')]
class Pairing extends BackendController
{
    public function execute()
    {
        $session = SessionFactory::getInstance()->createBackendSession();
        if (!$session->isLoggedIn()) {
            return $this->errorJsonExt('backend_session_required', 401);
        }
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $grantId = bin2hex(random_bytes(16));
        $userId = (int)($session->getUser()?->getId() ?? 0);
        $grant = ObjectManager::getInstance(AiExtensionGrant::class);
        $grant->setData([
            AiExtensionGrant::schema_primary_key => $grantId,
            AiExtensionGrant::schema_fields_USER_ID => $userId,
            AiExtensionGrant::schema_fields_EXT_ID => (string)($input['ext_id'] ?? ''),
            AiExtensionGrant::schema_fields_DEVICE_FP => (string)($input['device_fp'] ?? ''),
            AiExtensionGrant::schema_fields_DEVICE_FP_HASH => hash('sha256', (string)($input['device_fp'] ?? '')),
            AiExtensionGrant::schema_fields_STATUS => AiExtensionGrant::STATUS_ACTIVE,
            AiExtensionGrant::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
        $audit = ObjectManager::getInstance(AiExtensionAudit::class);
        $audit->setData([
            AiExtensionAudit::schema_fields_GRANT_ID => $grantId,
            AiExtensionAudit::schema_fields_ACTION => AiExtensionAudit::ACTION_PAIRING,
            AiExtensionAudit::schema_fields_RESULT => 'success',
            AiExtensionAudit::schema_fields_IP => (string)$this->request->getClientIp(),
            AiExtensionAudit::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
        $secret = (string)(\Weline\Framework\Env\WelineEnv::get('ai_extension.token_secret') ?: hash('sha256', (string)\Weline\Framework\Env\WelineEnv::get('install_key', BP . 'var')));
        $tokenService = new ExtensionTokenService(ExtensionTokenService::defaultSecret());
        $now = time();
        $access = $tokenService->sign([
            'iat' => $now,
            'exp' => $now + 900,
            'grant_id' => $grantId,
            'user_id' => $userId,
            'ext_id' => (string)($input['ext_id'] ?? ''),
            'device_fp' => (string)($input['device_fp'] ?? ''),
            'backend_session_id' => (string)$session->getId(),
            'scopes' => ['model:browser-local', 'capability:translate'],
        ]);
        return $this->json(['grant_id' => $grantId, 'access' => $access, 'expires_in' => 900]);
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
