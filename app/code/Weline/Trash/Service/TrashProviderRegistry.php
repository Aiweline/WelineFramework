<?php
declare(strict_types=1);

namespace Weline\Trash\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Trash\Api\TrashProviderInterface;

class TrashProviderRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_trash/trashprovider/';

    /** @var array<string,array{code:string,label:string,class:string,module:string,file:string}>|null */
    private ?array $providers = null;

    public function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            throw new \InvalidArgumentException((string)__('回收站 provider code 不能为空。'));
        }

        return $code;
    }

    public function has(string $code): bool
    {
        $code = $this->normalizeCode($code);
        return isset($this->all()[$code]);
    }

    /**
     * @return class-string<TrashProviderInterface>|null
     */
    public function get(string $code): ?string
    {
        $code = $this->normalizeCode($code);
        $definition = $this->all()[$code] ?? null;
        if ($definition === null) {
            return null;
        }

        /** @var class-string<TrashProviderInterface> $class */
        $class = $definition['class'];
        return $class;
    }

    /**
     * @return array{code:string,label:string,class:string,module:string,file:string}|null
     */
    public function getDefinition(string $code): ?array
    {
        $code = $this->normalizeCode($code);
        return $this->all()[$code] ?? null;
    }

    /**
     * @return list<array{code:string,label:string,module:string,class:string}>
     */
    public function listTypes(): array
    {
        $items = [];
        foreach ($this->all() as $definition) {
            $items[] = [
                'code' => $definition['code'],
                'label' => $definition['label'],
                'module' => $definition['module'],
                'class' => $definition['class'],
            ];
        }

        usort($items, static fn(array $a, array $b): int => strcmp($a['code'], $b['code']));
        return $items;
    }

    /**
     * @return array<string,array{code:string,label:string,class:string,module:string,file:string}>
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];
        foreach (ExtendsData::getExtendedBy('Weline_Trash') as $extensions) {
            foreach ($extensions as $extension) {
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }

                $definition = $this->buildDefinition($extension);
                if ($definition === null) {
                    continue;
                }

                $code = $definition['code'];
                if (isset($providers[$code])) {
                    throw new \RuntimeException((string)__(
                        '回收站 provider code 重复：%{1}，冲突类：%{2} / %{3}',
                        [$code, $providers[$code]['class'], $definition['class']]
                    ));
                }
                $providers[$code] = $definition;
            }
        }

        return $this->providers = $providers;
    }

    public function clear(): void
    {
        $this->providers = null;
    }

    /**
     * @param array<string,mixed> $extension
     * @return array{code:string,label:string,class:string,module:string,file:string}|null
     */
    private function buildDefinition(array $extension): ?array
    {
        $sourceFile = (string)($extension['source_file'] ?? '');
        $className = trim((string)($extension['class_name'] ?? ''));
        if ($className === '') {
            $className = $this->resolveClassName($sourceFile);
        }
        if ($className === '') {
            return null;
        }

        if (!class_exists($className, false) && $sourceFile !== '' && is_file($sourceFile)) {
            require_once $sourceFile;
        }
        if (!class_exists($className)) {
            return null;
        }
        if (!is_subclass_of($className, TrashProviderInterface::class)) {
            return null;
        }

        /** @var class-string<TrashProviderInterface> $className */
        $code = $this->normalizeCode($className::code());
        $label = trim((string)$className::label());

        return [
            'code' => $code,
            'label' => $label !== '' ? $label : $code,
            'class' => $className,
            'module' => $this->resolveModuleNameFromSourceFile($sourceFile),
            'file' => $sourceFile,
        ];
    }

    private function resolveClassName(string $sourceFile): string
    {
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return '';
        }

        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return '';
        }

        $namespace = '';
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim((string)$matches[1]);
        }

        $class = '';
        if (preg_match('/(?:final\s+)?class\s+(\w+)/', $content, $matches)) {
            $class = trim((string)$matches[1]);
        }

        return $namespace !== '' && $class !== '' ? $namespace . '\\' . $class : '';
    }

    private function resolveModuleNameFromSourceFile(string $sourceFile): string
    {
        $path = str_replace('\\', '/', $sourceFile);
        if (!preg_match('~/app/code/([^/]+)/([^/]+)/~i', $path, $matches)) {
            return '';
        }

        $vendor = trim((string)($matches[1] ?? ''));
        $module = trim((string)($matches[2] ?? ''));
        return $vendor !== '' && $module !== '' ? $vendor . '_' . $module : '';
    }
}
