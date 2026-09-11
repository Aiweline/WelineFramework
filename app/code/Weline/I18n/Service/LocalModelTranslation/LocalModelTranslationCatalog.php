<?php

declare(strict_types=1);

namespace Weline\I18n\Service\LocalModelTranslation;

use Weline\Framework\App\Env;
use Weline\I18n\Api\Localization\LocalModel;

/**
 * 自动发现已安装模块中继承 LocalModel 的模型，并解析可翻译字段与主表映射。
 * 候选类仅来自 active modules 的 Model 目录，且文件名约定为 *Local.php / *LocalDescription.php。
 */
final class LocalModelTranslationCatalog
{
    private const DESCRIPTOR_CACHE_RELATIVE = 'var/cache/i18n/local_model_descriptors.json';

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

        $fingerprint = $this->descriptorCacheFingerprint();
        $cached = $this->readDescriptorCache($fingerprint);
        if ($cached !== null) {
            $this->descriptors = $cached;

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
        $this->writeDescriptorCache($fingerprint, $items);

        return $this->descriptors;
    }

    /**
     * @return list<class-string<LocalModel>>
     */
    private function discoverLocalModelClassNames(): array
    {
        $classes = [];
        foreach ($this->candidateLocalModelPhpFiles() as $path) {
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
     * @return list<string>
     */
    private function candidateLocalModelPhpFiles(): array
    {
        $files = [];
        foreach (Env::getInstance()->getActiveModules() as $module) {
            $basePath = is_array($module) ? (string)($module['base_path'] ?? '') : '';
            $basePath = rtrim($basePath, "\\/");
            if ($basePath === '' || !is_dir($basePath)) {
                continue;
            }
            $modelRoot = $basePath . DIRECTORY_SEPARATOR . 'Model';
            if (!is_dir($modelRoot)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($modelRoot, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                if ($this->isTestSourcePath($path)) {
                    continue;
                }
                $basename = $file->getBasename();
                if (
                    !str_ends_with($basename, 'Local.php')
                    && !str_ends_with($basename, 'LocalDescription.php')
                ) {
                    continue;
                }
                $files[] = $path;
            }
        }

        sort($files);

        return array_values(array_unique($files));
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
        $reflection = new \ReflectionClass($localModelClass);
        foreach ($reflection->getReflectionConstants() as $constant) {
            // LocalModel contributes generic constants such as schema_fields_name
            // and lifecycle fields. They are not columns unless the concrete local
            // table declares them explicitly (for example Option only declares value).
            if ($constant->getDeclaringClass()->getName() !== $localModelClass) {
                continue;
            }
            $name = $constant->getName();
            $value = $constant->getValue();
            if (!str_starts_with($name, 'schema_fields_') || !is_string($value) || $value === '') {
                continue;
            }
            if ($name === 'schema_fields_ID') {
                continue;
            }
            if (in_array($value, $skip, true)) {
                continue;
            }
            if (
                str_ends_with($value, '_at')
                || in_array($value, ['create_time', 'update_time', 'created_at', 'updated_at'], true)
            ) {
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

    private function isTestSourcePath(string $path): bool
    {
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        foreach (['test', 'Test', 'UnitTest', 'tests'] as $segment) {
            if (str_contains($normalized, DIRECTORY_SEPARATOR . $segment . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function descriptorCachePath(): string
    {
        $basePath = defined('BP') ? (string)constant('BP') : dirname(__DIR__, 6) . DIRECTORY_SEPARATOR;

        return rtrim($basePath, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DESCRIPTOR_CACHE_RELATIVE);
    }

    private function descriptorCacheFingerprint(): string
    {
        $parts = [];
        foreach (Env::getInstance()->getActiveModules() as $moduleKey => $module) {
            $name = is_array($module) ? (string)($module['name'] ?? $moduleKey) : (string)$moduleKey;
            $parts[] = $name;
        }
        sort($parts);
        foreach ($this->candidateLocalModelPhpFiles() as $path) {
            $mtime = @filemtime($path);
            $parts[] = $path . ':' . ($mtime === false ? '0' : (string)$mtime);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @return list<array{local_model:class-string,parent_model:?class-string,local_id_field:string,parent_id_field:string,fields:list<string>}>|null
     */
    private function readDescriptorCache(string $fingerprint): ?array
    {
        $cachePath = $this->descriptorCachePath();
        if (!is_file($cachePath)) {
            return null;
        }
        $raw = @file_get_contents($cachePath);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['fingerprint'] ?? '') !== $fingerprint) {
            return null;
        }
        $items = $decoded['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        /** @var list<array{local_model:class-string,parent_model:?class-string,local_id_field:string,parent_id_field:string,fields:list<string>}> $items */
        return $items;
    }

    /**
     * @param list<array{local_model:class-string,parent_model:?class-string,local_id_field:string,parent_id_field:string,fields:list<string>}> $items
     */
    private function writeDescriptorCache(string $fingerprint, array $items): void
    {
        $cachePath = $this->descriptorCachePath();
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = json_encode(
            [
                'fingerprint' => $fingerprint,
                'items' => $items,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (!is_string($payload)) {
            return;
        }
        @file_put_contents($cachePath, $payload);
    }
}
