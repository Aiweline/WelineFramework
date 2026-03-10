<?php
declare(strict_types=1);

namespace Weline\Websites\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use Weline\Framework\Service\Query\FrameworkQueryService;

/**
 * 检查域名可用性工具
 */
class CheckDomainAvailabilityTool implements ToolInterface
{
    public function __construct(
        private readonly FrameworkQueryService $queryService
    ) {
    }

    public function getName(): string
    {
        return 'check_domain_availability';
    }

    public function getDescription(): string
    {
        return 'Check if domains are available for registration. Returns availability status for each domain.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'account_id' => [
                    'type' => 'integer',
                    'description' => 'Registrar account ID',
                ],
                'domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of domain names to check (e.g. example.com)',
                ],
            ],
            'required' => ['account_id', 'domains'],
        ];
    }

    public function execute(array $args): mixed
    {
        $accountId = (int) ($args['account_id'] ?? 0);
        $domains = $args['domains'] ?? [];
        if (!\is_array($domains)) {
            $domains = [$domains];
        }
        $domains = array_map('trim', array_filter($domains));
        if ($accountId <= 0 || $domains === []) {
            return ['error' => 'account_id and domains are required'];
        }
        return $this->queryService->execute('websites', 'checkAvailability', [
            'account_id' => $accountId,
            'domains' => $domains,
        ]);
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
