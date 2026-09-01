<?php

declare(strict_types=1);

namespace Weline\Maintenance\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Maintenance\Service\UpgradeWaveService;
use Weline\Maintenance\Service\WaitGiftService;

/**
 * Wait-gift token API (issue/heartbeat/abandon while maintaining; redeem after recovery).
 *
 * Routes:
 * - POST /maintenance/frontend/wait-gift/issue
 * - POST /maintenance/frontend/wait-gift/heartbeat
 * - POST /maintenance/frontend/wait-gift/abandon
 * - POST /maintenance/frontend/wait-gift/redeem
 * - GET  /maintenance/frontend/wait-gift/wave
 */
class WaitGift extends FrontendController
{
    public function issue()
    {
        $service = new WaitGiftService();
        $body = $this->jsonBody();
        $result = $service->issue([
            'gate' => (string)(Cookie::get(WaitGiftService::COOKIE_GATE, '') ?: ($body['gate'] ?? '')),
            'opaque_token' => (string)(Cookie::get(WaitGiftService::COOKIE_WAIT, '') ?: ($body['token'] ?? '')),
            'browser_key' => (string)(Cookie::get(WaitGiftService::COOKIE_BROWSER, '') ?: ($body['browser_key'] ?? '')),
            'guest_token' => (string)($body['guest_token'] ?? ''),
            'customer_id' => (string)($body['customer_id'] ?? ''),
            'ip' => (string)$this->request->getClientIp(),
            'user_agent' => (string)\Weline\Framework\Env\WelineEnv::server('HTTP_USER_AGENT', ''),
        ]);

        $headers = ['Content-Type' => 'application/json; charset=utf-8'];
        if (!empty($result['success']) && !empty($result['token'])) {
            Cookie::set(WaitGiftService::COOKIE_WAIT, (string)$result['token'], 86400, [
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            if (!empty($result['browser_key'])) {
                Cookie::set(WaitGiftService::COOKIE_BROWSER, (string)$result['browser_key'], 86400 * 30, [
                    'path' => '/',
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
            }
        }

        throw new ResponseTerminateException(
            !empty($result['success']) ? 200 : 400,
            (string)\json_encode($result, \JSON_UNESCAPED_UNICODE),
            $headers,
        );
    }

    public function heartbeat()
    {
        $service = new WaitGiftService();
        $body = $this->jsonBody();
        $token = (string)(Cookie::get(WaitGiftService::COOKIE_WAIT, '') ?: ($body['token'] ?? ''));
        $result = $token === ''
            ? ['success' => false, 'error' => 'token_required', 'message' => (string)\__('缺少等待凭证')]
            : $service->heartbeat($token);

        throw new ResponseTerminateException(
            !empty($result['success']) ? 200 : 400,
            (string)\json_encode($result, \JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function abandon()
    {
        $service = new WaitGiftService();
        $body = $this->jsonBody();
        $token = (string)(Cookie::get(WaitGiftService::COOKIE_WAIT, '') ?: ($body['token'] ?? ''));
        $result = $token === ''
            ? ['success' => true, 'status' => 'noop']
            : $service->abandon($token);

        throw new ResponseTerminateException(
            200,
            (string)\json_encode($result, \JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function redeem()
    {
        $service = new WaitGiftService();
        $body = $this->jsonBody();
        $token = (string)(Cookie::get(WaitGiftService::COOKIE_WAIT, '') ?: ($body['token'] ?? ''));
        $result = $token === ''
            ? ['success' => false, 'error' => 'token_required', 'message' => (string)\__('缺少等待凭证')]
            : $service->redeem($token);

        throw new ResponseTerminateException(
            WaitGiftService::redeemHttpStatus($result),
            (string)\json_encode($result, \JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function wave()
    {
        $waves = new UpgradeWaveService();
        $wave = $waves->readWave();
        $payload = [
            'success' => true,
            'maintenance' => (bool)Env::system('maintenance'),
            'wait_gift_enabled' => (bool)(($wave['wait_gift_enabled'] ?? false)),
            'wave_id' => (string)($wave['wave_id'] ?? ''),
            'system_version' => (string)($wave['system_version_to'] ?? ''),
            'theme_version' => (string)($wave['theme_version_to'] ?? ''),
            'redeem_deadline_at' => $wave['redeem_deadline_at'] ?? null,
            'redeem_window_open' => $waves->isRedeemWindowOpen($wave),
        ];

        throw new ResponseTerminateException(
            200,
            (string)\json_encode($payload, \JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $params = $this->request->getBodyParams(true);
        if (\is_array($params) && $params !== []) {
            return $params;
        }
        $raw = $this->request->getBodyParams(false);
        if (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }
        $post = $this->request->getPost();
        return \is_array($post) ? $post : [];
    }
}
