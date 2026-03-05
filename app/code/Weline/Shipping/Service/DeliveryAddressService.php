<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\DeliveryAddress;

/**
 * 运送地址服务
 * 
 * @package Weline_Shipping
 */
class DeliveryAddressService
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取运送地址模型实例
     * 
     * @return DeliveryAddress
     */
    private function getModel(): DeliveryAddress
    {
        return $this->objectManager->getInstance(DeliveryAddress::class);
    }

    /**
     * 获取客户地址列表
     * 
     * @param int $customerId 客户ID
     * @param array $filters 过滤条件
     * @return array
     */
    public function getListByCustomer(int $customerId, array $filters = []): array
    {
        $model = $this->getModel()->reset()
            ->where(DeliveryAddress::schema_fields_CUSTOMER_ID, $customerId);
        
        // 应用过滤条件
        if (isset($filters['is_enabled'])) {
            $model->where(DeliveryAddress::schema_fields_IS_ENABLED, $filters['is_enabled']);
        }
        
        if (isset($filters['is_default'])) {
            $model->where(DeliveryAddress::schema_fields_IS_DEFAULT, $filters['is_default']);
        }
        
        if (isset($filters['keyword']) && $filters['keyword']) {
            $keyword = "%{$filters['keyword']}%";
            $model->where(DeliveryAddress::schema_fields_NAME, $keyword, 'LIKE', 'OR')
                  ->where(DeliveryAddress::schema_fields_CONTACT_NAME, $keyword, 'LIKE', 'OR')
                  ->where(DeliveryAddress::schema_fields_CONTACT_PHONE, $keyword, 'LIKE', 'OR')
                  ->where(DeliveryAddress::schema_fields_STREET, $keyword, 'LIKE');
        }
        
        $model->order(DeliveryAddress::schema_fields_IS_DEFAULT, 'DESC')
              ->order(DeliveryAddress::schema_fields_CREATED_AT, 'DESC');
        
        $collection = $model->select()->fetch();
        return $collection->getItems();
    }

    /**
     * 获取所有地址列表（后台管理用）
     * 
     * @param array $filters 过滤条件
     * @return array
     */
    public function getList(array $filters = []): array
    {
        $model = $this->getModel()->reset();
        
        // 应用过滤条件
        if (isset($filters['customer_id'])) {
            $model->where(DeliveryAddress::schema_fields_CUSTOMER_ID, $filters['customer_id']);
        }
        
        if (isset($filters['is_enabled'])) {
            $model->where(DeliveryAddress::schema_fields_IS_ENABLED, $filters['is_enabled']);
        }
        
        if (isset($filters['is_default'])) {
            $model->where(DeliveryAddress::schema_fields_IS_DEFAULT, $filters['is_default']);
        }
        
        if (isset($filters['keyword']) && $filters['keyword']) {
            $keyword = "%{$filters['keyword']}%";
            $model->where(DeliveryAddress::schema_fields_NAME, $keyword, 'LIKE', 'OR')
                  ->where(DeliveryAddress::schema_fields_CONTACT_NAME, $keyword, 'LIKE', 'OR')
                  ->where(DeliveryAddress::schema_fields_CONTACT_PHONE, $keyword, 'LIKE', 'OR')
                  ->where(DeliveryAddress::schema_fields_STREET, $keyword, 'LIKE');
        }
        
        $model->order(DeliveryAddress::schema_fields_CREATED_AT, 'DESC');
        
        $collection = $model->select()->fetch();
        return $collection->getItems();
    }

    /**
     * 根据ID获取地址
     * 
     * @param int $id
     * @return DeliveryAddress|null
     */
    public function getById(int $id): ?DeliveryAddress
    {
        $model = $this->getModel()->reset()->load($id);
        return $model->getId() ? $model : null;
    }

    /**
     * 创建地址
     * 
     * @param int $customerId 客户ID
     * @param array $data
     * @return DeliveryAddress
     * @throws \Exception
     */
    public function create(int $customerId, array $data): DeliveryAddress
    {
        $data[DeliveryAddress::schema_fields_CUSTOMER_ID] = $customerId;
        $this->validate($data);
        
        $model = $this->getModel()->reset();
        $model->setData($data);
        
        // 如果设置为默认，取消该客户的其他默认地址
        if (!empty($data[DeliveryAddress::schema_fields_IS_DEFAULT])) {
            $this->clearDefaultByCustomer($customerId);
        }
        
        $model->save();
        return $model;
    }

    /**
     * 更新地址
     * 
     * @param int $id
     * @param array $data
     * @param int|null $customerId 客户ID（用于权限验证）
     * @return DeliveryAddress
     * @throws \Exception
     */
    public function update(int $id, array $data, ?int $customerId = null): DeliveryAddress
    {
        $model = $this->getById($id);
        if (!$model) {
            throw new \Exception(__('地址不存在'));
        }
        
        // 权限验证：前端只能更新自己的地址
        if ($customerId !== null && $model->getCustomerId() !== $customerId) {
            throw new \Exception(__('无权操作此地址'));
        }
        
        $this->validate($data, $id);
        
        // 如果设置为默认，取消该客户的其他默认地址
        if (!empty($data[DeliveryAddress::schema_fields_IS_DEFAULT])) {
            $this->clearDefaultByCustomer($model->getCustomerId(), $id);
        }
        
        $model->setData($data);
        $model->save();
        return $model;
    }

    /**
     * 删除地址
     * 
     * @param int $id
     * @param int|null $customerId 客户ID（用于权限验证）
     * @return bool
     * @throws \Exception
     */
    public function delete(int $id, ?int $customerId = null): bool
    {
        $model = $this->getById($id);
        if (!$model) {
            throw new \Exception(__('地址不存在'));
        }
        
        // 权限验证：前端只能删除自己的地址
        if ($customerId !== null && $model->getCustomerId() !== $customerId) {
            throw new \Exception(__('无权操作此地址'));
        }
        
        return $model->delete();
    }

    /**
     * 设置默认地址
     * 
     * @param int $id
     * @param int|null $customerId 客户ID（用于权限验证）
     * @return DeliveryAddress
     * @throws \Exception
     */
    public function setDefault(int $id, ?int $customerId = null): DeliveryAddress
    {
        $model = $this->getById($id);
        if (!$model) {
            throw new \Exception(__('地址不存在'));
        }
        
        // 权限验证：前端只能设置自己的地址为默认
        if ($customerId !== null && $model->getCustomerId() !== $customerId) {
            throw new \Exception(__('无权操作此地址'));
        }
        
        // 取消该客户的所有默认地址
        $this->clearDefaultByCustomer($model->getCustomerId(), $id);
        
        // 设置当前为默认
        $model->setData(DeliveryAddress::schema_fields_IS_DEFAULT, 1);
        $model->save();
        
        return $model;
    }

    /**
     * 启用/禁用地址
     * 
     * @param int $id
     * @param bool $enabled
     * @return DeliveryAddress
     * @throws \Exception
     */
    public function setEnabled(int $id, bool $enabled): DeliveryAddress
    {
        $model = $this->getById($id);
        if (!$model) {
            throw new \Exception(__('地址不存在'));
        }
        
        $model->setData(DeliveryAddress::schema_fields_IS_ENABLED, $enabled ? 1 : 0);
        $model->save();
        
        return $model;
    }

    /**
     * 获取客户的默认地址
     * 
     * @param int $customerId
     * @return DeliveryAddress|null
     */
    public function getDefaultByCustomer(int $customerId): ?DeliveryAddress
    {
        $model = $this->getModel()->reset()
            ->where(DeliveryAddress::schema_fields_CUSTOMER_ID, $customerId)
            ->where(DeliveryAddress::schema_fields_IS_DEFAULT, 1)
            ->where(DeliveryAddress::schema_fields_IS_ENABLED, 1)
            ->find()
            ->fetch();
        
        return $model->getId() ? $model : null;
    }

    /**
     * 清除客户的默认地址（排除指定ID）
     * 
     * @param int $customerId
     * @param int|null $excludeId
     * @return void
     */
    private function clearDefaultByCustomer(int $customerId, ?int $excludeId = null): void
    {
        $model = $this->getModel()->reset()
            ->where(DeliveryAddress::schema_fields_CUSTOMER_ID, $customerId)
            ->where(DeliveryAddress::schema_fields_IS_DEFAULT, 1);
        
        if ($excludeId) {
            $model->where(DeliveryAddress::schema_fields_ID, $excludeId, '!=');
        }
        
        $model->update([DeliveryAddress::schema_fields_IS_DEFAULT => 0])->fetch();
    }

    /**
     * 验证地址数据
     * 
     * @param array $data
     * @param int|null $id 更新时的ID
     * @return void
     * @throws \Exception
     */
    private function validate(array $data, ?int $id = null): void
    {
        $required = [
            DeliveryAddress::schema_fields_NAME => __('地址名称'),
            DeliveryAddress::schema_fields_CONTACT_NAME => __('收货人姓名'),
            DeliveryAddress::schema_fields_CONTACT_PHONE => __('联系电话'),
            DeliveryAddress::schema_fields_PROVINCE => __('省份'),
            DeliveryAddress::schema_fields_CITY => __('城市'),
            DeliveryAddress::schema_fields_STREET => __('街道地址'),
        ];
        
        foreach ($required as $field => $label) {
            if (empty($data[$field])) {
                throw new \Exception(__('%{1}不能为空', [$label]));
            }
        }
        
        // 验证电话号码格式
        if (!empty($data[DeliveryAddress::schema_fields_CONTACT_PHONE])) {
            $phone = $data[DeliveryAddress::schema_fields_CONTACT_PHONE];
            if (!preg_match('/^1[3-9]\d{9}$|^0\d{2,3}-?\d{7,8}$/', $phone)) {
                throw new \Exception(__('电话号码格式不正确'));
            }
        }
        
        // 验证邮政编码格式（如果提供）
        if (!empty($data[DeliveryAddress::schema_fields_POSTAL_CODE])) {
            $postalCode = $data[DeliveryAddress::schema_fields_POSTAL_CODE];
            if (!preg_match('/^\d{6}$/', $postalCode)) {
                throw new \Exception(__('邮政编码格式不正确，应为6位数字'));
            }
        }
    }
}

