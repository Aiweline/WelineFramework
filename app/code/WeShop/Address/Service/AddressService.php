<?php

declare(strict_types=1);

namespace WeShop\Address\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Address\Model\Address;

/**
 * 地址服务
 */
class AddressService
{
    /**
     * 获取地址
     * 
     * @param int $addressId 地址ID
     * @return Address|null
     */
    public function getAddress(int $addressId): ?Address
    {
        /** @var Address $address */
        $address = ObjectManager::getInstance(Address::class);
        $address->load($addressId);
        
        if ($address->getId()) {
            return $address;
        }
        
        return null;
    }
    
    /**
     * 获取客户地址列表
     * 
     * @param int $customerId 客户ID
     * @return array
     */
    public function getCustomerAddresses(int $customerId): array
    {
        /** @var Address $address */
        $address = ObjectManager::getInstance(Address::class);
        
        return $address->clear()
            ->where('customer_id', $customerId)
            ->order('is_default', 'DESC')
            ->order('created_at', 'DESC')
            ->select()
            ->fetchArray();
    }
    
    /**
     * 保存地址
     * 
     * @param array $addressData 地址数据
     * @return Address
     */
    public function saveAddress(array $addressData): Address
    {
        /** @var Address $address */
        $address = ObjectManager::getInstance(Address::class);
        
        if (!empty($addressData['address_id'])) {
            $address->load($addressData['address_id']);
        }
        
        // 如果设置为默认地址，取消其他默认地址
        if (!empty($addressData['is_default']) && $addressData['is_default'] == 1) {
            $this->unsetDefaultAddress($addressData['customer_id'] ?? 0);
        }
        
        // 设置数据
        foreach ($addressData as $key => $value) {
            if ($key !== 'address_id') {
                $address->setData($key, $value);
            }
        }
        
        if (!$address->getData('created_at')) {
            $address->setData('created_at', date('Y-m-d H:i:s'));
        }
        $address->setData('updated_at', date('Y-m-d H:i:s'));
        
        $address->save();
        
        return $address;
    }
    
    /**
     * 删除地址
     * 
     * @param int $addressId 地址ID
     * @param int $customerId 客户ID（用于验证）
     * @return bool
     */
    public function deleteAddress(int $addressId, int $customerId): bool
    {
        /** @var Address $address */
        $address = ObjectManager::getInstance(Address::class);
        $address->load($addressId);
        
        if (!$address->getId()) {
            return false;
        }
        
        // 验证客户ID
        if ((int)$address->getData('customer_id') !== $customerId) {
            throw new \Exception(__('无权删除此地址'));
        }
        
        return $address->delete();
    }
    
    /**
     * 设置默认地址
     * 
     * @param int $addressId 地址ID
     * @param int $customerId 客户ID
     * @return bool
     */
    public function setDefaultAddress(int $addressId, int $customerId): bool
    {
        // 取消其他默认地址
        $this->unsetDefaultAddress($customerId);
        
        /** @var Address $address */
        $address = ObjectManager::getInstance(Address::class);
        $address->load($addressId);
        
        if (!$address->getId()) {
            throw new \Exception(__('地址不存在'));
        }
        
        // 验证客户ID
        if ((int)$address->getData('customer_id') !== $customerId) {
            throw new \Exception(__('无权设置此地址为默认'));
        }
        
        $address->setData('is_default', 1)
            ->setData('updated_at', date('Y-m-d H:i:s'))
            ->save();
        
        return true;
    }
    
    /**
     * 取消客户的所有默认地址
     * 
     * @param int $customerId 客户ID
     * @return void
     */
    protected function unsetDefaultAddress(int $customerId): void
    {
        /** @var Address $address */
        $address = ObjectManager::getInstance(Address::class);
        
        $addresses = $address->clear()
            ->where('customer_id', $customerId)
            ->where('is_default', 1)
            ->select()
            ->fetchArray();
        
        foreach ($addresses as $addr) {
            $address->load($addr['address_id']);
            $address->setData('is_default', 0)->save();
        }
    }
    
    /**
     * 获取默认地址
     * 
     * @param int $customerId 客户ID
     * @return Address|null
     */
    public function getDefaultAddress(int $customerId): ?Address
    {
        /** @var Address $address */
        $address = ObjectManager::getInstance(Address::class);
        
        $address->clear()
            ->where('customer_id', $customerId)
            ->where('is_default', 1)
            ->find()
            ->fetch();
        
        if ($address->getId()) {
            return $address;
        }
        
        return null;
    }
}
