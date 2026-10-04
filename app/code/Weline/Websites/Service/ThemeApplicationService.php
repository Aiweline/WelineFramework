<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Api\Theme\ThemeApplicationInterface;
use Weline\Websites\Api\Theme\ThemeApplicationReference;
use Weline\Websites\Api\Theme\ThemeApplicationRepositoryInterface;
use Weline\Websites\Api\Theme\ThemeApplicationResolution;

/** 只读写网站应用记录；Theme 内容使用明确的不可变引用。 */
final class ThemeApplicationService implements ThemeApplicationInterface
{
    public function __construct(private readonly ThemeApplicationRepositoryInterface $repository)
    {
    }

    public function getOwn(string $scopeKey, string $storeMode, string $area): array
    {
        $this->assertIdentity($scopeKey, $storeMode, $area);
        return $this->repository->read($scopeKey, $storeMode, $area);
    }

    public function resolve(array $scopeKeys, string $storeMode, string $area): ThemeApplicationResolution
    {
        if ($scopeKeys === [] || !array_is_list($scopeKeys)) {
            throw new \InvalidArgumentException('website_theme_application_chain_invalid');
        }
        $localRevision = 0;
        foreach ($scopeKeys as $index => $scopeKey) {
            if (!is_string($scopeKey)) {
                throw new \InvalidArgumentException('website_theme_application_chain_invalid');
            }
            $record = $this->getOwn($scopeKey, $storeMode, $area);
            if ($index === 0) {
                $localRevision = $record['revision'];
            }
            if ($record['reference'] !== null) {
                return new ThemeApplicationResolution($record['reference'], $scopeKey, $index === 0, $localRevision);
            }
        }
        return new ThemeApplicationResolution(null, null, false, $localRevision);
    }

    public function save(string $scopeKey, string $storeMode, string $area, ThemeApplicationReference $reference, int $expectedRevision): array
    {
        $this->assertIdentity($scopeKey, $storeMode, $area);
        $this->assertRevision($expectedRevision);
        if ($reference->area !== $area || $reference->versionOwnerStoreMode !== $storeMode) {
            throw new \InvalidArgumentException('website_theme_application_reference_context_mismatch');
        }
        return $this->repository->compareAndSwap($scopeKey, $storeMode, $area, $reference, $expectedRevision);
    }

    public function removeOwn(string $scopeKey, string $storeMode, string $area, int $expectedRevision): array
    {
        $this->assertIdentity($scopeKey, $storeMode, $area);
        $this->assertRevision($expectedRevision);
        return $this->repository->compareAndSwap($scopeKey, $storeMode, $area, null, $expectedRevision);
    }

    private function assertIdentity(string $scopeKey, string $storeMode, string $area): void
    {
        if ($scopeKey === '' || trim($scopeKey) !== $scopeKey
            || !in_array($storeMode, ['normal', 'dev', 'test'], true)
            || !in_array($area, ['frontend', 'backend'], true)) {
            throw new \InvalidArgumentException('website_theme_application_identity_invalid');
        }
    }

    private function assertRevision(int $revision): void
    {
        if ($revision < 0) {
            throw new \InvalidArgumentException('website_theme_application_revision_invalid');
        }
    }
}
