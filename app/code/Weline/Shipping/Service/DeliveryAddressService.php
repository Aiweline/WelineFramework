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
        $this->ensureSoleDefaultByCustomer($customerId);

        $model = $this->getModel()->reset()
            ->where(DeliveryAddress::schema_fields_CUSTOMER_ID, $customerId);
        
        // 应用过滤条件
        if (isset($filters['is_enabled'])) {
            $model->where(DeliveryAddress::schema_fields_IS_ENABLED, $filters['is_enabled']);
        }
        
        if (isset($filters['is_default'])) {
            $model->where(DeliveryAddress::schema_fields_IS_DEFAULT, $filters['is_default']);
        }

        if (isset($filters['purpose_checkout'])) {
            $model->where(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, (int)$filters['purpose_checkout']);
        }

        if (isset($filters['purpose_receiving'])) {
            $model->where(DeliveryAddress::schema_fields_PURPOSE_RECEIVING, (int)$filters['purpose_receiving']);
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
        $data = $this->addressFormatter->normalize($data);
        $this->validate($data);

        if ($this->countByCustomer($customerId) === 0) {
            $data[DeliveryAddress::schema_fields_IS_DEFAULT] = 1;
        }

        $data = $this->normalizePurposeFlagsForCreate($data);
        
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
        
        $data = $this->addressFormatter->normalize($data);
        $this->validate($data, $id);
        $data = $this->normalizePurposeFlagsForUpdate($data, $model);
        
        // 如果设置为默认，取消该客户的其他默认地址
        if (!empty($data[DeliveryAddress::schema_fields_IS_DEFAULT])) {
            $this->clearDefaultByCustomer($model->getCustomerId(), $id);
        }
        
        $this->updateAddressRow($model, $data);
        $model->setData(array_merge($model->getData(), $data));
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

        $ownerId = (int)$model->getCustomerId();
        $deleted = (bool) $model->delete()->fetch();
        if ($deleted) {
            $this->ensureSoleDefaultByCustomer($ownerId);
        }

        return $deleted;
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
    public function getDefaultByCustomer(int $customerId, array $filters = []): ?DeliveryAddress
    {
        $model = $this->getModel()->reset()
            ->where(DeliveryAddress::schema_fields_CUSTOMER_ID, $customerId)
            ->where(DeliveryAddress::schema_fields_IS_DEFAULT, 1)
            ->where(DeliveryAddress::schema_fields_IS_ENABLED, 1);

        if (isset($filters['purpose_checkout'])) {
            $model->where(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, (int)$filters['purpose_checkout']);
        }
        if (isset($filters['purpose_receiving'])) {
            $model->where(DeliveryAddress::schema_fields_PURPOSE_RECEIVING, (int)$filters['purpose_receiving']);
        }

        $model = $model->find()->fetch();
        
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

    private function countByCustomer(int $customerId): int
    {
        if ($customerId <= 0) {
            return 0;
        }

        $collection = $this->getModel()->reset()
            ->where(DeliveryAddress::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetch();

        return count($collection->getItems());
    }

    /**
     * 客户仅剩一条地址时强制为默认（修复历史脏数据与删后残留）。
     */
    private function ensureSoleDefaultByCustomer(int $customerId): void
    {
        if ($customerId <= 0) {
            return;
        }

        $items = $this->getModel()->reset()
            ->where(DeliveryAddress::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetch()
            ->getItems();

        if (count($items) !== 1) {
            return;
        }

        /** @var DeliveryAddress $only */
        $only = $items[0];
        if ($only->isDefault()) {
            return;
        }

        $only->setData(DeliveryAddress::schema_fields_IS_DEFAULT, 1);
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
    private function updateAddressRow(DeliveryAddress $model, array $data): void
    {
        $data = $this->preparePersistenceData($data, [
            DeliveryAddress::schema_fields_NAME,
            DeliveryAddress::schema_fields_CONTACT_NAME,
            DeliveryAddress::schema_fields_CONTACT_PHONE,
            DeliveryAddress::schema_fields_COUNTRY,
            DeliveryAddress::schema_fields_COUNTRY_CODE,
            DeliveryAddress::schema_fields_PROVINCE,
            DeliveryAddress::schema_fields_PROVINCE_CODE,
            DeliveryAddress::schema_fields_PROVINCE_REGION_ID,
            DeliveryAddress::schema_fields_CITY,
            DeliveryAddress::schema_fields_CITY_CODE,
            DeliveryAddress::schema_fields_CITY_REGION_ID,
            DeliveryAddress::schema_fields_DISTRICT,
            DeliveryAddress::schema_fields_DISTRICT_CODE,
            DeliveryAddress::schema_fields_DISTRICT_REGION_ID,
            DeliveryAddress::schema_fields_STREET,
            DeliveryAddress::schema_fields_STREET_ID,
            DeliveryAddress::schema_fields_POSTAL_CODE,
            DeliveryAddress::schema_fields_IS_DEFAULT,
            DeliveryAddress::schema_fields_IS_ENABLED,
            DeliveryAddress::schema_fields_PURPOSE_CHECKOUT,
            DeliveryAddress::schema_fields_PURPOSE_RECEIVING,
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

        $sql = 'UPDATE ' . $model->getTable() . ' SET ' . implode(', ', $sets) . ' WHERE "' . DeliveryAddress::schema_fields_ID . '" = :id';
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
            DeliveryAddress::schema_fields_PROVINCE_REGION_ID => true,
            DeliveryAddress::schema_fields_CITY_REGION_ID => true,
            DeliveryAddress::schema_fields_DISTRICT_REGION_ID => true,
            DeliveryAddress::schema_fields_STREET_ID => true,
            DeliveryAddress::schema_fields_IS_DEFAULT => true,
            DeliveryAddress::schema_fields_IS_ENABLED => true,
            DeliveryAddress::schema_fields_PURPOSE_CHECKOUT => true,
            DeliveryAddress::schema_fields_PURPOSE_RECEIVING => true,
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
        if (empty($data[DeliveryAddress::schema_fields_NAME])) {
            throw new \Exception(__('地址名称不能为空'));
        }

        $this->addressValidationService->validate($data);
        $this->embargoService->assertAllowed([
            'country_code' => (string)($data[DeliveryAddress::schema_fields_COUNTRY_CODE]
                ?? $data[DeliveryAddress::schema_fields_COUNTRY]
                ?? ''),
            'province_code' => (string)($data[DeliveryAddress::schema_fields_PROVINCE_CODE]
                ?? $data[DeliveryAddress::schema_fields_PROVINCE]
                ?? ''),
            'province_region_id' => (int)($data[DeliveryAddress::schema_fields_PROVINCE_REGION_ID] ?? 0),
            'city_code' => (string)($data[DeliveryAddress::schema_fields_CITY_CODE]
                ?? $data[DeliveryAddress::schema_fields_CITY]
                ?? ''),
            'city_region_id' => (int)($data[DeliveryAddress::schema_fields_CITY_REGION_ID] ?? 0),
            'district_code' => (string)($data[DeliveryAddress::schema_fields_DISTRICT_CODE]
                ?? $data[DeliveryAddress::schema_fields_DISTRICT]
                ?? ''),
            'district_region_id' => (int)($data[DeliveryAddress::schema_fields_DISTRICT_REGION_ID] ?? 0),
            'street' => (string)($data[DeliveryAddress::schema_fields_STREET] ?? ''),
        ]);
    }

    /**
     * 新建：未传用途时双标；传了则按 purpose_source / also_use_* 落库。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizePurposeFlagsForCreate(array $data): array
    {
        $hasPurposeHint = array_key_exists(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, $data)
            || array_key_exists(DeliveryAddress::schema_fields_PURPOSE_RECEIVING, $data)
            || array_key_exists('also_use_checkout', $data)
            || array_key_exists('also_use_receiving', $data)
            || array_key_exists('purpose_source', $data);
        if (!$hasPurposeHint) {
            $data[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT] = 1;
            $data[DeliveryAddress::schema_fields_PURPOSE_RECEIVING] = 1;

            return $data;
        }

        $resolved = self::resolvePurposeWriteFlags($data, true, null);
        $data[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT] = $resolved[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT];
        $data[DeliveryAddress::schema_fields_PURPOSE_RECEIVING] = $resolved[DeliveryAddress::schema_fields_PURPOSE_RECEIVING];

        return $data;
    }

    /**
     * 更新：只加标不摘标；未传用途相关键则不改用途字段。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizePurposeFlagsForUpdate(array $data, DeliveryAddress $existing): array
    {
        $touchesPurpose = array_key_exists(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, $data)
            || array_key_exists(DeliveryAddress::schema_fields_PURPOSE_RECEIVING, $data)
            || array_key_exists('also_use_checkout', $data)
            || array_key_exists('also_use_receiving', $data)
            || array_key_exists('purpose_source', $data);
        if (!$touchesPurpose) {
            unset(
                $data[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT],
                $data[DeliveryAddress::schema_fields_PURPOSE_RECEIVING],
            );

            return $data;
        }

        $resolved = self::resolvePurposeWriteFlags($data, false, $existing);
        $data[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT] = $resolved[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT];
        $data[DeliveryAddress::schema_fields_PURPOSE_RECEIVING] = $resolved[DeliveryAddress::schema_fields_PURPOSE_RECEIVING];

        return $data;
    }

    /**
     * 演示/清空结账簿：摘掉账户库结账标（绕过「只加标不摘标」写路径）。
     * 双标 → 仅收货；仅结账 → 改为仅收货（保留物理行，便于对比列表）。
     *
     * @return int 更新行数
     */
    public function stripCheckoutPurposeForCustomer(int $customerId): int
    {
        $customerId = max(0, $customerId);
        if ($customerId <= 0) {
            return 0;
        }

        $updated = 0;
        foreach ($this->getListByCustomer($customerId, [
            'is_enabled' => 1,
            'purpose_checkout' => 1,
        ]) as $model) {
            if (!$model instanceof DeliveryAddress) {
                continue;
            }
            $model->setData(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, 0);
            if (!$model->hasPurposeReceiving()) {
                $model->setData(DeliveryAddress::schema_fields_PURPOSE_RECEIVING, 1);
            }
            $model->save();
            $updated++;
        }

        return $updated;
    }

    /**
     * 下单成功：为指定账户地址加上结账标（只加不摘）。
     */
    public function ensureCheckoutPurposeForAddressId(int $addressId, ?int $customerId = null): bool
    {
        $addressId = max(0, $addressId);
        if ($addressId <= 0) {
            return false;
        }
        $model = $this->getModel()->reset()->load($addressId);
        if (!$model instanceof DeliveryAddress || !(int)$model->getId()) {
            return false;
        }
        if ($customerId !== null && $customerId > 0
            && (int)$model->getData(DeliveryAddress::schema_fields_CUSTOMER_ID) !== $customerId) {
            return false;
        }
        if ($model->hasPurposeCheckout()) {
            return false;
        }
        $model->setData(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, 1);
        $model->save();

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{purpose_checkout: int, purpose_receiving: int}
     */
    public static function resolvePurposeWriteFlags(array $data, bool $isCreate, ?DeliveryAddress $existing): array
    {
        $existingCheckout = $existing ? ($existing->hasPurposeCheckout() ? 1 : 0) : 0;
        $existingReceiving = $existing ? ($existing->hasPurposeReceiving() ? 1 : 0) : 0;
        $source = strtolower(trim((string)($data['purpose_source'] ?? '')));

        if ($source === 'checkout') {
            if (array_key_exists('also_use_receiving', $data)) {
                $receiving = self::isTruthy($data['also_use_receiving']);
            } elseif (array_key_exists(DeliveryAddress::schema_fields_PURPOSE_RECEIVING, $data)) {
                $receiving = self::isTruthy($data[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]);
            } else {
                // 显式 purpose_source 且未传勾选：视为未勾选（表单 checkbox 省略）。
                $receiving = false;
            }
            if ($isCreate) {
                return [
                    DeliveryAddress::schema_fields_PURPOSE_CHECKOUT => 1,
                    DeliveryAddress::schema_fields_PURPOSE_RECEIVING => $receiving ? 1 : 0,
                ];
            }

            return [
                DeliveryAddress::schema_fields_PURPOSE_CHECKOUT => 1,
                DeliveryAddress::schema_fields_PURPOSE_RECEIVING => ($receiving || $existingReceiving) ? 1 : 0,
            ];
        }

        if ($source === 'receiving') {
            if (array_key_exists('also_use_checkout', $data)) {
                $alsoCheckout = self::isTruthy($data['also_use_checkout']);
            } elseif (array_key_exists(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, $data)) {
                $alsoCheckout = self::isTruthy($data[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]);
            } else {
                $alsoCheckout = false;
            }
            if ($isCreate) {
                return [
                    DeliveryAddress::schema_fields_PURPOSE_CHECKOUT => $alsoCheckout ? 1 : 0,
                    DeliveryAddress::schema_fields_PURPOSE_RECEIVING => 1,
                ];
            }

            return [
                DeliveryAddress::schema_fields_PURPOSE_CHECKOUT => ($alsoCheckout || $existingCheckout) ? 1 : 0,
                DeliveryAddress::schema_fields_PURPOSE_RECEIVING => 1,
            ];
        }

        $checkout = array_key_exists(DeliveryAddress::schema_fields_PURPOSE_CHECKOUT, $data)
            ? (self::isTruthy($data[DeliveryAddress::schema_fields_PURPOSE_CHECKOUT]) ? 1 : 0)
            : ($isCreate ? 1 : $existingCheckout);
        $receiving = array_key_exists(DeliveryAddress::schema_fields_PURPOSE_RECEIVING, $data)
            ? (self::isTruthy($data[DeliveryAddress::schema_fields_PURPOSE_RECEIVING]) ? 1 : 0)
            : ($isCreate ? 1 : $existingReceiving);

        if (!$isCreate) {
            $checkout = max($checkout, $existingCheckout);
            $receiving = max($receiving, $existingReceiving);
        }

        if ($checkout === 0 && $receiving === 0) {
            $checkout = 1;
            $receiving = 1;
        }

        return [
            DeliveryAddress::schema_fields_PURPOSE_CHECKOUT => $checkout,
            DeliveryAddress::schema_fields_PURPOSE_RECEIVING => $receiving,
        ];
    }

    public static function isTruthy(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === null || $value === '') {
            return false;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));

            return in_array($lower, ['true', 'yes', 'on'], true);
        }

        return (bool)$value;
    }
}

