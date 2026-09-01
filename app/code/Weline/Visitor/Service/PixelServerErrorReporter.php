<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * Checkout / 业务模块服务端失败上报入口（与客户端 site_error 共用去重指纹）。
 */
class PixelServerErrorReporter
{
    public function __construct(
        private ?PixelErrorIncidentService $incidentService = null
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function reportBusinessFailure(string $errorCode, string $message, array $context = []): void
    {
        try {
            $service = $this->incidentService
                ?? \Weline\Framework\Manager\ObjectManager::getInstance(PixelErrorIncidentService::class);
            $service->recordServerSide([
                'error_code' => $errorCode,
                'error_message' => $message,
                'message' => $message,
                'website_id' => (int)($context['website_id'] ?? 0),
                'session_id' => (string)($context['session_id'] ?? ''),
                'user_id' => (int)($context['user_id'] ?? 0),
                'identity_email' => (string)($context['identity_email'] ?? ''),
                'page_url' => (string)($context['page_url'] ?? ''),
                'http_status' => (int)($context['http_status'] ?? 0),
                'capture_source' => 'server',
            ]);
        } catch (\Throwable $e) {
            w_log_error('PixelServerErrorReporter failed: ' . $e->getMessage());
        }
    }
}
