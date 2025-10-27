<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Service\RechargeService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;

/**
 * 充值管理控制器
 */
class Recharge extends BackendController
{
    /**
     * 获取充值服务（懒加载）
     */
    private function getRechargeService(): RechargeService
    {
        return ObjectManager::getInstance(RechargeService::class);
    }
    
    /**
     * 充值管理页面
     */
    #[Acl('Weline_Ai::ai_recharge', '充值管理', 'mdi-cash-multiple', '充值管理')]
    public function index()
    {
        try {
            // 获取当前用户ID（暂时硬编码，实际应从session获取）
            $userId = 1; // TODO: 从session获取当前登录用户ID
            
            // 获取用户账户信息
            $accountInfo = $this->getRechargeService()->getUserAccountInfo($userId);
            
            // 获取充值记录
            $page = (int)($this->request->getGet('page') ?? 1);
            $rechargeHistory = $this->getRechargeService()->getRechargeHistory($userId, $page, 20);
            
            $this->assign('accountInfo', $accountInfo);
            $this->assign('rechargeHistory', $rechargeHistory['items']);
            $this->assign('pagination', [
                'total' => $rechargeHistory['total'],
                'page' => $rechargeHistory['page'],
                'limit' => $rechargeHistory['limit'],
            ]);
            
            return $this->fetch();
        } catch (\Exception $e) {
            Message::error(__('获取充值信息失败：%{1}', $e->getMessage()));
            
            $this->assign('accountInfo', [
                'balance' => 0.0,
                'total_recharge' => 0.0,
                'total_consumption' => 0.0,
                'currency' => 'CNY',
            ]);
            $this->assign('rechargeHistory', []);
            $this->assign('pagination', ['total' => 0, 'page' => 1, 'limit' => 20]);
            
            return $this->fetch();
        }
    }
    
    /**
     * 创建充值订单
     */
    #[Acl('Weline_Ai::ai_recharge_create', '创建充值订单', 'mdi-plus-circle', '创建充值订单', 'Weline_Ai::ai_recharge')]
    public function create()
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            // 获取参数
            $userId = 1; // TODO: 从session获取当前登录用户ID
            $amount = (float)$this->request->getPost('amount');
            $paymentMethod = $this->request->getPost('payment_method', 'alipay');
            
            // 验证金额
            if ($amount <= 0) {
                return $this->jsonResponse(false, __('充值金额必须大于0'));
            }
            
            if ($amount < 1) {
                return $this->jsonResponse(false, __('最小充值金额为1元'));
            }
            
            // 创建充值订单
            $recharge = $this->getRechargeService()->createRechargeOrder(
                $userId,
                $amount,
                $paymentMethod
            );
            
            return $this->jsonResponse(true, __('充值订单创建成功'), [
                'recharge_id' => $recharge->getId(),
                'amount' => $amount,
                'payment_method' => $paymentMethod,
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('创建充值订单失败：%{1}', $e->getMessage()));
        }
    }
    
    /**
     * 支付回调（支付宝）
     */
    #[Acl('Weline_Ai::ai_recharge_callback', '支付回调', '', '支付回调', 'Weline_Ai::ai_recharge')]
    public function alipayCallback()
    {
        try {
            // TODO: 实现支付宝回调验证逻辑
            $rechargeId = (int)$this->request->getPost('recharge_id');
            $transactionId = $this->request->getPost('transaction_id');
            
            // 验证签名
            // ...
            
            // 处理支付成功
            $this->getRechargeService()->handlePaymentSuccess($rechargeId, $transactionId);
            
            return 'success';
        } catch (\Exception $e) {
            return 'fail';
        }
    }
    
    /**
     * 支付回调（微信）
     */
    #[Acl('Weline_Ai::ai_recharge_wechat_callback', '微信支付回调', '', '微信支付回调', 'Weline_Ai::ai_recharge')]
    public function wechatCallback()
    {
        try {
            // TODO: 实现微信支付回调验证逻辑
            $rechargeId = (int)$this->request->getPost('recharge_id');
            $transactionId = $this->request->getPost('transaction_id');
            
            // 验证签名
            // ...
            
            // 处理支付成功
            $this->getRechargeService()->handlePaymentSuccess($rechargeId, $transactionId);
            
            return 'success';
        } catch (\Exception $e) {
            return 'fail';
        }
    }
    
    /**
     * 查询充值订单状态
     */
    #[Acl('Weline_Ai::ai_recharge_status', '查询充值状态', '', '查询充值状态', 'Weline_Ai::ai_recharge')]
    public function status()
    {
        try {
            $rechargeId = (int)$this->request->getGet('id');
            
            $recharge = ObjectManager::getInstance(\Weline\Ai\Model\AiUserRecharge::class)
                ->load($rechargeId);
            
            if (!$recharge->getId()) {
                return $this->jsonResponse(false, __('充值记录不存在'));
            }
            
            return $this->jsonResponse(true, __('查询成功'), [
                'id' => $recharge->getId(),
                'status' => $recharge->getData('payment_status'),
                'amount' => $recharge->getData('amount'),
                'payment_time' => $recharge->getData('payment_time'),
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('查询失败：%{1}', $e->getMessage()));
        }
    }
    
    /**
     * JSON响应
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}

