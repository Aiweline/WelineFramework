<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Faq\Api\FaqPageProviderInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

final class FaqPageProviderRegistry
{
    /** @var array<string, FaqPageProviderInterface>|null keyed by pageCode */
    private ?array $byCode = null;

    /** @var array<string, FaqPageProviderInterface>|null keyed by slug */
    private ?array $bySlug = null;

    private bool $extensionsLoaded = false;

    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    public static function forTesting(FaqPageProviderInterface ...$providers): self
    {
        $reg = new self(ObjectManager::getInstance());
        $reg->byCode = [];
        $reg->bySlug = [];
        $reg->extensionsLoaded = true;
        foreach ($providers as $provider) {
            $reg->register($provider);
        }

        return $reg;
    }

    public function register(FaqPageProviderInterface $provider): void
    {
        $code = strtolower(trim($provider->pageCode()));
        $slug = strtolower(trim($provider->slug()));
        if ($code === '' || preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $code) !== 1) {
            throw new \InvalidArgumentException((string)__('FAQ 页面编码无效。'));
        }
        if ($slug === '' || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw new \InvalidArgumentException((string)__('FAQ 页面 slug 无效。'));
        }
        if ($this->byCode === null) {
            $this->byCode = [];
        }
        if ($this->bySlug === null) {
            $this->bySlug = [];
        }
        // First registration wins (plan: 先注册胜出).
        if (!isset($this->byCode[$code])) {
            $this->byCode[$code] = $provider;
        }
        if (!isset($this->bySlug[$slug])) {
            $this->bySlug[$slug] = $provider;
        }
    }

    public function findBySlug(string $slug): ?FaqPageProviderInterface
    {
        $slug = strtolower(trim($slug));
        $provider = $this->allBySlug()[$slug] ?? null;
        if ($provider === null || !$provider->isEnabled()) {
            return null;
        }

        return $provider;
    }

    /**
     * Enabled providers sorted by sortOrder then pageCode.
     *
     * @return list<FaqPageProviderInterface>
     */
    public function enabledPages(): array
    {
        $list = array_values(array_filter(
            $this->allByCode(),
            static fn (FaqPageProviderInterface $p): bool => $p->isEnabled(),
        ));
        usort(
            $list,
            static function (FaqPageProviderInterface $a, FaqPageProviderInterface $b): int {
                $cmp = $a->sortOrder() <=> $b->sortOrder();
                return $cmp !== 0 ? $cmp : strcmp($a->pageCode(), $b->pageCode());
            },
        );

        return $list;
    }

    /**
     * @return array<string, FaqPageProviderInterface>
     */
    public function allByCode(bool $forceReload = false): array
    {
        $this->ensureLoaded($forceReload);

        return $this->byCode ?? [];
    }

    /**
     * @return array<string, FaqPageProviderInterface>
     */
    public function allBySlug(bool $forceReload = false): array
    {
        $this->ensureLoaded($forceReload);

        return $this->bySlug ?? [];
    }

    private function ensureLoaded(bool $forceReload): void
    {
        if ($this->byCode === null) {
            $this->byCode = [];
        }
        if ($this->bySlug === null) {
            $this->bySlug = [];
        }
        if ($forceReload || !$this->extensionsLoaded) {
            $this->loadExtensions($forceReload);
            $this->extensionsLoaded = true;
        }
    }

    private function loadExtensions(bool $forceReload = false): void
    {
        try {
            foreach (ExtendsData::getExtendedBy('Weline_Faq', $forceReload) as $extensions) {
                foreach ($extensions as $extension) {
                    if ($this->extensionName($extension) !== 'FaqPageProvider') {
                        continue;
                    }
                    $class = $this->extensionClass($extension);
                    if ($class === '' || !class_exists($class)) {
                        continue;
                    }
                    $instance = $this->objectManager->getInstance($class);
                    if (!$instance instanceof FaqPageProviderInterface) {
                        continue;
                    }
                    $this->register($instance);
                }
            }
        } catch (\Throwable) {
            // Optional extensions.
        }
    }

    /** @param array<string, mixed> $extension */
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

    /** @param array<string, mixed> $extension */
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
