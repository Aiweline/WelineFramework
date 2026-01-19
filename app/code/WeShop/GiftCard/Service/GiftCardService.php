<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\GiftCard\Model\GiftCard;

/**
 * 礼品卡服务
 */
class GiftCardService
{
    /**
     * 创建礼品卡
     * 
     * @param array $giftCardData 礼品卡数据
     * @return GiftCard
     */
    public function createGiftCard(array $giftCardData): GiftCard
    {
        /** @var GiftCard $giftCard */
        $giftCard = ObjectManager::getInstance(GiftCard::class);
        
        // 生成卡号
        $cardNumber = $this->generateCardNumber();
        
        $giftCard->clearData()
            ->setData('card_number', $cardNumber)
            ->setData('amount', $giftCardData['amount'] ?? 0)
            ->setData('balance', $giftCardData['amount'] ?? 0)
            ->setData('status', 'active')
            ->save();
        
        return $giftCard;
    }
    
    /**
     * 使用礼品卡
     * 
     * @param string $cardNumber 卡号
     * @param float $amount 使用金额
     * @return bool
     */
    public function useGiftCard(string $cardNumber, float $amount): bool
    {
        /** @var GiftCard $giftCard */
        $giftCard = ObjectManager::getInstance(GiftCard::class);
        $giftCard->load($cardNumber, 'card_number');
        
        if (!$giftCard->getId()) {
            throw new \Exception(__('礼品卡不存在'));
        }
        
        $balance = (float)$giftCard->getData('balance');
        if ($balance < $amount) {
            throw new \Exception(__('礼品卡余额不足'));
        }
        
        $giftCard->setData('balance', $balance - $amount)->save();
        
        return true;
    }
    
    /**
     * 生成卡号
     * 
     * @return string
     */
    protected function generateCardNumber(): string
    {
        return 'GC' . strtoupper(uniqid()) . rand(1000, 9999);
    }
}
