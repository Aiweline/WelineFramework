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
    private AddressFormatter $addressFormatter;
    private AddressValidationService $addressValidationService;
    private EmbargoService $embargoService;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
        $this->addressFormatter = $objectManager->getInstance(AddressFormatter::class);
        $this->addressValidationService = $objectManager->getInstance(AddressValidationService::class);
        $this->embargoService = $objectManager->getInstance(EmbargoService::class);
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
        $this->ensureSoleDefault();

        $model = $this->getModel()->reset();
        
        // 应用过滤条件
        if (isset($filters['is_enabled'])) {
            $model->where(ShippingAddress::schema_fields_IS_ENABLED, $filters['is_enabled']);
        }
        
        if (isset($filters['is_default'])) {
            $model->where(ShippingAddress::schema_fields_IS_DEFAULT, $filters['is_default']);
        }
        
        if (isset($filters['keyword']) && $filters['keyword']) {
            $keyword = "%{$filters['keyword']}%";
            $model->where(ShippingAddress::schema_fields_NAME, $keyword, 'LIKE', 'OR')
                  ->where(ShippingAddress::schema_fields_CONTACT_NAME, $keyword, 'LIKE', 'OR')
                  ->where(ShippingAddress::schema_fields_CONTACT_PHONE, $keyword, 'LIKE', 'OR')
                  ->where(ShippingAddress::schema_fields_STREET, $keyword, 'LIKE');
        }
        
        $model->order(ShippingAddress::schema_fields_IS_DEFAULT, 'DESC')
              ->order(ShippingAddress::schema_fields_CREATED_AT, 'DESC');
        
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
        $data = $this->addressFormatter->normalize($data);
        $this->validate($data);

        if ($this->countAll() === 0) {
            $data[ShippingAddress::schema_fields_IS_DEFAULT] = 1;
        }
        
        $model = $this->getModel()->reset();
        $model->setData($data);
        
        // 如果设置为默认，取消其他默认地址
        if (!empty($data[ShippingAddress::schema_fields_IS_DEFAULT])) {
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
        
        $data = $this->addressFormatter->normalize($data);
        $this->validate($data, $id);
        
        // 如果设置为默认，取消其他默认地址
        if (!empty($data[ShippingAddress::schema_fields_IS_DEFAULT])) {
            $this->clearDefault($id);
        }
        
        $this->updateAddressRow($model, $data);
        $model->setData(array_merge($model->getData(), $data));
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
        
        $model->delete()->fetch();
        $this->ensureSoleDefault();
        return true;
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
        $model->setData(ShippingAddress::schema_fields_IS_DEFAULT, 1);
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
        
        $model->setData(ShippingAddress::schema_fields_IS_ENABLED, $enabled ? 1 : 0);
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
            ->where(ShippingAddress::schema_fields_IS_DEFAULT, 1)
            ->where(ShippingAddress::schema_fields_IS_ENABLED, 1)
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
            ->where(ShippingAddress::schema_fields_IS_DEFAULT, 1);
        
        if ($excludeId) {
            $model->where(ShippingAddress::schema_fields_ID, $excludeId, '!=');
        }
        
        $model->update([ShippingAddress::schema_fields_IS_DEFAULT => 0])->fetch();
    }

    private function countAll(): int
    {
        $collection = $this->getModel()->reset()->select()->fetch();

        return count($collection->getItems());
    }

    /**
     * 仅剩一条发货地址时强制为默认。
     */
    private function ensureSoleDefault(): void
    {
        $items = $this->getModel()->reset()->select()->fetch()->getItems();
        if (count($items) !== 1) {
            return;
        }

        /** @var ShippingAddress $only */
        $only = $items[0];
        if ($only->isDefault()) {
            return;
        }

        $only->setData(ShippingAddress::schema_fields_IS_DEFAULT, 1);
        $only->save();
    }

    /**
     * 验证地址数据
     * 
     * @param array $data
     * @param int|null $id 更新时的ID
     * @return void
     * @throws \Exception
     */
    private function updateAddressRow(ShippingAddress $model, array $data): void
    {
        $data = $this->preparePersistenceData($data, [
            ShippingAddress::schema_fields_NAME,
            ShippingAddress::schema_fields_CONTACT_NAME,
            ShippingAddress::schema_fields_CONTACT_PHONE,
            ShippingAddress::schema_fields_COUNTRY,
            ShippingAddress::schema_fields_COUNTRY_CODE,
            ShippingAddress::schema_fields_PROVINCE,
            ShippingAddress::schema_fields_PROVINCE_CODE,
            ShippingAddress::schema_fields_PROVINCE_REGION_ID,
            ShippingAddress::schema_fields_CITY,
            ShippingAddress::schema_fields_CITY_CODE,
            ShippingAddress::schema_fields_CITY_REGION_ID,
            ShippingAddress::schema_fields_DISTRICT,
            ShippingAddress::schema_fields_DISTRICT_CODE,
            ShippingAddress::schema_fields_DISTRICT_REGION_ID,
            ShippingAddress::schema_fields_STREET,
            ShippingAddress::schema_fields_STREET_ID,
            ShippingAddress::schema_fields_POSTAL_CODE,
            ShippingAddress::schema_fields_IS_DEFAULT,
            ShippingAddress::schema_fields_IS_ENABLED,
        ]);

        if (!$data) {
            return;
        }

        $connector = $model->getConnection()->getConnector();
        $connector->create();
        $sets = [];
        $params = [':id' => (int)$model->getId()];
        foreach ($data as $field => $value) {
            $param = ':v_' . $field;
            $sets[] = '"' . $field . '" = ' . $param;
            $params[$param] = $value;
        }

        $sql = 'UPDATE ' . $model->getTable() . ' SET ' . implode(', ', $sets) . ' WHERE "' . ShippingAddress::schema_fields_ID . '" = :id';
        $stmt = $connector->getLink()->prepare($sql);
        foreach ($params as $param => $value) {
            if ($value === null) {
                $stmt->bindValue($param, null, \PDO::PARAM_NULL);
            } elseif (is_int($value)) {
                $stmt->bindValue($param, $value, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue($param, (string)$value);
            }
        }
        $stmt->execute();
    }

    private function preparePersistenceData(array $data, array $allowedFields): array
    {
        $allowed = array_flip($allowedFields);
        $result = [];
        foreach ($data as $field => $value) {
            if (!isset($allowed[$field])) {
                continue;
            }
            $result[$field] = $this->normalizePersistenceValue((string)$field, $value);
        }

        return $result;
    }

    private function normalizePersistenceValue(string $field, mixed $value): mixed
    {
        $integerFields = [
            ShippingAddress::schema_fields_PROVINCE_REGION_ID => true,
            ShippingAddress::schema_fields_CITY_REGION_ID => true,
            ShippingAddress::schema_fields_DISTRICT_REGION_ID => true,
            ShippingAddress::schema_fields_STREET_ID => true,
            ShippingAddress::schema_fields_IS_DEFAULT => true,
            ShippingAddress::schema_fields_IS_ENABLED => true,
        ];

        if (isset($integerFields[$field])) {
            if ($value === '' || $value === null) {
                return null;
            }
            return (int)$value;
        }

        return $value === null ? null : (string)$value;
    }

    private function validate(array $data, ?int $id = null): void
    {
        if (empty($data[ShippingAddress::schema_fields_NAME])) {
            throw new \Exception(__('地址名称不能为空'));
        }

        $this->addressValidationService->validate($data);
        $this->embargoService->assertAllowed([
            'country_code' => (string)($data[ShippingAddress::schema_fields_COUNTRY_CODE]
                ?? $data[ShippingAddress::schema_fields_COUNTRY]
                ?? ''),
            'province_code' => (string)($data[ShippingAddress::schema_fields_PROVINCE_CODE]
                ?? $data[ShippingAddress::schema_fields_PROVINCE]
                ?? ''),
            'province_region_id' => (int)($data[ShippingAddress::schema_fields_PROVINCE_REGION_ID] ?? 0),
            'city_code' => (string)($data[ShippingAddress::schema_fields_CITY_CODE]
                ?? $data[ShippingAddress::schema_fields_CITY]
                ?? ''),
            'city_region_id' => (int)($data[ShippingAddress::schema_fields_CITY_REGION_ID] ?? 0),
            'district_code' => (string)($data[ShippingAddress::schema_fields_DISTRICT_CODE]
                ?? $data[ShippingAddress::schema_fields_DISTRICT]
                ?? ''),
            'district_region_id' => (int)($data[ShippingAddress::schema_fields_DISTRICT_REGION_ID] ?? 0),
            'street' => (string)($data[ShippingAddress::schema_fields_STREET] ?? ''),
        ]);
    }
}

