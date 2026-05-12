<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Head;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\HeadContextProviderInterface;
use Weline\Seo\Interface\StructuredDataProviderInterface;

class HeadProviderRegistry
{
    /**
     * @var array<string, array<int, object>>|null
     */
    private ?array $cachedProviders = null;

    public function __construct(
        private readonly ObjectManager $objectManager
    ) {
    }

    /**
     * @return HeadContextProviderInterface[]
     */
    public function getHeadContextProviders(bool $forceReload = false): array
    {
        return $this->getProviders($forceReload)['head_context'] ?? [];
    }

    /**
     * @return StructuredDataProviderInterface[]
     */
    public function getStructuredDataProviders(bool $forceReload = false): array
    {
        return $this->getProviders($forceReload)['structured_data'] ?? [];
    }

    /**
     * @return array<string, array<int, object>>
     */
    private function getProviders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->cachedProviders !== null) {
            return $this->cachedProviders;
        }

        $providers = [
            'head_context' => [],
            'structured_data' => [],
        ];

        try {
            foreach (ExtendsData::getExtendedBy('Weline_Seo', $forceReload) as $extensions) {
                foreach ($extensions as $extension) {
                    $extendName = (string) ($extension['extend_name'] ?? '');
                    $class = (string) ($extension['class'] ?? '');
                    if ($class === '' || !class_exists($class)) {
                        continue;
                    }
                    $instance = $this->objectManager->getInstance($class);
                    if ($extendName === 'HeadContextProvider' && $instance instanceof HeadContextProviderInterface) {
                        $providers['head_context'][] = $instance;
                    }
                    if ($extendName === 'StructuredDataProvider' && $instance instanceof StructuredDataProviderInterface) {
                        $providers['structured_data'][] = $instance;
                    }
                }
            }
        } catch (\Throwable) {
            $providers = [
                'head_context' => [],
                'structured_data' => [],
            ];
        }

        $this->cachedProviders = $providers;
        return $providers;
    }
}
