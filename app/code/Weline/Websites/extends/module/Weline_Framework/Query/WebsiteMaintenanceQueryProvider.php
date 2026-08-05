<?php

declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Websites\Service\MaintenancePreviewTokenService;
use Weline\Websites\Service\ScopeMaintenanceGate;

final class WebsiteMaintenanceQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ScopeMaintenanceGate $gate,
        private readonly MaintenancePreviewTokenService $tokens,
    ) {
    }

    public function getProviderName(): string
    {
        return 'websiteMaintenance';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'status' => $this->status($params),
            'set' => $this->set($params),
            'issuePreview' => $this->issuePreview($params),
            'revokePreview' => $this->revokePreview($params),
            default => throw new \InvalidArgumentException('website_maintenance_operation_unsupported'),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => 'Website Scope maintenance',
            'module' => 'Weline_Websites',
            'operations' => [
                $this->operation('status', 'read', [
                    ['name' => 'scope', 'type' => 'array', 'required' => true],
                ]),
                $this->operation('set', 'write', [
                    ['name' => 'scope', 'type' => 'array', 'required' => true],
                    ['name' => 'enabled', 'type' => 'bool', 'required' => true],
                    ['name' => 'reason', 'type' => 'string', 'required' => false],
                ]),
                $this->operation('issuePreview', 'write', [
                    ['name' => 'scope', 'type' => 'array', 'required' => true],
                    ['name' => 'ttl', 'type' => 'int', 'required' => false],
                ]),
                $this->operation('revokePreview', 'write', [
                    ['name' => 'token', 'type' => 'string', 'required' => true],
                ]),
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function status(array $params): array
    {
        return ['success' => true, 'data' => $this->gate->status($this->scope($params))];
    }

    /** @param array<string,mixed> $params */
    private function set(array $params): array
    {
        $scope = $this->scope($params);
        $enabled = filter_var($params['enabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new \InvalidArgumentException('scope_maintenance_enabled_invalid');
        }
        $reason = trim((string)($params['reason'] ?? ''));
        $state = $enabled
            ? $this->gate->enable($scope, $reason, null, 'backend_query')
            : $this->gate->disable($scope, null, 'backend_query');
        return ['success' => true, 'data' => $state];
    }

    /** @param array<string,mixed> $params */
    private function issuePreview(array $params): array
    {
        $ttl = array_key_exists('ttl', $params) ? (int)$params['ttl'] : null;
        return [
            'success' => true,
            'data' => [
                'token' => $this->tokens->issue(
                    $this->scope($params),
                    $ttl,
                    null,
                    'backend_query',
                ),
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function revokePreview(array $params): array
    {
        $token = trim((string)($params['token'] ?? ''));
        return [
            'success' => true,
            'data' => [
                'revoked' => $this->tokens->revoke($token, null, 'backend_query'),
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function scope(array $params): ScopeIdentity
    {
        $scope = $params['scope'] ?? null;
        if (!is_array($scope) || array_is_list($scope)) {
            throw new \InvalidArgumentException('scope_maintenance_scope_invalid');
        }
        return ScopeIdentity::fromArray($scope);
    }

    /**
     * @param list<array{name:string,type:string,required:bool}> $params
     * @return array<string,mixed>
     */
    private function operation(string $name, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'description' => 'Durable Website Scope maintenance operation',
            'frontend' => true,
            'auth' => 'backend',
            'backend' => true,
            'backend_acl' => ['kind' => 'self'],
            'mode' => $mode,
            'params' => $params,
        ];
    }
}
