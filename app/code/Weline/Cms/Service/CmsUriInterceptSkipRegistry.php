<?php

declare(strict_types=1);

namespace Weline\Cms\Service;

use Weline\Cms\Api\Uri\CmsUriInterceptSkipInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

final class CmsUriInterceptSkipRegistry
{
    /** @var list<CmsUriInterceptSkipInterface>|null */
    private ?array $cached = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function shouldSkip(string $identifier, array $context = []): bool
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return false;
        }

        foreach ($this->providers() as $provider) {
            if ($provider->shouldSkipIntercept($identifier, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<CmsUriInterceptSkipInterface>
     */
    public function providers(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $providers = [];
        try {
            foreach (ExtendsData::getExtendedBy('Weline_Cms') as $extensions) {
                foreach ($extensions as $extension) {
                    $extendName = trim((string)($extension['extend_name'] ?? ''));
                    if ($extendName !== 'UriInterceptSkip') {
                        continue;
                    }
                    $class = $this->extensionClass($extension);
                    if ($class === '' || !class_exists($class)) {
                        continue;
                    }
                    $instance = $this->objectManager->getInstance($class);
                    if ($instance instanceof CmsUriInterceptSkipInterface) {
                        $providers[] = $instance;
                    }
                }
            }
        } catch (\Throwable) {
            $providers = [];
        }

        $this->cached = $providers;

        return $providers;
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

        $sourceFile = (string)($extension['source_file'] ?? '');
        if ($sourceFile === '' || !is_file($sourceFile) || !is_readable($sourceFile)) {
            return '';
        }

        $content = file_get_contents($sourceFile, false, null, 0, 4096);
        if ($content === false) {
            return '';
        }

        $namespace = '';
        $className = '';
        if (preg_match('/^\s*namespace\s+([^;]+)\s*;/m', $content, $matches) === 1) {
            $namespace = trim($matches[1]);
        }
        if (preg_match('/^\s*(?:abstract\s+)?(?:final\s+)?class\s+(\w+)/m', $content, $matches) === 1) {
            $className = trim($matches[1]);
        }

        return $namespace !== '' && $className !== '' ? $namespace . '\\' . $className : '';
    }
}
