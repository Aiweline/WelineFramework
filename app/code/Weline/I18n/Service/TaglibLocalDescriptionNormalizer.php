<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Api\Localization\LocalModel;

final class TaglibLocalDescriptionNormalizer
{
    /**
     * @param array<int|string, mixed> $descriptions
     * @param list<string> $extraFields
     * @return list<array<string, mixed>>
     */
    public static function prepareRows(
        LocalModel $model,
        array $descriptions,
        string $fallbackId = '',
        array $extraFields = [],
    ): array {
        $allowed = array_fill_keys($model->getModelFields(), true);
        foreach ($extraFields as $field) {
            $field = trim($field);
            if ($field !== '') {
                $allowed[$field] = true;
            }
        }

        $idField = $model::schema_fields_ID;
        $aliasIdField = self::resolveAliasIdField($model);

        $rows = [];
        foreach ($descriptions as $description) {
            if (!is_array($description)) {
                continue;
            }

            if ($aliasIdField !== null && $aliasIdField !== $idField) {
                if (!isset($description[$idField]) && isset($description[$aliasIdField])) {
                    $description[$idField] = $description[$aliasIdField];
                }
                unset($description[$aliasIdField]);
            }

            if ((!isset($description[$idField]) || $description[$idField] === '') && $fallbackId !== '') {
                $description[$idField] = $fallbackId;
            }

            unset($description['local']);

            $row = [];
            foreach ($description as $key => $value) {
                $key = (string)$key;
                if (isset($allowed[$key])) {
                    $row[$key] = $value;
                }
            }

            if (!isset($row['local_code'], $row[$idField])) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private static function resolveAliasIdField(LocalModel $model): ?string
    {
        $class = $model::class;
        if (!defined($class . '::fields_ID')) {
            return null;
        }

        $alias = constant($class . '::fields_ID');
        if (!is_string($alias) || $alias === '') {
            return null;
        }

        return $alias;
    }
}
