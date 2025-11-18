<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Helper;

/**
 * 双因素身份验证核心类
 * 
 * 完全原生PHP实现TOTP算法（RFC 6238）
 * 不依赖任何第三方库
 * 兼容所有标准2FA应用（Google Authenticator、Microsoft Authenticator等）
 * 
 * @package Weline\TwoFactorAuth\Helper
 */
class TwoFactorAuthHelper
{
    /**
     * Base32字符映射表
     */
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    
    /**
     * 默认验证码位数
     */
    private const DEFAULT_DIGITS = 6;
    
    /**
     * 默认时间步长（秒）
     */
    private const DEFAULT_PERIOD = 30;
    
    /**
     * 默认时间窗口（允许前后偏移）
     */
    private const DEFAULT_WINDOW = 1;
    
    /**
     * 生成随机密钥
     * 
     * @param int $length 密钥长度（字节数，建议16-32）
     * @return string Base32编码的密钥
     */
    public static function generateSecret(int $length = 20): string
    {
        $bytes = random_bytes($length);
        return self::base32Encode($bytes);
    }
    
    /**
     * Base32编码
     * 
     * @param string $data 原始数据
     * @return string Base32编码后的字符串
     */
    private static function base32Encode(string $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        
        $chunks = str_split($binary, 5);
        $encoded = '';
        
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0');
            $encoded .= self::BASE32_CHARS[bindec($chunk)];
        }
        
        // 移除尾部填充字符（如果有的话）
        return rtrim($encoded, '=');
    }
    
    /**
     * Base32解码
     * 
     * @param string $data Base32编码的字符串
     * @return string 解码后的原始数据
     */
    private static function base32Decode(string $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        $data = strtoupper(str_replace([' ', '-', '='], '', $data));
        $binary = '';
        
        for ($i = 0; $i < strlen($data); $i++) {
            $char = $data[$i];
            $pos = strpos(self::BASE32_CHARS, $char);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        
        $chunks = str_split($binary, 8);
        $decoded = '';
        
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $decoded .= chr(bindec($chunk));
        }
        
        return $decoded;
    }
    
    /**
     * 生成TOTP验证码
     * 
     * @param string $secret Base32编码的密钥
     * @param int|null $timestamp 时间戳（null表示当前时间）
     * @param int $period 时间步长（秒）
     * @param int $digits 验证码位数
     * @return string 验证码
     */
    public static function generateCode(
        string $secret,
        string $algorithm = 'SHA1',
        int $digits = self::DEFAULT_DIGITS,
        int $period = self::DEFAULT_PERIOD,
        ?int $timestamp = null
    ): string {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        // 计算时间步数
        $timeStep = (int)floor($timestamp / $period);
        
        // 解码密钥
        $key = self::base32Decode($secret);
        
        // 将时间步数转换为8字节大端序
        $timeBytes = pack('N*', 0, $timeStep);
        
        // 计算HMAC
        $hash = hash_hmac(strtolower($algorithm), $timeBytes, $key, true);
        
        // 动态截断（RFC 4226）
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncatedHash = substr($hash, $offset, 4);
        
        // 转换为整数
        $value = unpack('N', $truncatedHash)[1];
        $value = $value & 0x7FFFFFFF;
        
        // 生成验证码
        $code = $value % (10 ** $digits);
        
        return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
    }
    
    /**
     * 验证用户输入的验证码
     * 
     * @param string $secret 密钥
     * @param string $code 用户输入的验证码
     * @param int $window 时间窗口（允许前后几个时间步）
     * @param int|null $timestamp 时间戳（null表示当前时间）
     * @return bool 是否验证通过
     */
    public static function verifyCode(
        string $secret,
        string $code,
        int $window = self::DEFAULT_WINDOW,
        ?int $timestamp = null
    ): bool {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        $period = self::DEFAULT_PERIOD;
        
        // 检查当前时间及前后时间窗口
        for ($i = -$window; $i <= $window; $i++) {
            $testTime = $timestamp + ($i * $period);
            $generatedCode = self::generateCode($secret, $testTime);
            
            // 使用时间安全的字符串比较
            if (hash_equals($generatedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 生成otpauth URI
     * 用于生成二维码
     * 
     * @param string $secret 密钥
     * @param string $account 账户名（通常是邮箱或用户名）
     * @param string $issuer 发行者名称（应用名称）
     * @param int $digits 验证码位数
     * @param int $period 时间步长
     * @return string otpauth URI
     */
    public static function getOtpAuthUri(
        string $secret,
        string $account,
        string $issuer = 'WelineFramework',
        int $digits = self::DEFAULT_DIGITS,
        int $period = self::DEFAULT_PERIOD
    ): string {
        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => $digits,
            'period' => $period
        ];
        
        $uri = sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($issuer),
            rawurlencode($account),
            http_build_query($params)
        );
        
        return $uri;
    }
    
    /**
     * 生成二维码数据URL（使用Google Charts API）
     * 
     * @param string $otpAuthUri otpauth URI
     * @param int $size 二维码尺寸
     * @return string 二维码图片URL
     */
    public static function getQRCodeUrl(string $otpAuthUri, int $size = 200): string
    {
        return sprintf(
            'https://chart.googleapis.com/chart?chs=%dx%d&chld=M|0&cht=qr&chl=%s',
            $size,
            $size,
            urlencode($otpAuthUri)
        );
    }
    
    /**
     * 生成二维码SVG（纯PHP实现，简化版）
     * 实际应用可以使用更完整的QR码生成库
     * 
     * @param string $data 要编码的数据
     * @return string SVG代码
     */
    public static function generateQRCodeSVG(string $data): string
    {
        // 这里返回一个占位SVG
        // 实际应用中建议使用完整的QR码生成算法
        $encoded = htmlspecialchars($data);
        
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200">
    <rect fill="white" width="200" height="200"/>
    <text x="100" y="90" text-anchor="middle" font-size="12" fill="#666">
        请使用二维码扫描器扫描
    </text>
    <text x="100" y="110" text-anchor="middle" font-size="10" fill="#999">
        或手动输入密钥
    </text>
    <text x="100" y="130" text-anchor="middle" font-size="8" fill="#ccc" font-family="monospace">
        {$encoded}
    </text>
</svg>
SVG;
    }
    
    /**
     * 格式化密钥（添加空格分隔，便于手动输入）
     * 
     * @param string $secret 密钥
     * @param int $chunkSize 每组字符数
     * @return string 格式化后的密钥
     */
    public static function formatSecret(string $secret, int $chunkSize = 4): string
    {
        return implode(' ', str_split($secret, $chunkSize));
    }
    
    /**
     * 获取当前时间步剩余秒数
     * 
     * @param int $period 时间步长
     * @return int 剩余秒数
     */
    public static function getRemainingSeconds(int $period = self::DEFAULT_PERIOD): int
    {
        return $period - (time() % $period);
    }
    
    /**
     * 验证密钥格式是否正确
     * 
     * @param string $secret 密钥
     * @return bool 是否有效
     */
    public static function isValidSecret(string $secret): bool
    {
        // 移除空格和特殊字符
        $secret = str_replace([' ', '-', '='], '', $secret);
        
        // 检查长度（至少16个字符）
        if (strlen($secret) < 16) {
            return false;
        }
        
        // 检查是否只包含Base32字符
        return preg_match('/^[A-Z2-7]+$/i', $secret) === 1;
    }
    
    /**
     * 验证Base32格式（别名）
     * 
     * @param string $secret 密钥
     * @return bool 是否有效
     */
    public static function isValidBase32(string $secret): bool
    {
        return self::isValidSecret($secret);
    }
    
    /**
     * 生成备份码（用于紧急恢复）
     * 
     * @param int $count 生成数量
     * @return array 备份码数组
     */
    public static function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = sprintf(
                '%04d-%04d',
                random_int(0, 9999),
                random_int(0, 9999)
            );
        }
        return $codes;
    }
}

