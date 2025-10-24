<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiUserRecharge;
use Weline\Frontend\Model\FrontendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\ConnectionFactory;

/**
 * 充值服务
 */
class RechargeService
{
    private ConnectionFactory $connectionFactory;
    
    public function __construct(
        ConnectionFactory $connectionFactory
    ) {
        $this->connectionFactory = $connectionFactory;
    }
    
    /**
     * 创建充值订单
     *
     * @param int $userId 用户ID
     * @param float $amount 充值金额
     * @param string $paymentMethod 支付方式
     * @param int|null $promotionId 优惠活动ID
     * @return AiUserRecharge
     */
    public function createRechargeOrder(
        int $userId,
        float $amount,
        string $paymentMethod,
        ?int $promotionId = null
    ): AiUserRecharge {
        /** @var FrontendUser $user */
        $user = ObjectManager::getInstance(FrontendUser::class)->load($userId);
        if (!$user->getId()) {
            throw new \Exception(__('用户不存在'));
        }
        
        // 计算赠送金额
        $bonusAmount = 0.0;
        if ($promotionId) {
            // TODO: 根据优惠活动计算赠送金额
            // $promotion = ...
            // $bonusAmount = $this->calculateBonus($amount, $promotion);
        }
        
        // 创建充值记录
        /** @var AiUserRecharge $recharge */
        $recharge = ObjectManager::getInstance(AiUserRecharge::class);
        $recharge->setData([
            AiUserRecharge::fields_USER_ID => $userId,
            AiUserRecharge::fields_AMOUNT => $amount,
            AiUserRecharge::fields_CURRENCY => $user->getData('currency') ?? 'CNY',
            AiUserRecharge::fields_PAYMENT_METHOD => $paymentMethod,
            AiUserRecharge::fields_PAYMENT_STATUS => AiUserRecharge::STATUS_PENDING,
            AiUserRecharge::fields_BONUS_AMOUNT => $bonusAmount,
            AiUserRecharge::fields_PROMOTION_ID => $promotionId,
            AiUserRecharge::fields_BALANCE_BEFORE => $user->getData('balance') ?? 0.0,
        ]);
        $recharge->save();
        
        return $recharge;
    }
    
    /**
     * 处理支付成功回调
     *
     * @param int $rechargeId 充值记录ID
     * @param string $transactionId 支付交易ID
     * @return bool
     * @throws \Exception
     */
    public function handlePaymentSuccess(int $rechargeId, string $transactionId): bool
    {
        /** @var AiUserRecharge $recharge */
        $recharge = ObjectManager::getInstance(AiUserRecharge::class)->load($rechargeId);
        if (!$recharge->getId()) {
            throw new \Exception(__('充值记录不存在'));
        }
        
        // 检查状态
        if ($recharge->getData(AiUserRecharge::fields_PAYMENT_STATUS) !== AiUserRecharge::STATUS_PENDING) {
            throw new \Exception(__('充值记录状态异常'));
        }
        
        $conn = $this->connectionFactory->getConnection();
        $conn->beginTransaction();
        
        try {
            /** @var FrontendUser $user */
            $user = ObjectManager::getInstance(FrontendUser::class)
                ->lockForUpdate()
                ->load($recharge->getData(AiUserRecharge::fields_USER_ID));
            
            if (!$user->getId()) {
                throw new \Exception(__('用户不存在'));
            }
            
            // 计算充值后余额
            $amount = (float)$recharge->getData(AiUserRecharge::fields_AMOUNT);
            $bonusAmount = (float)$recharge->getData(AiUserRecharge::fields_BONUS_AMOUNT);
            $totalAmount = $amount + $bonusAmount;
            
            $balanceBefore = (float)($user->getData('balance') ?? 0.0);
            $balanceAfter = $balanceBefore + $totalAmount;
            
            // 更新用户余额
            $user->setData('balance', $balanceAfter);
            $user->setData('total_recharge', ((float)($user->getData('total_recharge') ?? 0.0)) + $amount);
            $user->save();
            
            // 更新充值记录
            $recharge->setData([
                AiUserRecharge::fields_PAYMENT_STATUS => AiUserRecharge::STATUS_SUCCESS,
                AiUserRecharge::fields_PAYMENT_TRANSACTION_ID => $transactionId,
                AiUserRecharge::fields_PAYMENT_TIME => date('Y-m-d H:i:s'),
                AiUserRecharge::fields_BALANCE_AFTER => $balanceAfter,
            ]);
            $recharge->save();
            
            $conn->commit();
            return true;
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }
    
    /**
     * 处理支付失败
     *
     * @param int $rechargeId 充值记录ID
     * @param string $errorMessage 错误信息
     * @return bool
     */
    public function handlePaymentFailed(int $rechargeId, string $errorMessage): bool
    {
        /** @var AiUserRecharge $recharge */
        $recharge = ObjectManager::getInstance(AiUserRecharge::class)->load($rechargeId);
        if (!$recharge->getId()) {
            return false;
        }
        
        $recharge->setData([
            AiUserRecharge::fields_PAYMENT_STATUS => AiUserRecharge::STATUS_FAILED,
            AiUserRecharge::fields_REMARK => $errorMessage,
        ]);
        $recharge->save();
        
        return true;
    }
    
    /**
     * 获取充值记录列表
     *
     * @param int $userId 用户ID
     * @param int $page 页码
     * @param int $limit 每页数量
     * @return array
     */
    public function getRechargeHistory(int $userId, int $page = 1, int $limit = 20): array
    {
        /** @var AiUserRecharge $recharge */
        $recharge = ObjectManager::getInstance(AiUserRecharge::class);
        
        $collection = $recharge->where(AiUserRecharge::fields_USER_ID, $userId)
            ->order(AiUserRecharge::fields_CREATED_TIME, 'DESC')
            ->pagination($page, $limit);
        
        return [
            'items' => $collection->getItems(),
            'total' => $collection->getTotal(),
            'page' => $page,
            'limit' => $limit,
        ];
    }
    
    /**
     * 获取用户账户信息
     *
     * @param int $userId 用户ID
     * @return array
     */
    public function getUserAccountInfo(int $userId): array
    {
        /** @var FrontendUser $user */
        $user = ObjectManager::getInstance(FrontendUser::class)->load($userId);
        if (!$user->getId()) {
            throw new \Exception(__('用户不存在'));
        }
        
        return [
            'balance' => (float)($user->getData('balance') ?? 0.0),
            'total_recharge' => (float)($user->getData('total_recharge') ?? 0.0),
            'total_consumption' => (float)($user->getData('total_consumption') ?? 0.0),
            'currency' => $user->getData('currency') ?? 'CNY',
        ];
    }
}

