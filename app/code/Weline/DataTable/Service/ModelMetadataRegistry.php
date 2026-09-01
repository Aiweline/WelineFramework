<?php

declare(strict_types=1);

namespace Weline\DataTable\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Manager\ObjectManager;

/** Normalizes ORM column metadata for table, filter, form and write-plan consumers. */
final class ModelMetadataRegistry
{
    /** @return array<string,mixed> */
    public function metadata(string $modelClass, ?string $alias = null): array
    {
        $model = $this->model($modelClass);
        $primaryKey = method_exists($model, 'getPrimaryKey')
            ? (string)($model->getPrimaryKey() ?: 'id')
            : 'id';

        return [
            'schema_version' => 'datatable.model-metadata.v1',
            'model' => $modelClass,
            'alias' => $alias,
            'table' => method_exists($model, 'getTable') ? (string)$model->getTable() : '',
            'primary_key' => $primaryKey,
            'fields' => $this->fields($modelClass, $alias),
            'relations' => [],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function fields(string $modelClass, ?string $alias = null): array
    {
        $model = $this->model($modelClass);
        $primaryKey = method_exists($model, 'getPrimaryKey')
            ? (string)($model->getPrimaryKey() ?: 'id')
            : 'id';
        $fields = [];

        foreach ($this->columns($model, $modelClass) as $column) {
            if (!is_array($column)) {
                continue;
            }
            $name = (string)($column['Field'] ?? $column['field'] ?? $column['COLUMN_NAME'] ?? '');
            if ($name === '') {
                continue;
            }
            $dbType = (string)($column['Type'] ?? $column['type'] ?? '');
            $label = trim((string)($column['Comment'] ?? $column['comment'] ?? ''));
            $label = $label !== '' ? $label : $this->humanize($name);
            $sensitive = preg_match('/(?:password|passwd|secret|token|api[_-]?key|private[_-]?key|credential)/i', $name) === 1;
            $isPrimary = $name === $primaryKey || strtoupper((string)($column['Key'] ?? '')) === 'PRI';
            $qualifiedName = $alias ? $alias . '.' . $name : $name;
            $nullable = strtoupper((string)($column['Null'] ?? 'YES')) === 'YES';

            $field = [
                'name' => $qualifiedName,
                'label' => $alias ? strtoupper($alias) . ' ' . $label : $label,
                'type' => $this->fieldType($name, $dbType),
                'db_type' => $dbType,
                'sortable' => !$sensitive,
                'searchable' => !$sensitive,
                'visible' => !$sensitive,
                'visible_default' => !$sensitive,
                'editable' => !$isPrimary && !$sensitive,
                'editable_inline' => !$isPrimary && !$sensitive,
                'writable' => !$isPrimary && !$sensitive,
                'sensitive' => $sensitive,
                'is_primary' => $isPrimary,
                'primary_key' => $isPrimary,
                'required' => !$isPrimary && !$nullable && ($column['Default'] ?? null) === null,
                'filter_default' => in_array($name, [$primaryKey, 'name', 'title', 'email', 'status', 'type'], true),
                'readonly' => $isPrimary || $sensitive,
                'source' => 'schema',
                'placeholder' => (string)__('请输入%{1}', [$label]),
                'width' => $this->fieldWidth($name, $dbType),
                'minWidth' => null,
                'maxWidth' => null,
                'resizable' => true,
                'display_orderable' => true,
                'template_defined' => false,
                'field_defined' => false,
                'from_field' => false,
                'options' => $this->fieldOptions($model, $name),
                'default' => $column['Default'] ?? '',
                'nullable' => $nullable,
                'key' => (string)($column['Key'] ?? ''),
                'extra' => (string)($column['Extra'] ?? ''),
            ];
            if ($alias) {
                $field['alias'] = $alias;
                $field['original_field'] = $name;
            }
            $fields[] = $field;
        }

        return $fields;
    }

    /** @return array<string,array<string,mixed>> */
    public function fieldMap(string $modelClass): array
    {
        $map = [];
        foreach ($this->fields($modelClass) as $field) {
            $map[(string)$field['name']] = $field;
        }
        return $map;
    }

    /** @return array<string,mixed> */
    public function sanitizeWritableData(string $modelClass, array $data): array
    {
        $fields = $this->fieldMap($modelClass);
        $result = [];
        $unknown = [];
        foreach ($data as $name => $value) {
            $name = (string)$name;
            if ($name === 'id' || ($fields[$name]['is_primary'] ?? false)) {
                continue;
            }
            if (!isset($fields[$name]) || !($fields[$name]['writable'] ?? false)) {
                $unknown[] = $name;
                continue;
            }
            $result[$name] = $value;
        }
        if ($unknown !== []) {
            throw new Exception(__('DataTable write contains unknown or protected fields: %{1}.', [implode(', ', $unknown)]));
        }

        return $result;
    }

    private function model(string $modelClass): Model
    {
        if (!class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            throw new Exception(__('DataTable model is invalid: %{1}.', [$modelClass]));
        }
        /** @var Model $model */
        $model = ObjectManager::make($modelClass);
        return $model;
    }

    /** @return list<array<string,mixed>> */
    private function columns(Model $model, string $modelClass): array
    {
        try {
            $columns = (array)$model->columns();
            if ($columns !== []) {
                return array_values(array_filter($columns, 'is_array'));
            }
        } catch (\Throwable) {
            // The declarative schema below remains available before setup/upgrade creates the table.
        }

        $columns = [];
        $reflection = new \ReflectionClass($modelClass);
        foreach ($reflection->getReflectionConstants() as $constant) {
            $attributes = $constant->getAttributes(Col::class);
            if ($attributes === []) {
                continue;
            }
            /** @var Col $definition */
            $definition = $attributes[0]->newInstance();
            $name = (string)($definition->name ?: $constant->getValue());
            if ($name === '') {
                continue;
            }
            $type = $definition->type;
            if ($definition->length !== null && $definition->length !== '') {
                $type .= '(' . $definition->length . ')';
            }
            $columns[] = [
                'Field' => $name,
                'Type' => $type,
                'Null' => $definition->nullable ? 'YES' : 'NO',
                'Key' => $definition->primaryKey ? 'PRI' : ($definition->unique ? 'UNI' : ''),
                'Default' => $definition->default,
                'Extra' => $definition->autoIncrement ? 'auto_increment' : '',
                'Comment' => $definition->comment,
            ];
        }

        return $columns;
    }

    private function fieldType(string $name, string $dbType): string
    {
        $name = strtolower($name);
        $dbType = strtolower($dbType);
        return match (true) {
            str_contains($dbType, 'date') && str_contains($dbType, 'time') => 'datetime',
            str_contains($dbType, 'date') => 'date',
            preg_match('/(^is_|status|state|type|gender|featured|payment_status|order_status)/', $name) === 1 => 'select',
            preg_match('/^(decimal|float|double|numeric|int|tinyint|smallint|bigint)/', $dbType) === 1 => 'number',
            str_contains($dbType, 'text') => 'textarea',
            str_contains($name, 'email') => 'email',
            str_contains($name, 'phone') => 'tel',
            str_contains($name, 'password') => 'password',
            preg_match('/(avatar|photo|image)$/', $name) === 1 => 'image',
            preg_match('/(attachment|file|document)$/', $name) === 1 => 'file',
            default => 'text',
        };
    }

    private function fieldWidth(string $name, string $dbType): string
    {
        $name = strtolower($name);
        $dbType = strtolower($dbType);
        return match (true) {
            $name === 'id' || str_ends_with($name, '_id') => '96px',
            str_contains($dbType, 'text') => '240px',
            str_contains($dbType, 'date') || str_contains($dbType, 'time') => '180px',
            preg_match('/^(decimal|float|double|numeric|int|tinyint|smallint|bigint)/', $dbType) === 1 => '140px',
            default => '180px',
        };
    }

    /** @return list<array<string,mixed>> */
    private function fieldOptions(Model $model, string $name): array
    {
        $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name))) . 'Options';
        if (!method_exists($model, $method)) {
            return [];
        }
        $options = $model->$method();
        return is_array($options) ? array_values($options) : [];
    }

    private function humanize(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }
}
