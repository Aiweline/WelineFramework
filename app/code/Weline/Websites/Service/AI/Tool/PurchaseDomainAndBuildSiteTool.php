<?php
declare(strict_types=1);

namespace Weline\Websites\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use Weline\Websites\Service\WebsiteAgentService;

/**
 * 购买域名并建站工具
 *
 * 一站式：购买域名 → DNS 解析 → HTTPS → 创建站点
 */
class PurchaseDomainAndBuildSiteTool implements ToolInterface
{
    public function __construct(
        private readonly WebsiteAgentService $agentService
    ) {
    }

    public function getName(): string
    {
        return 'purchase_domain_and_build_site';
    }

    public function getDescription(): string
    {
        return 'Purchase a domain, configure DNS to local server, apply HTTPS certificate, and create website. One-stop site creation.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'description' => [
                    'type' => 'string',
                    'description' => 'Site description (used as site name)',
                ],
                'domain' => [
                    'type' => 'string',
                    'description' => 'Domain to purchase (e.g. example.com)',
                ],
                'account_id' => [
                    'type' => 'integer',
                    'description' => 'Registrar account ID',
                ],
            ],
            'required' => ['domain', 'account_id'],
        ];
    }

    public function execute(array $args): mixed
    {
        $description = \trim((string) ($args['description'] ?? ''));
        $domain = \trim((string) ($args['domain'] ?? ''));
        $accountId = (int) ($args['account_id'] ?? 0);
        if ($domain === '' || $accountId <= 0) {
            return ['success' => false, 'message' => 'domain and account_id are required'];
        }
        if ($description === '') {
            $description = $domain;
        }
        return $this->agentService->buildFromDescription($description, $domain, $accountId, null);
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
