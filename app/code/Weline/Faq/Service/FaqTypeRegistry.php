<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Api\FaqTypeProviderInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

final class FaqTypeRegistry
{
    /** @var array<string,FaqTypeProviderInterface>|null */
    private ?array $providers = null;

    private bool $extensionsLoaded = false;

    public function __construct(
        private readonly ObjectManager $objectManager,
        ProductFaqTypeProvider $product,
        SiteFaqTypeProvider $site,
    ) {
        $this->register($product);
        $this->register($site);
    }

    public function register(FaqTypeProviderInterface $provider): void
    {
        $code = strtolower(trim($provider->typeCode()));
        if ($code === '' || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $code) !== 1) {
            throw new \InvalidArgumentException((string)__('FAQ 类型编码无效。'));
        }
        if ($this->providers === null) {
            $this->providers = [];
        }
        $this->providers[$code] = $provider;
    }

    public function get(string $typeCode): FaqTypeProviderInterface
    {
        $typeCode = strtolower(trim($typeCode));
        if (!isset($this->all()[$typeCode])) {
            throw new \InvalidArgumentException((string)__('FAQ 类型不存在或尚未注册：%{1}', [$typeCode]));
        }

        return $this->all()[$typeCode];
    }

    public function has(string $typeCode): bool
    {
        return isset($this->all()[strtolower(trim($typeCode))]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, FaqTypeProviderInterface>
     */
    public function all(bool $forceReload = false): array
    {
        if ($this->providers === null) {
            $this->providers = [];
        }
        if ($forceReload || !$this->extensionsLoaded) {
            $this->loadExtensions($forceReload);
            $this->extensionsLoaded = true;
        }

        return $this->providers;
    }

    private function loadExtensions(bool $forceReload = false): void
    {
        try {
            foreach (ExtendsData::getExtendedBy('Weline_Faq', $forceReload) as $extensions) {
                foreach ($extensions as $extension) {
                    if ($this->extensionName($extension) !== 'FaqTypeProvider') {
                        continue;
                    }
                    $class = $this->extensionClass($extension);
                    if ($class === '' || !class_exists($class)) {
                        continue;
                    }
                    $instance = $this->objectManager->getInstance($class);
                    if (!$instance instanceof FaqTypeProviderInterface) {
                        continue;
                    }
                    $this->register($instance);
                }
            }
        } catch (\Throwable) {
            // Optional extensions; keep built-in providers.
        }
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionName(array $extension): string
    {
        $extendName = trim((string)($extension['extend_name'] ?? ''));
        if ($extendName !== '') {
            return $extendName;
        }
        $filePath = str_replace('\\', '/', (string)($extension['file_path'] ?? ''));
        $segments = explode('/', $filePath);

        return trim((string)($segments[0] ?? ''));
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionClass(array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $class = trim((string)($extension[$key] ?? ''));
            if ($class !== '') {
                return $class;
            }
        }

        return $this->classFromFile((string)($extension['source_file'] ?? ''));
    }

    private function classFromFile(string $sourceFile): string
    {
        $sourceFile = trim($sourceFile);
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return '';
        }
        $contents = (string)file_get_contents($sourceFile);
        if (preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch) !== 1) {
            return '';
        }
        if (preg_match('/\bfinal\s+class\s+(\w+)/', $contents, $classMatch) !== 1
            && preg_match('/\bclass\s+(\w+)/', $contents, $classMatch) !== 1
        ) {
            return '';
        }

        return trim($namespaceMatch[1]) . '\\' . trim($classMatch[1]);
    }
}
