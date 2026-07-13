<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Service;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\CustomerService\Model\ChatSession;
use Weline\CustomerService\Model\CustomerLanguage;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\System\Text;
use Weline\Smtp\Api\MailSenderInterface;

/**
 * 邮件绑定服务
 * 处理客户邮件绑定和验证
 */
class EmailBindingService
{
    private MailSenderInterface $smtpSender;

    public function __construct(
        MailSenderInterface $smtpSender
    ) {
        $this->smtpSender = $smtpSender;
    }

    /**
     * 发送绑定验证邮件
     * 
     * @param string $email 邮箱地址
     * @param string $sessionToken 会话令牌
     * @return bool
     */
    public function sendVerificationEmail(string $email, string $sessionToken): bool
    {
        // 生成验证令牌
        $verificationToken = $this->generateVerificationToken($email, $sessionToken);

        // 构建验证链接
        $verificationUrl = $this->buildVerificationUrl($verificationToken);

        // 邮件内容
        $subject = __('客服服务 - 邮箱绑定验证');
        $content = $this->buildVerificationEmailContent($email, $verificationUrl);

        try {
            // 发送邮件
            $this->smtpSender->sender(
                ['email' => 'noreply@example.com', 'name' => __('客服系统')],
                ['email' => $email, 'name' => $email],
                $subject,
                $content,
                '',
                '',
                '',
                '',
                '',
                'Weline_CustomerService'
            );

            return true;
        } catch (\Exception $e) {
            w_log_error('EmailBindingService sendVerificationEmail error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 验证绑定令牌
     * 
     * @param string $verificationToken 验证令牌
     * @return array|null 返回 ['email' => string, 'session_token' => string] 或 null
     */
    public function verifyToken(string $verificationToken): ?array
    {
        // 解码令牌（简单实现，实际应该使用更安全的方式）
        $data = $this->decodeVerificationToken($verificationToken);
        
        if (!$data || !isset($data['email']) || !isset($data['session_token'])) {
            return null;
        }

        // 验证令牌是否过期（24小时）
        if (isset($data['expire_time']) && $data['expire_time'] < time()) {
            return null;
        }

        return [
            'email' => $data['email'],
            'session_token' => $data['session_token']
        ];
    }

    /**
     * 绑定客户到会话
     * 
     * @param string $email 邮箱
     * @param string $sessionToken 会话令牌
     * @param int|null $customerId 客户ID（如果已登录）
     * @return bool
     */
    public function bindCustomerToSession(
        string $email,
        string $sessionToken,
        ?int $customerId = null
    ): bool {
        try {
            // 如果提供了客户ID，直接绑定
            if ($customerId) {
                /** @var ChatSession $session */
                $session = ObjectManager::getInstance(ChatSession::class);
                $session->where(ChatSession::schema_fields_SESSION_TOKEN, $sessionToken)
                    ->find()
                    ->fetch();
                
                if ($session->getId()) {
                    $session->setCustomerId($customerId)
                        ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                        ->save();
                    
                    // 更新客户语言配置
                    $this->updateCustomerLanguageFromSession($customerId, $sessionToken);
                    
                    return true;
                }
            }

            // 尝试通过邮箱查找客户
            $customer = $this->customerAccounts()->findByEmail($email);

            if ($customer !== null) {
                $resolvedCustomerId = $customer->getId();
                // 找到客户，绑定到会话
                /** @var ChatSession $session */
                $session = ObjectManager::getInstance(ChatSession::class);
                $session->where(ChatSession::schema_fields_SESSION_TOKEN, $sessionToken)
                    ->find()
                    ->fetch();
                
                if ($session->getId()) {
                    $session->setCustomerId($resolvedCustomerId)
                        ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                        ->save();
                    
                    // 更新客户语言配置
                    $this->updateCustomerLanguageFromSession($resolvedCustomerId, $sessionToken);
                    
                    return true;
                }
            }

            // 如果客户不存在，只更新语言配置中的邮箱
            /** @var CustomerLanguage $language */
            $language = ObjectManager::getInstance(CustomerLanguage::class);
            $language->where(CustomerLanguage::schema_fields_session_id, $sessionToken)
                ->find()
                ->fetch();
            
            if ($language->getId()) {
                $language->setEmail($email)
                    ->setData(CustomerLanguage::schema_fields_updated_at, date('Y-m-d H:i:s'))
                    ->save();
            } else {
                // 创建新的语言配置
                $language->reset()
                    ->setEmail($email)
                    ->setSessionId($sessionToken)
                    ->setTargetLocale('zh_Hans_CN')
                    ->setData(CustomerLanguage::schema_fields_created_at, date('Y-m-d H:i:s'))
                    ->setData(CustomerLanguage::schema_fields_updated_at, date('Y-m-d H:i:s'))
                    ->save();
            }

            return true;
        } catch (\Exception $e) {
            w_log_error('EmailBindingService bindCustomerToSession error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 从会话更新客户语言配置
     * 
     * @param int $customerId 客户ID
     * @param string $sessionToken 会话令牌
     */
    private function updateCustomerLanguageFromSession(int $customerId, string $sessionToken): void
    {
        /** @var CustomerLanguage $sessionLanguage */
        $sessionLanguage = ObjectManager::getInstance(CustomerLanguage::class);
        $sessionLanguage->where(CustomerLanguage::schema_fields_session_id, $sessionToken)
            ->find()
            ->fetch();

        if ($sessionLanguage->getId()) {
            // 查找或创建客户的语言配置
            /** @var CustomerLanguage $customerLanguage */
            $customerLanguage = ObjectManager::getInstance(CustomerLanguage::class);
            $customerLanguage->where(CustomerLanguage::schema_fields_customer_id, $customerId)
                ->find()
                ->fetch();

            $customerLanguage->setCustomerId($customerId)
                ->setTargetLocale($sessionLanguage->getTargetLocale())
                ->setData(CustomerLanguage::schema_fields_updated_at, date('Y-m-d H:i:s'));
            
            if (!$customerLanguage->getId()) {
                $customerLanguage->setData(CustomerLanguage::schema_fields_created_at, date('Y-m-d H:i:s'));
            }
            
            $customerLanguage->save();
        }
    }

    private function customerAccounts(): CustomerAccountFacadeInterface
    {
        $accounts = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(CustomerAccountFacadeInterface::class);
        if (!$accounts instanceof CustomerAccountFacadeInterface) {
            throw new \RuntimeException('Weline_Customer account provider is unavailable.');
        }

        return $accounts;
    }

    /**
     * 生成验证令牌
     * 
     * @param string $email 邮箱
     * @param string $sessionToken 会话令牌
     * @return string
     */
    private function generateVerificationToken(string $email, string $sessionToken): string
    {
        $data = [
            'email' => $email,
            'session_token' => $sessionToken,
            'expire_time' => time() + (24 * 60 * 60) // 24小时过期
        ];

        // 简单编码（实际应该使用更安全的方式，如JWT）
        return base64_encode(json_encode($data));
    }

    /**
     * 解码验证令牌
     * 
     * @param string $token
     * @return array|null
     */
    private function decodeVerificationToken(string $token): ?array
    {
        try {
            $data = json_decode(base64_decode($token), true);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 构建验证URL
     * 
     * @param string $token
     * @return string
     */
    private function buildVerificationUrl(string $token): string
    {
        // 这里需要根据实际路由调整
        return '/customerservice/frontend/bind/verify?token=' . urlencode($token);
    }

    /**
     * 构建验证邮件内容
     * 
     * @param string $email
     * @param string $verificationUrl
     * @return string
     */
    private function buildVerificationEmailContent(string $email, string $verificationUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>邮箱绑定验证</title>
</head>
<body>
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>客服服务 - 邮箱绑定验证</h2>
        <p>您好，</p>
        <p>您正在绑定邮箱 <strong>{$email}</strong> 到客服会话。</p>
        <p>请点击以下链接完成绑定：</p>
        <p style="margin: 20px 0;">
            <a href="{$verificationUrl}" style="display: inline-block; padding: 10px 20px; background-color: #007bff; color: #ffffff; text-decoration: none; border-radius: 5px;">
                验证邮箱
            </a>
        </p>
        <p>如果按钮无法点击，请复制以下链接到浏览器中打开：</p>
        <p style="word-break: break-all; color: #666;">{$verificationUrl}</p>
        <p>此链接将在24小时后失效。</p>
        <p>如果您没有进行此操作，请忽略此邮件。</p>
        <hr>
        <p style="color: #999; font-size: 12px;">此邮件由系统自动发送，请勿回复。</p>
    </div>
</body>
</html>
HTML;
    }
}
