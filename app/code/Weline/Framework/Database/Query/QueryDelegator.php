<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Query;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Exception\Core;

/**
 * 将 Model 的查询相关 __call 委托给 QueryInterface，精简 AbstractModel 职责
 * @since 1.0.0
 */
final class QueryDelegator
{
    /**
     * 执行对 QueryInterface 方法的委托（仅当 method 属于 QueryInterface 时调用）
     */
    public function delegate(AbstractModel $model, string $method, array $args): mixed
    {
        $query = $method === 'clearQuery' ? $model->getQuery(false) : $model->getQuery();

        if ($method === 'insert') {
            $model->setInsertFlag(true);
        }
        if ($method === 'find') {
            $model->setFindFieldsValue(implode(',', $args));
        }
        if ($method === 'delete') {
            $model->setDeleteFlag(true);
            if ($model->getId()) {
                $model->getQuery()->where($model->getPrimaryKey(), $model->getId())->delete();
            } elseif ($model->getQuery()->wheres) {
                $model->getQuery()->delete();
            } elseif ($model->getUnitPrimaryKeys() !== []) {
                foreach ($model->getUnitPrimaryKeys() as $unit_primary_key) {
                    if (empty($model->getData($unit_primary_key))) {
                        throw new Core(__('删除条件不能为空：确保模型存在要删除的指定主键值，或者存在查询条件!'));
                    }
                    $query->where($unit_primary_key, $model->getData($unit_primary_key));
                }
                $query->delete();
            } else {
                throw new Core(__('删除条件不能为空：确保模型存在要删除的指定主键值，或者存在查询条件!'));
            }
        }
        if ($method === 'total') {
            return $query->$method(...$args);
        }
        if ($method === 'fields') {
            if (!empty($args) && is_array($args[0])) {
                $fieldsArray = $args[0];
                $fieldsStringParts = [];
                foreach ($fieldsArray as $alias => $expression) {
                    if (is_string($alias) && is_string($expression)) {
                        $fieldsStringParts[] = $expression . ' AS ' . $alias;
                    } else {
                        $fieldsStringParts[] = $expression;
                    }
                }
                $query->fields(implode(',', $fieldsStringParts));
                $model->bindModelFields(array_keys($fieldsArray));
            } else {
                $fieldsString = is_array($args[0] ?? '') ? '' : ($args[0] ?? '');
                $fields = !empty($fieldsString) ? explode(',', $fieldsString) : [];
                foreach ($fields as &$field) {
                    if (is_string($field) && str_contains($field, '.')) {
                        $parts = explode('.', $field);
                        $field = trim((string)array_pop($parts));
                    }
                    if (is_string($field) && str_contains($field, 'as')) {
                        $parts = explode('as', $field);
                        $field = trim((string)array_pop($parts), ' ');
                    }
                }
                $model->bindModelFields($fields);
            }
        }

        $is_fetch = false;
        if ($method === 'fetch') {
            if ($model->getIsDelete()) {
                $model->delete_before();
                $eventData = new DataObject(['model' => $model]);
                $model->getEventManager()->dispatch($model->getProcessTableName() . '_model_delete_before', $eventData);
            }
            if (!trim($model->getQuery()->getPrepareSql(false))) {
                return $model;
            }
            $args[] = $model::class;
            $is_fetch = true;
        }

        $query_data = $query->$method(...$args);
        if ($query_data instanceof QueryInterface) {
            $model->setQuery($query_data);
        }
        $model->setQueryData($query_data);

        if ($method === 'fetchArray') {
            return $query_data;
        }
        if ($is_fetch) {
            if ($model->getIsDelete()) {
                $model->clearData();
                $eventData = new DataObject(['model' => $model]);
                $model->getEventManager()->dispatch($model->getProcessTableName() . '_model_delete_after', $eventData);
                $model->delete_after();
            }
            $model->fetch_before();
            if (is_object($query_data)) {
                $model->setFetchData($query_data->getData());
                $model->setObjectData($query_data->getData());
            } elseif (is_array($query_data)) {
                $model->setFetchData($query_data);
                $model->setObjectData($query_data);
            } elseif ($model->getIsInsert() && (is_numeric($query_data) || is_string($query_data))) {
                $model->setId($query_data);
            }
            $model->fetch_after();
            $model->clearQuery();
            $model->setDeleteFlag(false);
            if ($model->getFindFieldsValue() !== '') {
                $find_fields = explode(',', $model->getFindFieldsValue());
                $model->setFindFieldsValue('');
                $model->clearData();
                foreach ($find_fields as $find_field) {
                    $model->setData($find_field, $query_data[$find_field] ?? null);
                }
                return $query_data;
            }
            return $model;
        }
        if (in_array($method, ['getPrepareSql', 'getSql'], true)) {
            return $query_data;
        }
        return $model;
    }
}
