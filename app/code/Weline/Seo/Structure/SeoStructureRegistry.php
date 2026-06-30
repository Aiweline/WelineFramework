<?php

declare(strict_types=1);

namespace Weline\Seo\Structure;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\Structure\SeoStructureBuilderRegistry;

class SeoStructureRegistry
{
    private ?SeoStructureBuilderRegistry $builderRegistry = null;

    public function __construct(
        private readonly ?SeoStructureBuilderRegistry $seoStructureBuilderRegistry = null
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function buildNodes(array $context, string $url): array
    {
        $nodes = [];
        foreach ($this->registry()->getBuilders() as $builder) {
            if (!$builder->supports($context)) {
                continue;
            }
            foreach ($builder->buildNodes($context, $url) as $node) {
                if (is_array($node) && $node !== []) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    private function registry(): SeoStructureBuilderRegistry
    {
        if ($this->seoStructureBuilderRegistry !== null) {
            return $this->seoStructureBuilderRegistry;
        }
        if ($this->builderRegistry === null) {
            $this->builderRegistry = ObjectManager::getInstance(SeoStructureBuilderRegistry::class);
        }

        return $this->builderRegistry;
    }
}
