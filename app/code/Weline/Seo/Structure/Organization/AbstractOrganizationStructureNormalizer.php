<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Organization;

use Weline\Seo\Structure\AbstractSeoStructureNormalizer;
use Weline\Seo\Structure\SeoStructureFactsInterface;

/**
 * 组织/本地商家结构化事实归一化基类。
 *
 * 标准 context 键：organization
 * 典型字段：name、url、logo、telephone、address、sameAs
 */
abstract class AbstractOrganizationStructureNormalizer extends AbstractSeoStructureNormalizer implements SeoStructureFactsInterface
{
    /**
     * @return array<string, mixed>
     */
    abstract public function normalize(mixed $organization): array;

    /**
     * @param array<string, mixed> $organization
     * @return array<string, mixed>
     */
    protected function baseOrganizationFacts(array $organization): array
    {
        $facts = [];
        foreach ([
            'name' => ['name', 'legalName'],
            'url' => ['url', 'website'],
            'logo' => ['logo', 'image'],
            'telephone' => ['telephone', 'phone'],
        ] as $target => $aliases) {
            $value = $this->firstString($organization, $aliases);
            if ($value !== '') {
                $facts[$target] = $value;
            }
        }
        if (isset($organization['address']) && is_array($organization['address'])) {
            $facts['address'] = $organization['address'];
        }
        if (isset($organization['sameAs']) && is_array($organization['sameAs'])) {
            $facts['sameAs'] = array_values(array_filter($organization['sameAs'], static fn ($url): bool => is_string($url) && trim($url) !== ''));
        }

        return $facts;
    }
}
