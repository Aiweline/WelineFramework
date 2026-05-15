<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1;

use Weline\DeveloperWorkspace\Service\DevToolPayloadStore;
use Weline\Framework\App\Env;
use Weline\Framework\Controller\AbstractRestController;
use Weline\Framework\Http\Cookie;

class Trace extends AbstractRestController
{
    private const TRACE_TTL_SECONDS = 60;

    private DevToolPayloadStore $payloadStore;

    public function __construct(?DevToolPayloadStore $payloadStore = null)
    {
        parent::__construct();
        $this->payloadStore = $payloadStore ?? new DevToolPayloadStore();
    }

    public function getIndex()
    {
        if (!$this->isAllowed()) {
            return $this->error('dev tool trace is not allowed', [], 403);
        }

        $requestId = (string)$this->request->getGet('id', '');
        if ($requestId === '' || !\preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $requestId)) {
            return $this->error('invalid request id', [], 400);
        }

        $payload = $this->payloadStore->get('trace', 'trace:' . $requestId);
        if (!\is_array($payload)) {
            return $this->error('链路已过期，请刷新页面', [
                'request_id' => $requestId,
                'ttl' => self::TRACE_TTL_SECONDS,
            ], 404);
        }

        return $this->success('success', $payload);
    }

    private function isAllowed(): bool
    {
        if ((\defined('DEV') && DEV) || (\defined('DEBUG') && DEBUG)) {
            return true;
        }

        $cookieName = (string)Env::get('dev_tool.cookie_name', 'w_dev_tool');

        return Cookie::get($cookieName) === '1';
    }
}
