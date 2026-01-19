<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;

/**
 * 密码重置页控制器
 * 
 * 支持2种布局变体：
 * - forgot_password_page_1
 * - forgot_password_page_2
 * 
 * 布局变体通过主题配置设置：layouts.account_auth = forgot_password_page_1
 */
class ForgotPassword extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     * 注意：account_auth类型需要根据具体页面选择布局选项
     */
    protected ?string $layoutType = 'account_auth';
    
    /**
     * 显示忘记密码页面
     */
    public function index(): string
    {
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        
        // 如果已登录，重定向到账户首页
        if ($customerSession->isLoggedIn()) {
            return $this->redirect('weshop/customer/account/index');
        }
        
        // 获取token（用于重置密码）
        $token = trim((string)($this->request->getParam('token') ?? ''));
        
        // 如果有token，显示重置密码表单
        if (!empty($token)) {
            return $this->showResetForm($token);
        }
        
        // 获取错误信息（如果有）
        $error = $this->request->getParam('error');
        $success = $this->request->getParam('success');
        
        // 准备模板数据
        $this->assign('error', $error);
        $this->assign('success', $success);
        $this->assign('login_url', $this->getUrl('weshop/customer/account/login'));
        $this->assign('register_url', $this->getUrl('weshop/customer/account/register'));
        
        // 设置页面标题
        $this->assign('title', __('忘记密码'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/account_auth/forgot_password_page_{variant}.phtml
        return $this->fetch();
    }
    
    /**
     * 显示重置密码表单
     */
    protected function showResetForm(string $token): string
    {
        // 验证token
        // TODO: 实现token验证逻辑（可以从数据库或缓存中验证）
        
        // 准备模板数据
        $this->assign('token', $token);
        $this->assign('login_url', $this->getUrl('weshop/customer/account/login'));
        
        // 设置页面标题
        $this->assign('title', __('重置密码'));
        
        return $this->fetch('WeShop_Customer::templates/frontend/account/reset_password.phtml');
    }
    
    /**
     * 处理忘记密码提交（发送重置链接）
     */
    public function postForgotPassword(): string
    {
        $email = trim((string)($this->request->getPost('email') ?? ''));
        
        if (empty($email)) {
            $this->getMessageManager()->addError(__('邮箱不能为空'));
            return $this->redirect('weshop/customer/account/forgotPassword');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->getMessageManager()->addError(__('邮箱格式不正确'));
            return $this->redirect('weshop/customer/account/forgotPassword');
        }
        
        try {
            /** @var Customer $customer */
            $customer = ObjectManager::getInstance(Customer::class);
            $customer->load($email, 'email');
            
            if (!$customer->getId()) {
                // 为了安全，不提示用户是否存在
                $this->getMessageManager()->addSuccess(__('如果该邮箱已注册，我们将发送密码重置链接到您的邮箱'));
                return $this->redirect('weshop/customer/account/forgotPassword?success=1');
            }
            
            // 生成重置token
            $token = bin2hex(random_bytes(32));
            
            // TODO: 保存token到数据库或缓存，设置过期时间（如1小时）
            // TODO: 发送密码重置邮件
            
            // 临时：将token存储到session（实际应该通过邮件发送）
            // 使用$_SESSION直接存储（CustomerSession使用$_SESSION）
            $_SESSION['password_reset_token_' . $customer->getId()] = $token;
            $_SESSION['password_reset_token_expire_' . $customer->getId()] = time() + 3600;
            
            $this->getMessageManager()->addSuccess(__('如果该邮箱已注册，我们将发送密码重置链接到您的邮箱'));
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('发送失败，请稍后重试'));
        }
        
        return $this->redirect('weshop/customer/account/forgotPassword?success=1');
    }
    
    /**
     * 处理重置密码提交
     */
    public function postResetPassword(): string
    {
        $token = trim((string)($this->request->getPost('token') ?? ''));
        $password = (string)($this->request->getPost('password') ?? '');
        $passwordConfirm = (string)($this->request->getPost('password_confirm') ?? '');
        
        if (empty($token)) {
            $this->getMessageManager()->addError(__('重置令牌无效'));
            return $this->redirect('weshop/customer/account/forgotPassword');
        }
        
        if (empty($password)) {
            $this->getMessageManager()->addError(__('密码不能为空'));
            return $this->redirect('weshop/customer/account/forgotPassword?token=' . urlencode($token));
        }
        
        if (strlen($password) < 6) {
            $this->getMessageManager()->addError(__('密码长度至少6位'));
            return $this->redirect('weshop/customer/account/forgotPassword?token=' . urlencode($token));
        }
        
        if ($password !== $passwordConfirm) {
            $this->getMessageManager()->addError(__('两次输入的密码不一致'));
            return $this->redirect('weshop/customer/account/forgotPassword?token=' . urlencode($token));
        }
        
        try {
            // TODO: 验证token并获取用户ID
            // 这里使用临时session方式，实际应该从数据库或缓存中验证
            
            // 查找token对应的用户
            $customerId = null;
            
            // 从session中查找token对应的用户ID
            foreach ($_SESSION as $key => $value) {
                if (strpos($key, 'password_reset_token_') === 0 && $value === $token) {
                    // 提取用户ID
                    $customerId = (int)str_replace('password_reset_token_', '', $key);
                    
                    // 检查token是否过期
                    $expireKey = 'password_reset_token_expire_' . $customerId;
                    if (isset($_SESSION[$expireKey]) && $_SESSION[$expireKey] > time()) {
                        break; // 找到有效的token
                    } else {
                        $customerId = null; // token已过期
                        break;
                    }
                }
            }
            
            // 如果找到用户，更新密码
            if ($customerId) {
                /** @var Customer $customer */
                $customer = ObjectManager::getInstance(Customer::class);
                $customer->load($customerId);
                
                if ($customer->getId()) {
                    $customer->setData('password', password_hash($password, PASSWORD_DEFAULT))
                        ->setData('updated_at', date('Y-m-d H:i:s'))
                        ->save();
                    
                    // 清除token
                    unset($_SESSION['password_reset_token_' . $customerId]);
                    unset($_SESSION['password_reset_token_expire_' . $customerId]);
                    
                    $this->getMessageManager()->addSuccess(__('密码重置成功，请使用新密码登录'));
                    return $this->redirect('weshop/customer/account/login');
                }
            }
            
            $this->getMessageManager()->addError(__('重置令牌无效或已过期'));
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        return $this->redirect('weshop/customer/account/forgotPassword?token=' . urlencode($token));
    }
}
