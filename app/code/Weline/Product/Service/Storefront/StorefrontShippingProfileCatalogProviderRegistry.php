<?php

declare(strict_types=1);

namespace Weline\Product\Service\Storefront;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Storefront\StorefrontShippingProfileCatalogProviderInterface;

/**
 * Discovers optional StorefrontShippingProfileCatalogProvider via Product extends.
 */
final class StorefrontShippingProfileCatalogProviderRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_product/storefrontshippingprofilecatalogprovider/';

    private ?StorefrontShippingProfileCatalogProviderInterface $provider = null;

    private bool $extendsLoaded = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
    ) {
    }

    public static function forTesting(?StorefrontShippingProfileCatalogProviderInterface $provider = null): self
    {
        $reg = new self(autoLoadExtends: false);
        $reg->provider = $provider;
        $reg->extendsLoaded = true;

        return $reg;
    }

    public function primary(): ?StorefrontShippingProfileCatalogProviderInterface
    {
        $this->boot();

        return $this->provider;
    }

    private function boot(): void
    {
        if ($this->extendsLoaded || !$this->autoLoadExtends) {
            return;
        }
        $this->extendsLoaded = true;
        if (!class_exists(ExtendsData::class)) {
            return;
        }
        foreach (ExtendsData::getExtendedBy('Weline_Product') as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }
                $className = $this->extensionClass((string)$sourceModule, $extension);
                if ($className === '' || !class_exists($className)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                } catch (\Throwable) {
                    continue;
                }
                if ($instance instanceof StorefrontShippingProfileCatalogProviderInterface) {
                    $this->provider = $instance;

                    return;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionClass(string $sourceModule, array $extension): string
    {
        $explicit = trim((string)($extension['class'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $relative = trim((string)($extension['relative_path'] ?? ''), '/\\');
        if ($relative === '') {
            return '';
        }
        $vendorModule = str_replace('_', '\\', $sourceModule);
        $suffix = str_replace(['/', '.php'], ['\\', ''], $relative);

        return $vendorModule . '\\' . $suffix;
    }
}
