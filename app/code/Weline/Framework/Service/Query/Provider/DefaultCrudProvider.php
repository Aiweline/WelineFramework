<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query\Provider;

use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;

class DefaultCrudProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'crud';
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'crud',
            'name' => __('CRUD 通用查询'),
            'description' => __('基于模型的通用增删改查操作，支持任意 AbstractModel 子类'),
            'module' => 'Weline_Framework',
            'operations' => [
                [
                    'name' => 'create',
                    'description' => __('创建记录'),
                    'params' => [
                        ['name' => 'model', 'type' => 'string', 'required' => true, 'description' => __('模型类名或短名（配合 module 参数）')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块名（使用短 model 名时必填，如 Vendor_Module）')],
                        ['name' => 'data', 'type' => 'array', 'required' => true, 'description' => __('要写入的字段键值对')],
                    ],
                ],
                [
                    'name' => 'read',
                    'description' => __('读取单条记录'),
                    'params' => [
                        ['name' => 'model', 'type' => 'string', 'required' => true, 'description' => __('模型类名或短名')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块名')],
                        ['name' => 'id', 'type' => 'mixed', 'required' => true, 'description' => __('主键值')],
                        ['name' => 'id_field', 'type' => 'string', 'required' => false, 'description' => __('主键字段名，默认 id')],
                    ],
                ],
                [
                    'name' => 'update',
                    'description' => __('更新记录'),
                    'params' => [
                        ['name' => 'model', 'type' => 'string', 'required' => true, 'description' => __('模型类名或短名')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块名')],
                        ['name' => 'id', 'type' => 'mixed', 'required' => true, 'description' => __('主键值')],
                        ['name' => 'id_field', 'type' => 'string', 'required' => false, 'description' => __('主键字段名，默认 id')],
                        ['name' => 'data', 'type' => 'array', 'required' => true, 'description' => __('要更新的字段键值对')],
                    ],
                ],
                [
                    'name' => 'delete',
                    'description' => __('删除记录'),
                    'params' => [
                        ['name' => 'model', 'type' => 'string', 'required' => true, 'description' => __('模型类名或短名')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块名')],
                        ['name' => 'id', 'type' => 'mixed', 'required' => true, 'description' => __('主键值')],
                        ['name' => 'id_field', 'type' => 'string', 'required' => false, 'description' => __('主键字段名，默认 id')],
                    ],
                ],
                [
                    'name' => 'list',
                    'description' => __('列表查询（分页、过滤、排序）'),
                    'params' => [
                        ['name' => 'model', 'type' => 'string', 'required' => true, 'description' => __('模型类名或短名')],
                        ['name' => 'module', 'type' => 'string', 'required' => false, 'description' => __('模块名')],
                        ['name' => 'page', 'type' => 'int', 'required' => false, 'description' => __('页码，默认 1')],
                        ['name' => 'page_size', 'type' => 'int', 'required' => false, 'description' => __('每页条数，默认 20')],
                        ['name' => 'order_field', 'type' => 'string', 'required' => false, 'description' => __('排序字段')],
                        ['name' => 'order_type', 'type' => 'string', 'required' => false, 'description' => __('排序方向，ASC 或 DESC，默认 DESC')],
                        ['name' => 'filters', 'type' => 'array', 'required' => false, 'description' => __('过滤条件数组，每项 {field, operator, value}')],
                    ],
                ],
            ],
        ];
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'create' => $this->create($params),
            'read' => $this->read($params),
            'update' => $this->update($params),
            'delete' => $this->delete($params),
            'list' => $this->list($params),
            default => throw new \InvalidArgumentException((string)__('不支持的默认 CRUD 操作：%{1}', $operation)),
        };
    }

    private function getModel(array $params): Model
    {
        $modelClass = (string)($params['model'] ?? '');
        if ($modelClass === '') {
            throw new \InvalidArgumentException((string)__('参数 model 必填'));
        }
        $module = (string)($params['module'] ?? '');
        if ($module !== '' && !str_contains($modelClass, '\\')) {
            $modelClass = w_resolve_model_class($module, $modelClass);
        }
        if (!\class_exists($modelClass)) {
            throw new \InvalidArgumentException((string)__('model 必须是有效模型类名：%{1}', $modelClass));
        }
        /** @var Model $model */
        $model = ObjectManager::getInstance($modelClass);
        return $model;
    }

    private function create(array $params): array
    {
        $model = $this->getModel($params)->reset();
        $data = (array)($params['data'] ?? []);
        foreach ($data as $key => $value) {
            $model->setData((string)$key, $value);
        }
        $model->save();
        return $model->getData();
    }

    private function read(array $params): mixed
    {
        $model = $this->getModel($params)->reset();
        $idField = (string)($params['id_field'] ?? 'id');
        $id = $params['id'] ?? null;
        if ($id === null || $id === '') {
            throw new \InvalidArgumentException((string)__('参数 id 必填'));
        }
        $model->where($idField, $id)->find()->fetch();
        return $model->getData();
    }

    private function update(array $params): array
    {
        $model = $this->getModel($params)->reset();
        $idField = (string)($params['id_field'] ?? 'id');
        $id = $params['id'] ?? null;
        if ($id === null || $id === '') {
            throw new \InvalidArgumentException((string)__('参数 id 必填'));
        }
        $model->where($idField, $id)->find()->fetch();
        if (!$model->getData($idField)) {
            throw new \RuntimeException((string)__('未找到要更新的数据'));
        }
        $data = (array)($params['data'] ?? []);
        foreach ($data as $key => $value) {
            $model->setData((string)$key, $value);
        }
        $model->save();
        return $model->getData();
    }

    private function delete(array $params): array
    {
        $model = $this->getModel($params)->reset();
        $idField = (string)($params['id_field'] ?? 'id');
        $id = $params['id'] ?? null;
        if ($id === null || $id === '') {
            throw new \InvalidArgumentException((string)__('参数 id 必填'));
        }
        $model->where($idField, $id)->find()->fetch();
        if (!$model->getData($idField)) {
            throw new \RuntimeException((string)__('未找到要删除的数据'));
        }
        $data = $model->getData();
        $model->delete()->fetch();
        return $data;
    }

    private function list(array $params): array
    {
        $model = $this->getModel($params)->reset();
        $page = (int)($params['page'] ?? 1);
        $pageSize = (int)($params['page_size'] ?? 20);
        $orderField = (string)($params['order_field'] ?? '');
        $orderType = (string)($params['order_type'] ?? 'DESC');
        $filters = (array)($params['filters'] ?? []);

        foreach ($filters as $filter) {
            if (!\is_array($filter)) {
                continue;
            }
            $field = (string)($filter['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $op = (string)($filter['operator'] ?? '=');
            $value = $filter['value'] ?? null;
            $model->where($field, $op, $value);
        }

        if ($orderField !== '') {
            $model->order($orderField, $orderType);
        }

        if ($page > 0 && $pageSize > 0) {
            $model->pagination($page, $pageSize);
        }

        return $model->select()->fetchArray();
    }
}
