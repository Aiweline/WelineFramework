<?php

declare(strict_types=1);

namespace Amayum\Website\Service;

use Amayum\Website\Model\Inquiry;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Security\Token;
use Weline\Websites\Data\WebsiteData;

/**
 * 诊断询盘：服务端校验、反滥用、落库、SMTP 发送与 mailto 回退。
 *
 * 只有 SMTP 传输接受后才返回 sent；其余情况一律返回可重试/回退状态，不伪造成功。
 */
final class InquiryService
{
    public const STACKS = ['Shopify', 'Shoplazza', 'Shopline', 'WooCommerce', '自建', '其他'];
    public const MARKETS = ['北美', '欧洲', '日本', '海湾', '多市场', '未定'];

    public const RATE_LIMIT_PER_HOUR = 5;
    public const FORM_TOKEN_TTL = 7200;
    public const HONEYPOT_FIELD = 'hp_website';

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, spam: bool, errors: array<string, string>, data: array<string, mixed>}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $spam = \trim((string)($input[self::HONEYPOT_FIELD] ?? '')) !== '';

        $name = \trim((string)($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = (string)__('请填写姓名。');
        } elseif (\mb_strlen($name) > 80) {
            $errors['name'] = (string)__('姓名不能超过 80 个字符。');
        }

        $email = \trim((string)($input['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = (string)__('请填写工作邮箱。');
        } elseif (\mb_strlen($email) > 254 || \filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = (string)__('请填写有效的邮箱地址。');
        }

        $company = \trim((string)($input['company'] ?? ''));
        if (\mb_strlen($company) > 120) {
            $errors['company'] = (string)__('公司/站点不能超过 120 个字符。');
        }

        $stack = \trim((string)($input['stack'] ?? ''));
        if ($stack !== '' && !\in_array($stack, self::STACKS, true)) {
            $errors['stack'] = (string)__('请选择有效的当前栈。');
        }

        $market = \trim((string)($input['market'] ?? ''));
        if ($market !== '' && !\in_array($market, self::MARKETS, true)) {
            $errors['market'] = (string)__('请选择有效的目标市场。');
        }

        $volumeBand = \trim((string)($input['volume_band'] ?? ''));
        if (\mb_strlen($volumeBand) > 80) {
            $errors['volume_band'] = (string)__('订单量/GMV 区间不能超过 80 个字符。');
        }

        $timeline = \trim((string)($input['timeline'] ?? ''));
        if (\mb_strlen($timeline) > 80) {
            $errors['timeline'] = (string)__('计划上线时间不能超过 80 个字符。');
        }

        $message = \trim((string)($input['message'] ?? ''));
        $messageLength = \mb_strlen($message);
        if ($messageLength < 8) {
            $errors['message'] = (string)__('请至少写 8 个字符说明当前最大卡点。');
        } elseif ($messageLength > 2000) {
            $errors['message'] = (string)__('当前最大卡点不能超过 2000 个字符。');
        }

        $consent = \filter_var($input['consent'] ?? false, \FILTER_VALIDATE_BOOLEAN);
        if (!$consent) {
            $errors['consent'] = (string)__('请同意 Amayum 使用以上信息回复这次咨询。');
        }

        if ($spam) {
            $errors = ['form' => (string)__('提交失败，请稍后重试。')];
        }

        return [
            'valid' => $errors === [],
            'spam' => $spam,
            'errors' => $errors,
            'data' => [
                'name' => $name,
                'email' => $email,
                'company' => $company,
                'stack' => $stack,
                'market' => $market,
                'volume_band' => $volumeBand,
                'timeline' => $timeline,
                'message' => $message,
                'consent' => $consent ? 1 : 0,
                'locale' => 'zh-CN',
                'source_path' => '/diagnosis',
            ],
        ];
    }

    public function isSameOrigin(Request $request): bool
    {
        $fetchSite = \strtolower(\trim((string)$request->getServer('HTTP_SEC_FETCH_SITE', '')));
        if ($fetchSite === 'cross-site') {
            return false;
        }

        $origin = \trim((string)$request->getServer('HTTP_ORIGIN', ''));
        $referer = \trim((string)$request->getServer('HTTP_REFERER', ''));
        if ($origin === '' && $referer === '') {
            return true;
        }

        $host = \strtolower(\trim((string)$request->getServer('HTTP_HOST', '')));
        if ($host === '') {
            return false;
        }
        $requestHost = \strtolower((string)(\parse_url('http://' . $host, \PHP_URL_HOST) ?: ''));
        if ($requestHost === '') {
            return false;
        }

        $source = $origin !== '' ? $origin : $referer;
        $sourceHost = \strtolower((string)(\parse_url($source, \PHP_URL_HOST) ?: ''));

        return $sourceHost !== '' && \hash_equals($requestHost, $sourceHost);
    }

    public function verifyRequestToken(Request $request): bool
    {
        $token = \trim((string)$request->getPost('contact_token', ''));
        if ($token === '') {
            $token = \trim((string)$request->getPost('contact', ''));
        }
        if ($token === '') {
            return false;
        }

        return $this->verifyFormToken($token) || $this->verifySessionToken($token);
    }

    public function issueFormToken(): string
    {
        $secret = $this->secret();
        if ($secret === '') {
            return '';
        }

        $payload = \bin2hex(\random_bytes(16)) . '.' . \time();
        $signature = $this->base64Url(\hash_hmac('sha256', $payload, $secret, true));

        return $payload . '.' . $signature;
    }

    public function verifyFormToken(string $token): bool
    {
        $secret = $this->secret();
        if ($secret === '' || $token === '') {
            return false;
        }
        $parts = \explode('.', $token);
        if (\count($parts) !== 3) {
            return false;
        }
        [$nonce, $issuedAt, $signature] = $parts;
        if ($nonce === '' || !\ctype_digit($issuedAt)) {
            return false;
        }
        if (\abs(\time() - (int)$issuedAt) > self::FORM_TOKEN_TTL) {
            return false;
        }
        $expected = $this->base64Url(\hash_hmac('sha256', $nonce . '.' . $issuedAt, $secret, true));

        return \hash_equals($expected, $signature);
    }

    public function hashIp(string $ip): string
    {
        $ip = \trim($ip);
        if ($ip === '') {
            return '';
        }
        $secret = $this->secret();

        return \hash('sha256', $secret !== '' ? $ip . '|' . $secret : $ip);
    }

    public function rateLimitExceeded(string $ipHash): bool
    {
        if ($ipHash === '') {
            return false;
        }
        $key = $this->rateLimitKey($ipHash);
        $count = (int)(w_cache('amayum_inquiry')->get($key) ?? 0);

        return $count >= self::RATE_LIMIT_PER_HOUR;
    }

    public function recordAttempt(string $ipHash): void
    {
        if ($ipHash === '') {
            return;
        }
        $cache = w_cache('amayum_inquiry');
        $key = $this->rateLimitKey($ipHash);
        $count = (int)($cache->get($key) ?? 0);
        $cache->set($key, $count + 1, 3600);
    }

    public function smtpAvailable(string $websiteCode = ''): bool
    {
        try {
            $result = w_query('smtp', 'isAvailable', [
                'module' => SiteProfileService::MODULE,
                'website_code' => $websiteCode !== '' ? $websiteCode : $this->currentWebsiteCode(),
            ]);
        } catch (\Throwable) {
            return false;
        }

        return \is_array($result) && ($result['available'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $flags
     * @return array{ok: bool, status: string, mailto: string, message: string, inquiry_id: int}
     */
    public function submit(array $data, Request $request, array $profile, array $flags): array
    {
        $websiteCode = $this->currentWebsiteCode();
        $mailto = $this->mailto($profile, $data);

        try {
            $inquiry = $this->persist($data, $request);
        } catch (\Throwable) {
            return [
                'ok' => false,
                'status' => 'failed',
                'mailto' => $mailto,
                'message' => (string)__('提交暂时无法保存，请使用下方邮件地址联系我们。'),
                'inquiry_id' => 0,
            ];
        }

        $inquiryId = (int)$inquiry->getId();
        $smtpEnabled = (bool)($flags['contact_smtp_enabled'] ?? false) && $this->smtpAvailable($websiteCode);
        if (!$smtpEnabled) {
            return [
                'ok' => false,
                'status' => Inquiry::STATUS_NEW,
                'mailto' => $mailto,
                'message' => (string)__('邮件服务未配置或暂不可用，请使用下方邮件地址联系我们。'),
                'inquiry_id' => $inquiryId,
            ];
        }

        $to = \trim((string)($profile['contact_email'] ?? ''));
        if ($to === '') {
            $this->markStatus($inquiry, Inquiry::STATUS_FAILED);

            return [
                'ok' => false,
                'status' => Inquiry::STATUS_FAILED,
                'mailto' => $mailto,
                'message' => (string)__('收件邮箱未配置，请使用下方邮件地址联系我们。'),
                'inquiry_id' => $inquiryId,
            ];
        }

        try {
            $result = w_query('smtp', 'send', [
                'to' => $to,
                'subject' => $this->subject($data),
                'content' => $this->body($data),
                'module' => SiteProfileService::MODULE,
                'website_code' => $websiteCode,
                'use_template' => false,
            ]);
        } catch (\Throwable) {
            $result = ['success' => false];
        }

        if (\is_array($result) && ($result['success'] ?? false) === true) {
            $inquiry->setData(Inquiry::schema_fields_STATUS, Inquiry::STATUS_SENT)
                ->setData(Inquiry::schema_fields_SENT_AT, \gmdate('Y-m-d H:i:s'))
                ->save();

            return [
                'ok' => true,
                'status' => Inquiry::STATUS_SENT,
                'mailto' => $mailto,
                'message' => (string)__('已发送。我们会在 1 个工作日内回复。'),
                'inquiry_id' => $inquiryId,
            ];
        }

        $this->markStatus($inquiry, Inquiry::STATUS_FAILED);

        return [
            'ok' => false,
            'status' => Inquiry::STATUS_FAILED,
            'mailto' => $mailto,
            'message' => (string)__('发送失败，请使用下方邮件地址联系我们。'),
            'inquiry_id' => $inquiryId,
        ];
    }

    /** @param array<string, mixed> $profile @param array<string, mixed> $data */
    public function mailto(array $profile, array $data = []): string
    {
        $email = \trim((string)($profile['contact_email'] ?? 'hello@amayum.com'));
        $subject = $this->subject($data);
        $body = $this->body($data);

        return 'mailto:' . $email . '?subject=' . \rawurlencode($subject) . '&body=' . \rawurlencode($body);
    }

    /** @param array<string, mixed> $data */
    private function persist(array $data, Request $request): Inquiry
    {
        /** @var Inquiry $inquiry */
        $inquiry = ObjectManager::getInstance(Inquiry::class);
        $inquiry->clear()->setData([
            Inquiry::schema_fields_NAME => (string)($data['name'] ?? ''),
            Inquiry::schema_fields_EMAIL => (string)($data['email'] ?? ''),
            Inquiry::schema_fields_COMPANY => (string)($data['company'] ?? ''),
            Inquiry::schema_fields_STACK => (string)($data['stack'] ?? ''),
            Inquiry::schema_fields_MARKET => (string)($data['market'] ?? ''),
            Inquiry::schema_fields_VOLUME_BAND => (string)($data['volume_band'] ?? ''),
            Inquiry::schema_fields_TIMELINE => (string)($data['timeline'] ?? ''),
            Inquiry::schema_fields_MESSAGE => (string)($data['message'] ?? ''),
            Inquiry::schema_fields_CONSENT => (int)($data['consent'] ?? 0),
            Inquiry::schema_fields_LOCALE => (string)($data['locale'] ?? 'zh-CN'),
            Inquiry::schema_fields_SOURCE_PATH => (string)($data['source_path'] ?? '/diagnosis'),
            Inquiry::schema_fields_IP_HASH => $this->hashIp((string)$request->clientIP()),
            Inquiry::schema_fields_USER_AGENT => \substr((string)$request->getServer('HTTP_USER_AGENT', ''), 0, 255),
            Inquiry::schema_fields_STATUS => Inquiry::STATUS_NEW,
        ])->save();

        return $inquiry;
    }

    private function markStatus(Inquiry $inquiry, string $status): void
    {
        try {
            $inquiry->setData(Inquiry::schema_fields_STATUS, $status)->save();
        } catch (\Throwable) {
        }
    }

    /** @param array<string, mixed> $data */
    private function subject(array $data): string
    {
        $name = \trim((string)($data['name'] ?? ''));
        $company = \trim((string)($data['company'] ?? ''));
        $suffix = $company !== '' ? ' · ' . $company : '';

        return '[Amayum] 诊断申请：' . ($name !== '' ? $name : '未填写姓名') . $suffix;
    }

    /** @param array<string, mixed> $data */
    private function body(array $data): string
    {
        $lines = [
            '姓名：' . (string)($data['name'] ?? ''),
            '工作邮箱：' . (string)($data['email'] ?? ''),
            '公司 / 站点：' . (string)($data['company'] ?? ''),
            '当前栈：' . (string)($data['stack'] ?? ''),
            '目标市场：' . (string)($data['market'] ?? ''),
            '月订单量或 GMV 区间：' . (string)($data['volume_band'] ?? ''),
            '计划上线时间：' . (string)($data['timeline'] ?? ''),
            '',
            '当前最大卡点：',
            (string)($data['message'] ?? ''),
        ];

        return \implode("\n", $lines);
    }

    private function rateLimitKey(string $ipHash): string
    {
        return 'rate:' . $ipHash . ':' . \gmdate('YmdH');
    }

    private function verifySessionToken(string $token): bool
    {
        try {
            $expected = Token::get('contact');
        } catch (\Throwable) {
            return false;
        }

        return \is_string($expected) && $expected !== '' && \hash_equals($expected, $token);
    }

    private function secret(): string
    {
        $secret = (string)(Env::get('security.secrets_key_base64', '') ?: Env::get('security.secret_ref_key', ''));
        $secret = \trim($secret);
        if ($secret !== '') {
            return $secret;
        }

        $envFile = \defined('BP') ? BP . 'app/etc/env.php' : '';
        if ($envFile !== '' && \is_file($envFile)) {
            $env = require $envFile;
            if (\is_array($env)) {
                return \trim((string)($env['security']['secrets_key_base64'] ?? $env['security']['secret_ref_key'] ?? ''));
            }
        }

        return '';
    }

    private function base64Url(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private function currentWebsiteCode(): string
    {
        $code = \trim((string)(WebsiteData::getCode() ?? ''));

        return $code !== '' ? \strtolower($code) : 'amayum';
    }
}
