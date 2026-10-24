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

#[Acl('Weline_Ai::ai_extension_renew', 'AI 扩展续签', 'edit', '浏览器扩展签章续签', 'Weline_Ai::ai_extension_grants')]
class Renew extends BackendController
{
    public function execute()
    {
        $session = SessionFactory::getInstance()->createBackendSession();
        if (!$session->isLoggedIn()) {
            return $this->errorJson('backend_session_required', 401);
        }
        $token = (string)($_SERVER['HTTP_X_AI_EXT_TOKEN'] ?? $this->request->getHeader('X-Ai-Ext-Token') ?? '');
        if ($token === '') {
            return $this->errorJson('invalid_token', 403);
        }
        /** @var ExtensionTokenService $tokenService */
        $tokenService = new ExtensionTokenService(ExtensionTokenService::defaultSecret());
        $payload = $tokenService->verify($token);
        if ($payload === false) {
            @file_put_contents('/tmp/renewdbg.txt', 'secret='.substr(ExtensionTokenService::defaultSecret(),0,8).' cookie='.($_COOKIE['WELINE_SESSID_8800_w0'] ?? '-')."\n", FILE_APPEND);
            return $this->errorJson('invalid_token', 403);
        }
        $grant = ObjectManager::getInstance(AiExtensionGrant::class);
        $grant->load($payload['grant_id'] ?? '');
        if (!$grant->getId() || $grant->getData(AiExtensionGrant::schema_fields_STATUS) !== AiExtensionGrant::STATUS_ACTIVE) {
            return $this->errorJson('grant_revoked', 403);
        }
        $sessionId = (string)$session->getSession()->getId();
        $newToken = $tokenService->renew($token, $sessionId, time());
        if ($newToken === false) {
            @file_put_contents('/tmp/renewdbg.txt', 'renew-false sess='.($session->getSession()->getId() ?: '-')."\n", FILE_APPEND);
            return $this->errorJson('invalid_token', 403);
        }
        $grant->setData(AiExtensionGrant::schema_fields_LAST_RENEW_AT, date('Y-m-d H:i:s'))->save();
        $audit = ObjectManager::getInstance(AiExtensionAudit::class);
        $audit->setData([
            AiExtensionAudit::schema_fields_GRANT_ID => $grant->getId(),
            AiExtensionAudit::schema_fields_ACTION => AiExtensionAudit::ACTION_RENEW,
            AiExtensionAudit::schema_fields_RESULT => 'success',
            AiExtensionAudit::schema_fields_IP => (string)$this->request->getClientIp(),
            AiExtensionAudit::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
        return $this->json(['access' => $newToken, 'expires_in' => ExtensionTokenService::TTL]);
    }

    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function errorJson(string $code, int $status): string
    {
        http_response_code($status);
        header('Content-Type: application/json');
        return json_encode(['error' => $code]);
    }
}
