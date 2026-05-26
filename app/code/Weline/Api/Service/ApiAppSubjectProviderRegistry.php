<?php
declare(strict_types=1);

namespace Weline\Api\Service;

use Weline\Api\Api\ApiAppSubjectProviderInterface;

class ApiAppSubjectProviderRegistry
{
    /** @var array<string, ApiAppSubjectProviderInterface> */
    private array $providers = [];

    public function register(ApiAppSubjectProviderInterface $provider): void
    {
        $this->providers[$provider->getSubjectType()] = $provider;
    }

    public function get(string $subjectType): ?ApiAppSubjectProviderInterface
    {
        return $this->providers[$subjectType] ?? null;
    }

    public function validate(string $subjectType, string $subjectId): bool
    {
        if ($subjectType === 'global') {
            return $subjectId === '' || $subjectId === '0' || $subjectId === 'default';
        }

        $provider = $this->get($subjectType);
        return $provider !== null && $provider->validateSubject($subjectId);
    }

    public function label(string $subjectType, string $subjectId): string
    {
        if ($subjectType === 'global') {
            return 'Global';
        }

        $provider = $this->get($subjectType);
        return $provider ? $provider->getSubjectLabel($subjectId) : '';
    }
}
