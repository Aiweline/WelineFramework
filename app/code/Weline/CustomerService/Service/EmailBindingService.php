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
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * 邮件绑定服务
 * 处理客户邮件绑定和验证
 */
class EmailBindingService
{
    private string $lastErrorMessage = '';

    public function getLastErrorMessage(): string
    {
        return $this->lastErrorMessage;
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
        $this->lastErrorMessage = '';

        $verificationToken = $this->generateVerificationToken($email, $sessionToken);
        $verificationUrl = $this->buildVerificationUrl($verificationToken);
        $subject = (string) __('客服服务 - 邮箱绑定验证');
        $content = $this->buildVerificationEmailContent($email, $verificationUrl);

        $module = $this->resolveSmtpModule();
        if ($module === null) {
            if ($this->shouldUseDevFallback()) {
                return $this->sendVerificationEmailDevFallback($email, $sessionToken, $verificationUrl);
            }

            $this->lastErrorMessage = (string) __(
                '邮件服务尚未配置。请先在后台 SMTP 设置中为 Weline_Smtp 或 Weline_CustomerService 添加发件人。'
            );
            w_log_error('EmailBindingService sendVerificationEmail: SMTP unavailable for CustomerService and Weline_Smtp');

            return false;
        }

        try {
            $result = w_query('smtp', 'send', [
                'module' => $module,
                'to' => ['email' => $email, 'name' => $email],
                'subject' => $subject,
                'content' => $content,
            ]);

            if (is_array($result) && !empty($result['success'])) {
                return true;
            }

            $this->lastErrorMessage = trim((string) ($result['message'] ?? ''));
            if ($this->lastErrorMessage === '') {
                $this->lastErrorMessage = (string) __('Unable to send verification email. Please try again later.');
            }

            w_log_error('EmailBindingService sendVerificationEmail smtp.send failed: ' . $this->lastErrorMessage);

            if ($this->shouldUseDevFallback()) {
                return $this->sendVerificationEmailDevFallback($email, $sessionToken, $verificationUrl);
            }

            return false;
        } catch (\Throwable $e) {
            $this->lastErrorMessage = $e->getMessage();
            w_log_error('EmailBindingService sendVerificationEmail error: ' . $e->getMessage());

            if ($this->shouldUseDevFallback()) {
                return $this->sendVerificationEmailDevFallback($email, $sessionToken, $verificationUrl);
            }

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
        $data = $this->decodeVerificationToken($verificationToken);

        if (!$data || !isset($data['email']) || !isset($data['session_token'])) {
            return null;
        }

        if (isset($data['expire_time']) && $data['expire_time'] < time()) {
            return null;
        }

        return [
            'email' => $data['email'],
            'session_token' => $data['session_token'],
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

                    $this->updateCustomerLanguageFromSession($customerId, $sessionToken);

                    return true;
                }
            }

            $customer = $this->customerAccounts()->findByEmail($email);

            if ($customer !== null) {
                $resolvedCustomerId = $customer->getId();
                /** @var ChatSession $session */
                $session = ObjectManager::getInstance(ChatSession::class);
                $session->where(ChatSession::schema_fields_SESSION_TOKEN, $sessionToken)
                    ->find()
                    ->fetch();

                if ($session->getId()) {
                    $session->setCustomerId($resolvedCustomerId)
                        ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                        ->save();

                    $this->updateCustomerLanguageFromSession($resolvedCustomerId, $sessionToken);

                    return true;
                }
            }

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

    private function resolveSmtpModule(): ?string
    {
        foreach (['Weline_CustomerService', 'Weline_Smtp'] as $module) {
            $check = w_query('smtp', 'isAvailable', ['module' => $module]);
            if (is_array($check) && !empty($check['available'])) {
                return $module;
            }
        }

        return null;
    }

    private function shouldUseDevFallback(): bool
    {
        return defined('DEV') && DEV;
    }

    private function sendVerificationEmailDevFallback(
        string $email,
        string $sessionToken,
        string $verificationUrl
    ): bool {
        $this->bindCustomerToSession($email, $sessionToken, null);
        w_log_info(sprintf(
            'EmailBindingService DEV fallback: bind email saved for session; verification URL: %s',
            $verificationUrl
        ));
        $this->lastErrorMessage = '';

        return true;
    }

    private function updateCustomerLanguageFromSession(int $customerId, string $sessionToken): void
    {
        /** @var CustomerLanguage $sessionLanguage */
        $sessionLanguage = ObjectManager::getInstance(CustomerLanguage::class);
        $sessionLanguage->where(CustomerLanguage::schema_fields_session_id, $sessionToken)
            ->find()
            ->fetch();

        if ($sessionLanguage->getId()) {
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

    private function generateVerificationToken(string $email, string $sessionToken): string
    {
        $data = [
            'email' => $email,
            'session_token' => $sessionToken,
            'expire_time' => time() + (24 * 60 * 60),
        ];

        return base64_encode(json_encode($data));
    }

    private function decodeVerificationToken(string $token): ?array
    {
        try {
            $data = json_decode(base64_decode($token), true);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function buildVerificationUrl(string $token): string
    {
        $path = '/customerservice/frontend/bind/verify?token=' . urlencode($token);

        try {
            /** @var Url $url */
            $url = ObjectManager::getInstance(Url::class);
            return $url->getUrl($path);
        } catch (\Throwable) {
            return $path;
        }
    }

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
