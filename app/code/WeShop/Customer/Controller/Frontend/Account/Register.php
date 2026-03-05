<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use WeShop\Frontend\Controller\BaseController;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;

/**
 * 注册页控制器
 * 
 * 支持2种布局变体：
 * - sign_up_page_1
 * - sign_up_page_2
 * 
 * 布局变体通过主题配置设置：layouts.account_auth = sign_up_page_1
 */
class Register extends BaseController
{
    /**
     * 布局类型
     * Theme模块会根据此类型从主题配置中加载对应的布局
     * 注意：account_auth类型需要根据具体页面选择布局选项
     */
    protected ?string $layoutType = 'account_auth';
    
    /**
     * 显示注册页面
     */
    public function index(): string
    {
        /** @var CustomerSession $customerSession */
        $customerSession = ObjectManager::getInstance(CustomerSession::class);
        
        // 如果已登录，重定向到账户首页
        if ($customerSession->isLoggedIn()) {
            return $this->redirect('weshop/customer/account/index');
        }
        
        // 获取注册错误信息（如果有）
        $error = $this->request->getParam('error');
        
        // 准备模板数据
        $this->assign('error', $error);
        $this->assign('login_url', $this->getUrl('weshop/customer/account/login'));
        
        // 设置页面标题
        $this->assign('title', __('注册'));
        
        // Theme模块会自动根据 layoutType 和主题配置加载对应的布局
        // 布局文件路径：app/design/WeShop/default/frontend/layouts/account_auth/sign_up_page_{variant}.phtml
        return $this->fetch();
    }
    
    /**
     * 处理注册提交
     */
    public function postRegister(): string
    {
        $firstName = trim((string)($this->request->getPost('firstname') ?? $this->request->getPost('first_name') ?? ''));
        $lastName = trim((string)($this->request->getPost('lastname') ?? $this->request->getPost('last_name') ?? ''));
        $email = trim((string)($this->request->getPost('email') ?? ''));
        $password = (string)($this->request->getPost('password') ?? '');
        $passwordConfirm = (string)($this->request->getPost('password_confirm') ?? $this->request->getPost('confirm_password') ?? '');
        $agreeTerms = (bool)($this->request->getPost('agree_terms') ?? false);
        
        // 验证必填字段
        if (empty($firstName) || empty($lastName)) {
            $this->getMessageManager()->addError(__('姓名不能为空'));
            return $this->redirect('weshop/customer/account/register');
        }
        
        if (empty($email)) {
            $this->getMessageManager()->addError(__('邮箱不能为空'));
            return $this->redirect('weshop/customer/account/register');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->getMessageManager()->addError(__('邮箱格式不正确'));
            return $this->redirect('weshop/customer/account/register');
        }
        
        if (empty($password)) {
            $this->getMessageManager()->addError(__('密码不能为空'));
            return $this->redirect('weshop/customer/account/register');
        }
        
        // 验证密码长度
        if (strlen($password) < 6) {
            $this->getMessageManager()->addError(__('密码长度至少6位'));
            return $this->redirect('weshop/customer/account/register');
        }
        
        // 验证密码确认
        if ($password !== $passwordConfirm) {
            $this->getMessageManager()->addError(__('两次输入的密码不一致'));
            return $this->redirect('weshop/customer/account/register');
        }
        
        // 验证同意条款
        if (!$agreeTerms) {
            $this->getMessageManager()->addError(__('请同意用户协议和隐私政策'));
            return $this->redirect('weshop/customer/account/register');
        }
        
        try {
            /** @var Customer $customer */
            $customer = ObjectManager::getInstance(Customer::class);
            
            // 检查邮箱是否已存在
            $existingCustomer = $customer->reset()->load(Customer::schema_fields_EMAIL, $email);
            if ($existingCustomer->getId()) {
                $this->getMessageManager()->addError(__('该邮箱已被注册'));
                return $this->redirect('weshop/customer/account/register');
            }
            
            // 创建新用户
            // 注意：Customer模型可能没有password字段，需要先创建User或扩展Customer表结构
            // 这里先保存基本信息和password（如果表结构支持动态字段）
            $customer->clearData()
                ->setData(Customer::schema_fields_FIRST_NAME, $firstName)
                ->setData(Customer::schema_fields_LAST_NAME, $lastName)
                ->setData(Customer::schema_fields_EMAIL, $email)
                ->setData('password', password_hash($password, PASSWORD_DEFAULT)) // TODO: 如果Customer表没有password字段，需要扩展表结构或使用User表
                ->setData(Customer::schema_fields_STATUS, 'active')
                ->setData(Customer::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
                ->save();
            
            if ($customer->getId()) {
                // 自动登录
                /** @var CustomerSession $customerSession */
                $customerSession = ObjectManager::getInstance(CustomerSession::class);
                $customerSession->setCustomer($customer);
                
                $this->getMessageManager()->addSuccess(__('注册成功，欢迎加入！'));
                
                // 重定向到账户首页
                return $this->redirect('weshop/customer/account/index');
            } else {
                $this->getMessageManager()->addError(__('注册失败，请重试'));
            }
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        // 注册失败，返回注册页面
        return $this->redirect('weshop/customer/account/register?error=' . urlencode(__('注册失败')));
    }
}
