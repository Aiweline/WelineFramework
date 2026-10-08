<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

/**
 * 本机生成/复用 DKIM RSA 密钥：公钥给 DNS/Cloudflare，私钥落盘供本机邮局签名（不落库明文）。
 */
final class MailDkimLocalEnsureService
{
    private const PRIVATE_DIR = 'var/security/mail-dkim';

    /**
     * @return array{selector:string,public_key:string,private_key_path:string,created:bool}
     */
    public function ensure(string $domain, string $selector = 'default', string $existingPublicKey = ''): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $selector = strtolower(trim($selector));
        if ($selector === '') {
            $selector = 'default';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $selector)) {
            throw new \DomainException((string)__('DKIM 选择器格式无效。'));
        }
        if ($domain === '' || !filter_var('postmaster@' . $domain, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException((string)__('邮箱域名格式无效。'));
        }

        $path = $this->privateKeyPath($domain, $selector);
        $existingPublicKey = trim($existingPublicKey);
        if ($existingPublicKey !== '' && is_readable($path)) {
            return [
                'selector' => $selector,
                'public_key' => $this->publicBodyFromDnsOrPem($existingPublicKey),
                'private_key_path' => $path,
                'created' => false,
            ];
        }
        if (is_readable($path)) {
            $public = $this->publicBodyFromPrivatePem((string)file_get_contents($path));

            return [
                'selector' => $selector,
                'public_key' => $public,
                'private_key_path' => $path,
                'created' => false,
            ];
        }

        $generated = $this->generateRsaPair();
        $this->writePrivateKey($path, $generated['private_pem']);

        return [
            'selector' => $selector,
            'public_key' => $generated['public_body'],
            'private_key_path' => $path,
            'created' => true,
        ];
    }

    private function privateKeyPath(string $domain, string $selector): string
    {
        $dir = rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . self::PRIVATE_DIR;
        $safe = preg_replace('/[^a-z0-9._-]+/', '_', $domain . '__' . $selector) ?? 'dkim';

        return $dir . DIRECTORY_SEPARATOR . $safe . '.pem';
    }

    /**
     * @return array{private_pem:string,public_body:string}
     */
    private function generateRsaPair(): array
    {
        if (!function_exists('openssl_pkey_new')) {
            throw new \RuntimeException((string)__('本机 OpenSSL 不可用，无法自动生成 DKIM。'));
        }
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new \RuntimeException((string)__('DKIM RSA 密钥生成失败。'));
        }
        $privatePem = '';
        if (!openssl_pkey_export($key, $privatePem) || trim($privatePem) === '') {
            throw new \RuntimeException((string)__('DKIM 私钥导出失败。'));
        }
        $publicBody = $this->publicBodyFromPrivatePem($privatePem);

        return [
            'private_pem' => $privatePem,
            'public_body' => $publicBody,
        ];
    }

    private function writePrivateKey(string $path, string $pem): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException((string)__('无法创建 DKIM 私钥目录。'));
        }
        if (file_put_contents($path, $pem, LOCK_EX) === false) {
            throw new \RuntimeException((string)__('DKIM 私钥写入失败。'));
        }
        @chmod($path, 0600);
    }

    private function publicBodyFromPrivatePem(string $privatePem): string
    {
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false) {
            throw new \RuntimeException((string)__('DKIM 私钥不可读。'));
        }
        $details = openssl_pkey_get_details($key);
        $pubPem = is_array($details) ? (string)($details['key'] ?? '') : '';
        $body = $this->pemBody($pubPem);
        if ($body === '' || strlen(base64_decode($body, true) ?: '') < 32) {
            throw new \RuntimeException((string)__('DKIM 公钥提取失败。'));
        }

        return $body;
    }

    private function publicBodyFromDnsOrPem(string $value): string
    {
        $value = trim($value);
        if (stripos($value, 'v=DKIM1') === 0) {
            if (!preg_match('/(?:^|;)\s*p\s*=\s*([A-Za-z0-9+\/=]+)/i', $value, $match)) {
                throw new \DomainException((string)__('DKIM TXT 缺少有效的 p= 公钥。'));
            }

            return $match[1];
        }
        if (str_contains($value, 'BEGIN PUBLIC KEY') || str_contains($value, 'BEGIN RSA PUBLIC KEY')) {
            return $this->pemBody($value);
        }

        return preg_replace('/[\s"]+/', '', $value) ?? '';
    }

    private function pemBody(string $pem): string
    {
        $body = preg_replace('/-----[^-]+-----/', '', $pem) ?? '';

        return preg_replace('/\s+/', '', $body) ?? '';
    }
}
