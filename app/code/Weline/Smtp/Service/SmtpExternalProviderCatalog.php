<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

/**
 * 外部 Smtp 服务商主机预设（仅 host/port/tls/文案；不含密钥）。
 * 供企业邮局「外部填入 / 修改凭据 / 外投手动填写 / 导流手动填写」下拉选用。
 */
final class SmtpExternalProviderCatalog
{
    public const ID_CUSTOM = 'custom';

    /**
     * @return list<array{
     *   id: string,
     *   label: string,
     *   host: string,
     *   port: int,
     *   tls: string,
     *   username_hint: string,
     *   note: string,
     *   tier: string
     * }>
     */
    public function listPresets(): array
    {
        return [
            [
                'id' => self::ID_CUSTOM,
                'label' => '自定义（手动填写主机）',
                'host' => '',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => '',
                'note' => '自行填写 SMTP 主机、端口与 TLS。',
                'tier' => 'custom',
            ],
            [
                'id' => 'aliyun_directmail',
                'label' => '阿里云邮件推送 (DirectMail)',
                'host' => 'smtpdm.aliyun.com',
                'port' => 465,
                'tls' => 'smtps',
                'username_hint' => '控制台 SMTP 用户名',
                'note' => '需在阿里云验证发信域名并开 SMTP；按量计费，常有试用额度。',
                'tier' => 'paid',
            ],
            [
                'id' => 'tencent_ses',
                'label' => '腾讯云邮件推送',
                'host' => 'smtp.qcloudmail.com',
                'port' => 465,
                'tls' => 'smtps',
                'username_hint' => '控制台 SMTP 用户名',
                'note' => '需验证发信域名；以控制台给出的主机为准（部分地域主机不同）。',
                'tier' => 'paid',
            ],
            [
                'id' => 'brevo',
                'label' => 'Brevo（原 Sendinblue）',
                'host' => 'smtp-relay.brevo.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => '登录邮箱或 SMTP 登录名',
                'note' => '免费档有日限额；控制台验证域名后取 SMTP 密钥。',
                'tier' => 'free',
            ],
            [
                'id' => 'resend',
                'label' => 'Resend',
                'host' => 'smtp.resend.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => 'resend',
                'note' => '用户名多为 resend；密码为 API Key。需验证域名。',
                'tier' => 'free',
            ],
            [
                'id' => 'sendgrid',
                'label' => 'SendGrid',
                'host' => 'smtp.sendgrid.net',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => 'apikey',
                'note' => '用户名固定 apikey；密码为 API Key。',
                'tier' => 'free',
            ],
            [
                'id' => 'mailgun',
                'label' => 'Mailgun',
                'host' => 'smtp.mailgun.org',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => 'postmaster@你的发信域',
                'note' => '欧盟区可能用 smtp.eu.mailgun.org。',
                'tier' => 'free',
            ],
            [
                'id' => 'mailjet',
                'label' => 'Mailjet',
                'host' => 'in-v3.mailjet.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => 'API Key',
                'note' => '用户名/密码分别为 API Key 与 Secret Key。',
                'tier' => 'free',
            ],
            [
                'id' => 'postmark',
                'label' => 'Postmark',
                'host' => 'smtp.postmarkapp.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => 'Server API Token',
                'note' => '用户名与密码均可填 Server API Token。',
                'tier' => 'paid',
            ],
            [
                'id' => 'sparkpost',
                'label' => 'SparkPost',
                'host' => 'smtp.sparkpostmail.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => 'SMTP_Injection',
                'note' => '密码为 API Key。',
                'tier' => 'paid',
            ],
            [
                'id' => 'smtp2go',
                'label' => 'SMTP2GO',
                'host' => 'mail.smtp2go.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => '控制台 SMTP 用户',
                'note' => '有免费试用额度。',
                'tier' => 'free',
            ],
            [
                'id' => 'elasticemail',
                'label' => 'Elastic Email',
                'host' => 'smtp.elasticemail.com',
                'port' => 2525,
                'tls' => 'starttls',
                'username_hint' => '登录邮箱',
                'note' => '也可用 587；密码为 SMTP 密码。',
                'tier' => 'free',
            ],
            [
                'id' => 'amazon_ses_us_east_1',
                'label' => 'Amazon SES (us-east-1)',
                'host' => 'email-smtp.us-east-1.amazonaws.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => 'SMTP 凭据用户名',
                'note' => '需在 SES 创建 SMTP 凭据并验证域名；其它地域换主机前缀。',
                'tier' => 'paid',
            ],
            [
                'id' => 'amazon_ses_ap_northeast_1',
                'label' => 'Amazon SES (ap-northeast-1 东京)',
                'host' => 'email-smtp.ap-northeast-1.amazonaws.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => 'SMTP 凭据用户名',
                'note' => '亚洲较近的 SES 终端。',
                'tier' => 'paid',
            ],
            [
                'id' => 'amazon_ses_ap_southeast_1',
                'label' => 'Amazon SES (ap-southeast-1 新加坡)',
                'host' => 'email-smtp.ap-southeast-1.amazonaws.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => 'SMTP 凭据用户名',
                'note' => '东南亚 SES 终端。',
                'tier' => 'paid',
            ],
            [
                'id' => 'zoho',
                'label' => 'Zoho Mail',
                'host' => 'smtp.zoho.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => '完整邮箱地址',
                'note' => '欧盟区可能用 smtp.zoho.eu。',
                'tier' => 'paid',
            ],
            [
                'id' => 'gmail',
                'label' => 'Gmail / Google Workspace',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => '完整 Gmail 地址',
                'note' => '需应用专用密码；发件人受 Google 账户约束。',
                'tier' => 'free',
            ],
            [
                'id' => 'outlook',
                'label' => 'Outlook / Microsoft 365',
                'host' => 'smtp.office365.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => '完整邮箱地址',
                'note' => '需允许 SMTP AUTH；发件人受租户约束。',
                'tier' => 'paid',
            ],
            [
                'id' => 'weline_hub',
                'label' => '本平台 Hub（mail.aiweline.com）',
                'host' => 'mail.aiweline.com',
                'port' => 587,
                'tls' => 'starttls',
                'username_hint' => '如 contact@你的域名',
                'note' => '提交到 Hub；Hub 须能出站投递（解封 25 或再配域名 SMTP）。',
                'tier' => 'self',
            ],
            [
                'id' => 'qq_personal',
                'label' => 'QQ 邮箱个人 SMTP（不推荐品牌发信）',
                'host' => 'smtp.qq.com',
                'port' => 465,
                'tls' => 'smtps',
                'username_hint' => 'QQ 邮箱地址',
                'note' => '会改写可见 From 为 QQ 地址，且易限流；仅应急，勿作品牌外投。',
                'tier' => 'warn',
            ],
            [
                'id' => 'netease_163',
                'label' => '网易 163 邮箱',
                'host' => 'smtp.163.com',
                'port' => 465,
                'tls' => 'smtps',
                'username_hint' => '完整 163 地址',
                'note' => '需授权码；发件人受网易账户约束。',
                'tier' => 'free',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPresetsForUi(): array
    {
        return $this->listPresets();
    }

    public function matchIdByHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return self::ID_CUSTOM;
        }
        foreach ($this->listPresets() as $row) {
            if (($row['id'] ?? '') === self::ID_CUSTOM) {
                continue;
            }
            $presetHost = strtolower(trim((string)($row['host'] ?? '')));
            if ($presetHost !== '' && $presetHost === $host) {
                return (string)$row['id'];
            }
        }

        return self::ID_CUSTOM;
    }
}
