<?php

declare(strict_types=1);

namespace Weline\Cms\Service;

use Weline\Cms\Api\Kind\CmsPageKindInterface;
use Weline\Cms\Kind\DefaultCmsPageKind;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

final class CmsPageKindRegistry
{
    /** @var list<CmsPageKindInterface>|null */
    private ?array $cached = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly DefaultCmsPageKind $defaultKind = new DefaultCmsPageKind(),
    ) {
    }

    public function default(): CmsPageKindInterface
    {
        return $this->defaultKind;
    }

    public function getByCode(string $code): ?CmsPageKindInterface
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return null;
        }
        foreach ($this->all() as $kind) {
            if (strtolower($kind->getCode()) === $code) {
                return $kind;
            }
        }

        return null;
    }

    public function resolveByPathGroup(string $pathGroup): CmsPageKindInterface
    {
        $pathGroup = strtolower(trim($pathGroup));
        if ($pathGroup !== '') {
            foreach ($this->all() as $kind) {
                $kindGroup = strtolower(trim($kind->getPathGroup()));
                if ($kindGroup !== '' && $kindGroup === $pathGroup) {
                    return $kind;
                }
            }
        }

        return $this->defaultKind;
    }

    /**
     * @return list<string>
     */
    public function allLayoutTypes(): array
    {
        $types = [];
        foreach ($this->all() as $kind) {
            foreach ($kind->getLayoutTypes() as $type) {
                $type = strtolower(trim((string)$type));
                if ($type !== '') {
                    $types[$type] = $type;
                }
            }
        }

        return array_values($types);
    }

    public function canUseLayoutTypeForPathGroup(string $pathGroup, string $layoutType): bool
    {
        $layoutType = strtolower(trim($layoutType));
        if ($layoutType === '') {
            return false;
        }
        $kind = $this->resolveByPathGroup($pathGroup);
        foreach ($kind->getLayoutTypes() as $allowed) {
            if (strtolower(trim((string)$allowed)) === $layoutType) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<CmsPageKindInterface>
     */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $byCode = ['cms' => $this->defaultKind];
        try {
            foreach (ExtendsData::getExtendedBy('Weline_Cms') as $extensions) {
                foreach ($extensions as $extension) {
                    $extendName = trim((string)($extension['extend_name'] ?? ''));
                    if ($extendName !== 'PageKind') {
                        continue;
                    }
                    $class = $this->extensionClass($extension);
                    if ($class === '' || !class_exists($class)) {
                        continue;
                    }
                    $instance = $this->objectManager->getInstance($class);
                    if (!$instance instanceof CmsPageKindInterface) {
                        continue;
                    }
                    $code = strtolower(trim($instance->getCode()));
                    if ($code === '' || $code === 'cms') {
                        continue;
                    }
                    $byCode[$code] = $instance;
                }
            }
        } catch (\Throwable) {
            // Keep default kind only.
        }

        $this->cached = array_values($byCode);

        return $this->cached;
    }

    /**
     * @return list<array{code:string,label:string,path_group:string,public_namespace:string,layout_types:list<string>,default_layout_type:string,default_layout_option:string}>
     */
    public function listDescriptors(): array
    {
        $rows = [];
        foreach ($this->all() as $kind) {
            $rows[] = [
                'code' => $kind->getCode(),
                'label' => $kind->getLabel(),
                'path_group' => $kind->getPathGroup(),
                'public_namespace' => $kind->getPublicNamespace(),
                'layout_types' => $kind->getLayoutTypes(),
                'default_layout_type' => $kind->getDefaultLayoutType(),
                'default_layout_option' => $kind->getDefaultLayoutOption(),
            ];
        }

        return $rows;
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
