<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller;

use Weline\Deploy\Service\DeployWebhookReleaseService;
use Weline\Framework\App\Controller\FrontendController;

class Webhook extends FrontendController
{
    public function __construct(
        private readonly DeployWebhookReleaseService $webhookReleaseService
    ) {
    }

    public function deploy(): string
    {
        $config = $this->webhookReleaseService->loadConfig();

        if ($this->request->isGet() && (string)$this->request->getGet('health', '') === '1') {
            return $this->fetchJson($this->webhookReleaseService->healthPayload($config, $this->requestContext()));
        }

        if (!$this->request->isPost()) {
            return $this->fetchJson(['ok' => false, 'error' => 'only POST is allowed'], 405);
        }

        $rawBody = $this->rawBody();
        $resolved = $this->webhookReleaseService->resolveEffectiveConfigForWebhook(
            $rawBody,
            $config,
            $this->requestContext()
        );
        $effectiveConfig = $resolved['config'];
        $releaseContext = $resolved['context'];
        $secret = (string)($effectiveConfig['WEBHOOK_SECRET'] ?? '');
        if ($secret === '') {
            return $this->fetchJson(['ok' => false, 'error' => 'WEBHOOK_SECRET is empty'], 500);
        }

        if (!$this->webhookReleaseService->isValidToken(
            $secret,
            $rawBody,
            (string)$this->request->getHeader('X-Gitee-Token'),
            (string)$this->request->getHeader('X-Gitee-Timestamp'),
            (string)$this->request->getHeader('Authorization'),
            (string)$this->request->getGet('token', ''),
            (string)$this->request->getHeader('X-Hub-Signature-256')
        )) {
            return $this->fetchJson(['ok' => false, 'error' => 'invalid webhook token'], 403);
        }

        $result = $this->webhookReleaseService->releaseFromWebhook($rawBody, $effectiveConfig, $releaseContext);
        return $this->fetchJson($result['payload'], $result['status']);
    }

    /**
     * @return array<string, string>
     */
    private function requestContext(): array
    {
        return [
            'profile_key' => trim((string)$this->request->getGet('profile_key', '')),
            'project_id' => trim((string)$this->request->getGet('project_id', '')),
            'domain' => trim((string)$this->request->getGet('domain', '')),
            'project_type' => trim((string)$this->request->getGet('project_type', '')),
        ];
    }

    private function rawBody(): string
    {
        $body = $this->request->getBodyParams();
        if (is_string($body)) {
            return $body;
        }
        if (is_array($body)) {
            return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return '';
    }
}
