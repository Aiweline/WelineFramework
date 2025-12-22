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
use Weline\Shipping\Model\ShippingAddress;

/**
 * 发货地址服务
 * 
 * @package Weline_Shipping
 */
class ShippingAddressService
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取发货地址模型实例
     * 
     * @return ShippingAddress
     */
    private function getModel(): ShippingAddress
    {
        return $this->objectManager->getInstance(ShippingAddress::class);
    }

    /**
     * 获取地址列表
     * 
     * @param array $filters 过滤条件
     * @return array
     */
    public function getList(array $filters = []): array
    {
        $model = $this->getModel()->reset();
        
        // 应用过滤条件
        if (isset($filters['is_enabled'])) {
            $model->where(ShippingAddress::fields_IS_ENABLED, $filters['is_enabled']);
        }
        
        if (isset($filters['is_default'])) {
            $model->where(ShippingAddress::fields_IS_DEFAULT, $filters['is_default']);
        }
        
        if (isset($filters['keyword']) && $filters['keyword']) {
            $keyword = "%{$filters['keyword']}%";
            $model->where(ShippingAddress::fields_NAME, $keyword, 'LIKE', 'OR')
                  ->where(ShippingAddress::fields_CONTACT_NAME, $keyword, 'LIKE', 'OR')
                  ->where(ShippingAddress::fields_CONTACT_PHONE, $keyword, 'LIKE', 'OR')
                  ->where(ShippingAddress::fields_STREET, $keyword, 'LIKE');
        }
        
        $model->order(ShippingAddress::fields_IS_DEFAULT, 'DESC')
              ->order(ShippingAddress::fields_CREATED_AT, 'DESC');
        
        $collection = $model->select()->fetch();
        return $collection->getItems();
    }

    /**
     * 根据ID获取地址
     * 
     * @param int $id
     * @return ShippingAddress|null
     */
    public function getById(int $id): ?ShippingAddress
    {
        $model = $this->getModel()->reset()->load($id);
        return $model->getId() ? $model : null;
    }

    /**
     * 创建地址
     * 
     * @param array $data
     * @return ShippingAddress
     * @throws \Exception
     */
    public function create(array $data): ShippingAddress
    {
        $this->validate($data);
        
        $model = $this->getModel()->reset();
        $model->setData($data);
        
        // 如果设置为默认，取消其他默认地址
        if (!empty($data[ShippingAddress::fields_IS_DEFAULT])) {
            $this->clearDefault();
        }
        
        $model->save();
        return $model;
    }

    /**
     * 更新地址
     * 
     * @param int $id
     * @param array $data
     * @return ShippingAddress
     * @throws \Exception
     */
    public function update(int $id, array $data): ShippingAddress
    {
        $model = $this->getById($id);
        if (!$model) {
            throw new \Exception(__('地址不存在'));
        }
        
        $this->validate($data, $id);
        
        // 如果设置为默认，取消其他默认地址
        if (!empty($data[ShippingAddress::fields_IS_DEFAULT])) {
            $this->clearDefault($id);
        }
        
        $model->setData($data);
        $model->save();
        return $model;
    }

    /**
     * 删除地址
     * 
     * @param int $id
     * @return bool
     * @throws \Exception
     */
    public function delete(int $id): bool
    {
        $model = $this->getById($id);
        if (!$model) {
            throw new \Exception(__('地址不存在'));
        }
        
        return $model->delete();
    }

    /**
     * 设置默认地址
     * 
     * @param int $id
     * @return ShippingAddress
     * @throws \Exception
     */
    public function setDefault(int $id): ShippingAddress
    {
        $model = $this->getById($id);
        if (!$model) {
            throw new \Exception(__('地址不存在'));
        }
        
        // 取消所有默认地址
        $this->clearDefault($id);
        
        // 设置当前为默认
        $model->setData(ShippingAddress::fields_IS_DEFAULT, 1);
        $model->save();
        
        return $model;
    }

    /**
     * 启用/禁用地址
     * 
     * @param int $id
     * @param bool $enabled
     * @return ShippingAddress
     * @throws \Exception
     */
    public function setEnabled(int $id, bool $enabled): ShippingAddress
    {
        $model = $this->getById($id);
        if (!$model) {
            throw new \Exception(__('地址不存在'));
        }
        
        $model->setData(ShippingAddress::fields_IS_ENABLED, $enabled ? 1 : 0);
        $model->save();
        
        return $model;
    }

    /**
     * 获取默认地址
     * 
     * @return ShippingAddress|null
     */
    public function getDefault(): ?ShippingAddress
    {
        $model = $this->getModel()->reset()
            ->where(ShippingAddress::fields_IS_DEFAULT, 1)
            ->where(ShippingAddress::fields_IS_ENABLED, 1)
            ->find()
            ->fetch();
        
        return $model->getId() ? $model : null;
    }

    /**
     * 清除默认地址（排除指定ID）
     * 
     * @param int|null $excludeId
     * @return void
     */
    private function clearDefault(?int $excludeId = null): void
    {
        $model = $this->getModel()->reset()
            ->where(ShippingAddress::fields_IS_DEFAULT, 1);
        
        if ($excludeId) {
            $model->where(ShippingAddress::fields_ID, $excludeId, '!=');
        }
        
        $model->update([ShippingAddress::fields_IS_DEFAULT => 0])->fetch();
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
            ShippingAddress::fields_NAME => __('地址名称'),
            ShippingAddress::fields_CONTACT_NAME => __('联系人姓名'),
            ShippingAddress::fields_CONTACT_PHONE => __('联系电话'),
            ShippingAddress::fields_PROVINCE => __('省份'),
            ShippingAddress::fields_CITY => __('城市'),
            ShippingAddress::fields_STREET => __('街道地址'),
        ];
        
        foreach ($required as $field => $label) {
            if (empty($data[$field])) {
                throw new \Exception(__('%{1}不能为空', [$label]));
            }
        }
        
        // 验证电话号码格式
        if (!empty($data[ShippingAddress::fields_CONTACT_PHONE])) {
            $phone = $data[ShippingAddress::fields_CONTACT_PHONE];
            if (!preg_match('/^1[3-9]\d{9}$|^0\d{2,3}-?\d{7,8}$/', $phone)) {
                throw new \Exception(__('电话号码格式不正确'));
            }
        }
        
        // 验证邮政编码格式（如果提供）
        if (!empty($data[ShippingAddress::fields_POSTAL_CODE])) {
            $postalCode = $data[ShippingAddress::fields_POSTAL_CODE];
            if (!preg_match('/^\d{6}$/', $postalCode)) {
                throw new \Exception(__('邮政编码格式不正确，应为6位数字'));
            }
        }
    }
}

