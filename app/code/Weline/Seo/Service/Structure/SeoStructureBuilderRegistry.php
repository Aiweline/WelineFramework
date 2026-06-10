<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Structure;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Structure\Faq\FaqStructureNodeBuilder;
use Weline\Seo\Structure\Qa\QaStructureNodeBuilder;
use Weline\Seo\Structure\SeoStructureNodeBuilderInterface;

class SeoStructureBuilderRegistry
{
    /** @var SeoStructureNodeBuilderInterface[]|null */
    private ?array $cachedBuilders = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ?FaqStructureNodeBuilder $faqBuilder = null,
        private readonly ?QaStructureNodeBuilder $qaBuilder = null
    ) {
    }

    /**
     * @return SeoStructureNodeBuilderInterface[]
     */
    public function getBuilders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->cachedBuilders !== null) {
            return $this->cachedBuilders;
        }

        $builders = [
            $this->faqBuilder ?? new FaqStructureNodeBuilder(),
            $this->qaBuilder ?? new QaStructureNodeBuilder(),
        ];

        try {
            foreach (ExtendsData::getExtendedBy('Weline_Seo', $forceReload) as $extensions) {
                foreach ($extensions as $extension) {
                    if ($this->extensionName($extension) !== 'SeoStructureNodeBuilder') {
                        continue;
                    }
                    $class = $this->extensionClass($extension);
                    if ($class === '' || !class_exists($class)) {
                        continue;
                    }
                    $instance = $this->objectManager->getInstance($class);
                    if ($instance instanceof SeoStructureNodeBuilderInterface) {
                        $builders[] = $instance;
                    }
                }
            }
        } catch (\Throwable) {
        }

        $this->cachedBuilders = $builders;
        return $builders;
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionName(array $extension): string
    {
        $extendName = trim((string) ($extension['extend_name'] ?? ''));
        if ($extendName !== '') {
            return $extendName;
        }

        $filePath = str_replace('\\', '/', (string) ($extension['file_path'] ?? ''));
        $segments = explode('/', $filePath);

        return trim((string) ($segments[0] ?? ''));
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionClass(array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $class = trim((string) ($extension[$key] ?? ''));
            if ($class !== '') {
                return $class;
            }
        }

        $sourceFile = (string) ($extension['source_file'] ?? '');
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
