<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Manager\ObjectManager;

/**
 * 登录页控制器
 * 
 * 支持3种布局变体：
 * - login_page_1
 * - login_page_2
 * - login_page_3
 * 
 * 布局变体通过主题配置设置：layouts.account_auth = login_page_1
 */
class Login extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     * 注意：account_auth类型需要根据具体页面选择布局选项
     */
    protected ?string $layoutType = 'account_auth';
    
    /**
     * 显示登录页面
     */
    public function index(): string
    {
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        
        // 如果已登录，重定向到账户首页
        if ($customerSession->isLoggedIn()) {
            return $this->redirect('weshop/customer/account/index');
        }
        
        // 获取登录错误信息（如果有）
        $error = $this->request->getParam('error');
        
        // 获取重定向URL（登录成功后跳转）
        $redirectUrl = $this->request->getParam('redirect') ?? $this->request->getParam('redirect_url') ?? '';
        
        // 准备模板数据
        $this->assign('error', $error);
        $this->assign('redirect_url', $redirectUrl);
        $this->assign('register_url', $this->getUrl('weshop/customer/account/register'));
        $this->assign('forgot_password_url', $this->getUrl('weshop/customer/account/forgotPassword'));
        
        // 设置页面标题
        $this->assign('title', __('登录'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/account_auth/login_page_{variant}.phtml
        // 注意：需要在BaseController中处理account_auth类型的特殊布局选项选择
        return $this->fetch();
    }
    
    /**
     * 处理登录提交
     */
    public function postLogin(): string
    {
        $email = trim((string)($this->request->getPost('email') ?? ''));
        $password = (string)($this->request->getPost('password') ?? '');
        $rememberMe = (bool)($this->request->getPost('remember_me') ?? false);
        
        if (empty($email) || empty($password)) {
            $this->getMessageManager()->addError(__('邮箱和密码不能为空'));
            return $this->redirect('weshop/customer/account/login');
        }
        
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        
        try {
            /** @var \WeShop\Customer\Model\Customer $customer */
            $customer = ObjectManager::getInstance(\WeShop\Customer\Model\Customer::class);
            $customer->load($email, \WeShop\Customer\Model\Customer::fields_EMAIL);
            
            if (!$customer->getId()) {
                $this->getMessageManager()->addError(__('邮箱或密码错误'));
                return $this->redirect('weshop/customer/account/login?error=' . urlencode(__('登录失败')));
            }
            
            // 验证密码（Customer模型可能没有password字段，需要检查关联的用户表）
            // TODO: 如果Customer模型没有password字段，需要从关联的User表获取密码
            $storedPassword = $customer->getData('password');
            if (empty($storedPassword)) {
                // 如果Customer模型没有password字段，尝试从user_id关联的用户表获取
                $userId = $customer->getData(\WeShop\Customer\Model\Customer::fields_USER_ID);
                if ($userId) {
                    // TODO: 从User表获取密码并验证
                    // 临时：如果Customer模型没有password字段，跳过密码验证（仅用于开发测试）
                    // 生产环境必须实现密码验证
                } else {
                    $this->getMessageManager()->addError(__('账户信息不完整'));
                    return $this->redirect('weshop/customer/account/login?error=' . urlencode(__('登录失败')));
                }
            } else {
                // 验证密码
                if (!password_verify($password, $storedPassword)) {
                    $this->getMessageManager()->addError(__('邮箱或密码错误'));
                    return $this->redirect('weshop/customer/account/login?error=' . urlencode(__('登录失败')));
                }
            }
            
            // 检查用户状态
            $status = $customer->getData(\WeShop\Customer\Model\Customer::fields_STATUS);
            if ($status !== 'active' && $status !== 'enabled') {
                $this->getMessageManager()->addError(__('账户已被禁用'));
                return $this->redirect('weshop/customer/account/login?error=' . urlencode(__('账户已被禁用')));
            }
            
            // 执行登录
            $customerSession->setCustomer($customer);
            
            // TODO: 实现记住我功能（设置长期cookie）
            if ($rememberMe) {
                // 可以设置长期session或cookie
            }
            
            $this->getMessageManager()->addSuccess(__('登录成功'));
            
            // 获取重定向URL
            $redirectUrl = $this->request->getPost('redirect_url') ?? $this->request->getParam('redirect') ?? '';
            if (empty($redirectUrl)) {
                $redirectUrl = 'weshop/customer/account/index';
            }
            
            return $this->redirect($redirectUrl);
            
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        // 登录失败，返回登录页面
        return $this->redirect('weshop/customer/account/login?error=' . urlencode(__('登录失败')));
    }
}
