<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1\Seo;

use Weline\DeveloperWorkspace\Api\DevToolRestController;
use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\PanelGoogleUrlInspectionService;

/**
 * Panel-gated Google URL Inspection. Requires weline panel auth; never storefront.
 */
class Gsc extends DevToolRestController
{
    public function postInspect()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('SEO GSC 检测需要有效的 Weline Panel Token。', [], 403);
            }

            $payload = $this->requestPayload();
            $url = trim((string)($payload['url'] ?? $payload['inspectionUrl'] ?? $this->currentRequestUrl()));
            $service = ObjectManager::getInstance(PanelGoogleUrlInspectionService::class);
            $result = $service->inspect($url);

            if (!(bool)($result['success'] ?? false)) {
                $status = (string)($result['status'] ?? 'error');
                $code = $status === 'account_unbound' ? 422 : ($status === 'invalid_url' ? 400 : 502);

                return $this->error((string)($result['message'] ?? 'GSC inspect failed'), [
                    'status' => $status,
                    'data' => $result['data'] ?? [],
                ], $code);
            }

            return $this->success((string)($result['message'] ?? 'success'), [
                'status' => 'ok',
                'data' => $result['data'] ?? [],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(): array
    {
        $body = $this->request->getBodyParams(true);
        if (is_array($body)) {
            return $body;
        }

        $raw = $this->request->getBodyParams(false);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function isAllowed(): bool
    {
        return (new PanelAccessService())->canAccessApi($this->request);
    }

    private function currentRequestUrl(): string
    {
        try {
            $uri = (string)$this->request->getUri();
            if ($uri !== '' && preg_match('#^https?://#i', $uri)) {
                return $uri;
            }
        } catch (\Throwable) {
        }

        return '';
    }
}
