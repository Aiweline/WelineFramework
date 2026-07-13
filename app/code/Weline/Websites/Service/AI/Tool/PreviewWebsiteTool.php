<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AI\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\Websites\Service\AiWorkbench\SessionService;
use Weline\Websites\Service\AiWorkbench\DomainLifecycleBridgeService;

/**
 * 获取网站预览地址工具
 *
 * 查询会话的预览 URL 和域名准备状态，返回完整的预览访问信息。
 */
class PreviewWebsiteTool implements ToolInterface
{
    public function __construct(
        private readonly SessionService $sessionService,
        private readonly DomainLifecycleBridgeService $lifecycleBridgeService
    ) {
    }

    public function getName(): string
    {
        return 'preview_website';
    }

    public function getDescription(): string
    {
        return 'Get the website preview URL and domain readiness status for the current session.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'public_id' => [
                    'type' => 'string',
                    'description' => 'The session public ID to get preview for',
                ],
            ],
            'required' => ['public_id'],
        ];
    }

    public function execute(array $args): mixed
    {
        $publicId = \trim((string)($args['public_id'] ?? ''));
        if ($publicId === '') {
            return [
                'success' => false,
                'message' => 'public_id is required',
            ];
        }

        $session = $this->sessionService->loadByPublicId($publicId, 0);
        if ($session === null) {
            $session = $this->sessionService->loadByPublicId($publicId, 1);
        }
        if ($session === null) {
            return [
                'success' => false,
                'message' => 'Session not found or not accessible',
            ];
        }

        $domainStatus = $this->lifecycleBridgeService->buildLifecycleStatus($session);
        $isReady = $this->lifecycleBridgeService->isDomainReadyForBuild($session);

        $previewUrl = $session->getPreviewUrl();
        $selectedDomain = $session->getSelectedDomain();
        $websiteId = $session->getWebsiteId();

        if ($previewUrl === '' && $selectedDomain !== '' && $isReady) {
            $protocol = 'https';
            $previewUrl = $protocol . '://' . $selectedDomain;
        }

        return [
            'success' => true,
            'public_id' => $publicId,
            'preview_url' => $previewUrl,
            'selected_domain' => $selectedDomain,
            'website_id' => $websiteId,
            'domain_status' => $domainStatus,
            'is_ready' => $isReady,
            'next_step' => $isReady ? 'confirm_materialization' : 'wait_for_domain_ready',
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
