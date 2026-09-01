<?php

declare(strict_types=1);

namespace Weline\I18n\Service\LocalModelTranslation;

use Weline\I18n\Api\Localization\LocalModel;

/**
 * 自动发现所有继承 LocalModel 的模型，并解析可翻译字段与主表映射。
 */
final class LocalModelTranslationCatalog
{
    /** @var list<array{local_model:class-string,parent_model:?class-string,local_id_field:string,parent_id_field:string,fields:list<string>}>|null */
    private ?array $descriptors = null;

    /**
     * @return list<array{local_model:class-string,parent_model:?class-string,local_id_field:string,parent_id_field:string,fields:list<string>}>
     */
    public function descriptors(): array
    {
        if ($this->descriptors !== null) {
            return $this->descriptors;
        }

        $items = [];
        foreach ($this->discoverLocalModelClassNames() as $localModelClass) {
            $descriptor = $this->buildDescriptor($localModelClass);
            if ($descriptor !== null) {
                $items[] = $descriptor;
            }
        }

        $this->descriptors = $items;

        return $this->descriptors;
    }

    /**
     * @return list<class-string<LocalModel>>
     */
    private function discoverLocalModelClassNames(): array
    {
        $basePath = defined('BP') ? (string)constant('BP') : dirname(__DIR__, 6) . DIRECTORY_SEPARATOR;
        $root = $basePath . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Weline';
        if (!is_dir($root)) {
            return [];
        }

        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (!str_contains($path, DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $class = $this->classNameFromPath($path);
            if ($class === null || !class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, LocalModel::class) || $class === LocalModel::class) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            $classes[] = $class;
        }

        sort($classes);

        return array_values(array_unique($classes));
    }

    /**
     * @param class-string<LocalModel> $localModelClass
     * @return array{local_model:class-string,parent_model:?class-string,local_id_field:string,parent_id_field:string,fields:list<string>}|null
     */
    private function buildDescriptor(string $localModelClass): ?array
    {
        $parentModelClass = $this->inferParentModelClass($localModelClass);
        $localIdField = defined($localModelClass . '::schema_fields_ID')
            ? (string)constant($localModelClass . '::schema_fields_ID')
            : 'id';
        $parentIdField = defined($localModelClass . '::fields_ID')
            ? (string)constant($localModelClass . '::fields_ID')
            : ($parentModelClass && defined($parentModelClass . '::schema_fields_ID')
                ? (string)constant($parentModelClass . '::schema_fields_ID')
                : $localIdField);

        $fields = $this->resolveTranslatableFields($localModelClass, $localIdField);
        if ($fields === []) {
            return null;
        }

        return [
            'local_model' => $localModelClass,
            'parent_model' => $parentModelClass,
            'local_id_field' => $localIdField,
            'parent_id_field' => $parentIdField,
            'fields' => $fields,
        ];
    }

    /**
     * @param class-string<LocalModel> $localModelClass
     * @return class-string|null
     */
    private function inferParentModelClass(string $localModelClass): ?string
    {
        if (str_ends_with($localModelClass, '\\LocalDescription')) {
            $candidate = substr($localModelClass, 0, -strlen('\\LocalDescription'));
            return class_exists($candidate) ? $candidate : null;
        }

        if (str_ends_with($localModelClass, 'Local')) {
            $namespace = substr($localModelClass, 0, (int)strrpos($localModelClass, '\\'));
            $shortName = substr($localModelClass, (int)strrpos($localModelClass, '\\') + 1);
            $candidate = $namespace . '\\' . substr($shortName, 0, -strlen('Local'));
            return class_exists($candidate) ? $candidate : null;
        }

        return null;
    }

    /**
     * @param class-string<LocalModel> $localModelClass
     * @return list<string>
     */
    private function resolveTranslatableFields(string $localModelClass, string $localIdField): array
    {
        $skip = [
            'local_code',
            'config',
            $localIdField,
        ];
        if (defined($localModelClass . '::schema_fields_LOCAL_DESCRIPTION_ID')) {
            $skip[] = (string)constant($localModelClass . '::schema_fields_LOCAL_DESCRIPTION_ID');
        }
        if (defined($localModelClass . '::schema_fields_local_code')) {
            $skip[] = (string)constant($localModelClass . '::schema_fields_local_code');
        }

        $fields = [];
        foreach ((new \ReflectionClass($localModelClass))->getConstants() as $name => $value) {
            if (!str_starts_with($name, 'schema_fields_') || !is_string($value) || $value === '') {
                continue;
            }
            if ($name === 'schema_fields_ID') {
                continue;
            }
            if (in_array($value, $skip, true)) {
                continue;
            }
            if (str_ends_with($value, '_at')) {
                continue;
            }
            $fields[] = $value;
        }

        return array_values(array_unique($fields));
    }

    private function classNameFromPath(string $path): ?string
    {
        $marker = DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR;
        $pos = strpos($path, $marker);
        if ($pos === false) {
            return null;
        }

        $relative = substr($path, $pos + strlen($marker));
        $relative = preg_replace('/\.php$/', '', $relative) ?? '';

        return str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    }
}
