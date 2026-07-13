<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AI\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\Websites\Service\WebsiteAgentService;

/**
 * 确认并购买域名工具
 *
 * 强制人工确认门槛：必须传入 confirmed=true 才执行购买。
 * 这是高风险外部动作的强制二次确认机制。
 */
class ConfirmDomainPurchaseTool implements ToolInterface
{
    public function __construct(
        private readonly WebsiteAgentService $agentService
    ) {
    }

    public function getName(): string
    {
        return 'confirm_domain_purchase';
    }

    public function getDescription(): string
    {
        return 'Purchase a domain after explicit user confirmation. Returns purchase result with order ID on success, or error on failure.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'domain' => [
                    'type' => 'string',
                    'description' => 'Domain name to purchase (e.g. example.com)',
                ],
                'account_id' => [
                    'type' => 'integer',
                    'description' => 'Registrar account ID to use for purchase',
                ],
                'confirmed' => [
                    'type' => 'boolean',
                    'description' => 'User must explicitly confirm this as TRUE. Purchase will NOT proceed without confirmed=true.',
                ],
                'use_ai_description' => [
                    'type' => 'boolean',
                    'description' => 'If true, use site description from context as site name. Defaults to false.',
                ],
            ],
            'required' => ['domain', 'account_id', 'confirmed'],
        ];
    }

    public function execute(array $args): mixed
    {
        $domain = \trim((string)($args['domain'] ?? ''));
        $accountId = (int)($args['account_id'] ?? 0);
        $confirmed = (bool)($args['confirmed'] ?? false);
        $useAiDescription = (bool)($args['use_ai_description'] ?? false);

        if ($domain === '') {
            return [
                'success' => false,
                'error_code' => 'INVALID_DOMAIN',
                'message' => __('域名不能为空'),
            ];
        }

        if ($accountId <= 0) {
            return [
                'success' => false,
                'error_code' => 'INVALID_ACCOUNT',
                'message' => __('无效的服务商账号 ID'),
            ];
        }

        if (!$confirmed) {
            return [
                'success' => false,
                'error_code' => 'CONFIRMATION_REQUIRED',
                'message' => __('购买域名 %{domain} 需要人工确认。请明确告知用户购买风险，等待用户回复"确认"后再执行。', ['domain' => $domain]),
                'requires_confirmation' => true,
                'domain' => $domain,
                'account_id' => $accountId,
            ];
        }

        if (!$this->isAllowedTld($domain)) {
            return [
                'success' => false,
                'error_code' => 'UNSUPPORTED_TLD',
                'message' => __('暂不支持 %{tld} 后缀的域名购买', ['tld' => $this->getTld($domain)]),
            ];
        }

        $description = '';
        if ($useAiDescription) {
            $description = $this->deriveSiteName($domain);
        }

        if ($description === '') {
            $description = $domain;
        }

        try {
            $result = $this->agentService->buildFromDescription(
                $description,
                $domain,
                $accountId,
                null
            );

            if (($result['success'] ?? false)) {
                return [
                    'success' => true,
                    'domain' => $domain,
                    'account_id' => $accountId,
                    'order_id' => $result['order_id'] ?? null,
                    'purchase_status' => $result['status'] ?? 'completed',
                    'message' => __('域名 %{domain} 购买成功', ['domain' => $domain]),
                    'next_step' => 'check_domain_status',
                ];
            }

            return [
                'success' => false,
                'error_code' => 'PURCHASE_FAILED',
                'message' => $result['message'] ?? __('域名购买失败'),
                'details' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error_code' => 'EXCEPTION',
                'message' => __('购买失败: %{error}', ['error' => $e->getMessage()]),
            ];
        }
    }

    public function isEnabled(): bool
    {
        return true;
    }

    private function isAllowedTld(string $domain): bool
    {
        $allowedTlds = ['.com', '.net', '.org', '.io', '.co', '.info', '.biz', '.me', '.cc', '.tv'];
        $tld = \strtolower($this->getTld($domain));

        return \in_array($tld, $allowedTlds, true);
    }

    private function getTld(string $domain): string
    {
        $parts = \explode('.', $domain);
        if (\count($parts) >= 2) {
            return '.' . \end($parts);
        }

        return $domain;
    }

    private function deriveSiteName(string $domain): string
    {
        $name = \preg_replace('/\.[a-z]{2,}$/i', '', $domain);
        $name = \str_replace(['-', '_'], ' ', $name);
        $name = \ucwords(\strtolower($name));

        return \trim($name);
    }
}
