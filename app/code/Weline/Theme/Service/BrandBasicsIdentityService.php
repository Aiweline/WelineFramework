<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;

/** Load/save Theme Editor brand-basics identity via registered providers. */
final class BrandBasicsIdentityService
{
    public function __construct(
        private readonly BrandBasicsIdentityRegistry $registry,
        private readonly ScopeIdentityCatalogInterface $catalog,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function loadFromInput(array $input): array
    {
        $identity = $this->resolveIdentity($input);
        $provider = $this->registry->forIdentity($identity);
        if ($provider === null) {
            return $this->emptyPayload($identity);
        }

        $payload = $provider->load($identity);

        return $this->normalizePayload($provider->getCode(), $provider->getModule(), $payload, $identity);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveFromInput(array $input): array
    {
        $identity = $this->resolveIdentity($input);
        $provider = $this->registry->forIdentity($identity);
        if ($provider === null) {
            throw new \InvalidArgumentException('theme_brand_basics_identity_unavailable');
        }

        $values = $input['values'] ?? null;
        if (\is_string($values)) {
            $values = \json_decode($values, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($values)) {
            throw new \InvalidArgumentException('theme_brand_basics_identity_values_required');
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            if (!\is_string($key) && !\is_int($key)) {
                continue;
            }
            $normalized[(string)$key] = \is_scalar($value) || $value === null
                ? \trim((string)$value)
                : '';
        }

        $payload = $provider->save($identity, $normalized);

        return $this->normalizePayload($provider->getCode(), $provider->getModule(), $payload, $identity);
    }

    /**
     * @param array<string,mixed> $input
     */
    private function resolveIdentity(array $input): ScopeIdentity
    {
        $raw = $input['editor_context'] ?? $input;
        if (\is_string($raw)) {
            $raw = \json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('theme_editor_context_required');
        }

        $scope = $raw['scope'] ?? null;
        if (\is_string($scope)) {
            $scope = \json_decode($scope, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($scope)) {
            throw new \InvalidArgumentException('theme_editor_typed_scope_required');
        }
        $claims = \is_array($scope['identity'] ?? null) ? $scope['identity'] : $scope;
        $candidate = ScopeIdentity::fromArray($claims);

        return $this->catalog->authoritativeIdentity($candidate);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizePayload(
        string $providerCode,
        string $module,
        array $payload,
        ScopeIdentity $identity,
    ): array {
        $fields = [];
        foreach (\is_array($payload['fields'] ?? null) ? $payload['fields'] : [] as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $key = \trim((string)($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $fields[] = [
                'key' => $key,
                'type' => \trim((string)($field['type'] ?? 'text')) ?: 'text',
                'label' => \trim((string)($field['label'] ?? $key)) ?: $key,
                'required' => !empty($field['required']),
                'max' => isset($field['max']) ? (int)$field['max'] : null,
                'rows' => isset($field['rows']) ? (int)$field['rows'] : null,
            ];
        }

        $values = [];
        foreach (\is_array($payload['values'] ?? null) ? $payload['values'] : [] as $key => $value) {
            $values[(string)$key] = \trim((string)$value);
        }

        return [
            'available' => $fields !== [],
            'provider' => $providerCode,
            'module' => $module,
            'label' => \trim((string)($payload['label'] ?? '')) ?: (string)__('身份信息'),
            'scope_kind' => \trim((string)($payload['scope_kind'] ?? $identity->scopeKind)),
            'fields' => $fields,
            'values' => $values,
            'identity' => $identity->toArray(),
        ];
    }

    /** @return array<string,mixed> */
    private function emptyPayload(ScopeIdentity $identity): array
    {
        return [
            'available' => false,
            'provider' => '',
            'module' => '',
            'label' => (string)__('身份信息'),
            'scope_kind' => $identity->scopeKind,
            'fields' => [],
            'values' => [],
            'identity' => $identity->toArray(),
        ];
    }
}
