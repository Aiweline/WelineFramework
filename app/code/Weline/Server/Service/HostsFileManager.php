<?php
declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Hosts 文件管理服务
 *
 * 自动配置系统 hosts 文件，将项目域名映射到 127.0.0.1
 */
class HostsFileManager
{
    private const MARKER_START = '# Weline WLS Auto-Config Start';
    private const MARKER_END = '# Weline WLS Auto-Config End';

    /**
     * 获取 hosts 文件路径
     */
    public static function getHostsFilePath(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return getenv('SystemRoot') . '\System32\drivers\etc\hosts';
        }
        return '/etc/hosts';
    }

    /**
     * 检查是否有权限修改 hosts 文件
     */
    public static function hasPermission(): bool
    {
        $hostsFile = self::getHostsFilePath();
        return is_writable($hostsFile);
    }

    /**
     * 添加域名到 hosts 文件
     *
     * @param string $domain 域名
     * @param string $ip IP 地址（默认 127.0.0.1）
     * @return array ['success' => bool, 'message' => string, 'needs_admin' => bool]
     */
    public static function addDomain(string $domain, string $ip = '127.0.0.1'): array
    {
        $hostsFile = self::getHostsFilePath();

        // 检查文件是否存在
        if (!file_exists($hostsFile)) {
            return [
                'success' => false,
                'message' => "Hosts 文件不存在: {$hostsFile}",
                'needs_admin' => false,
            ];
        }

        // 检查权限
        if (!self::hasPermission()) {
            return [
                'success' => false,
                'message' => '需要管理员权限修改 hosts 文件',
                'needs_admin' => true,
                'command' => self::getAdminCommand($domain, $ip),
            ];
        }

        // 读取现有内容
        $content = file_get_contents($hostsFile);
        if ($content === false) {
            return [
                'success' => false,
                'message' => "无法读取 hosts 文件: {$hostsFile}",
                'needs_admin' => false,
            ];
        }

        // 检查域名是否已存在
        if (self::domainExists($content, $domain)) {
            return [
                'success' => true,
                'message' => "域名 {$domain} 已存在于 hosts 文件中",
                'needs_admin' => false,
                'already_exists' => true,
            ];
        }

        // 添加域名
        $newContent = self::addDomainToContent($content, $domain, $ip);

        // 写入文件
        if (file_put_contents($hostsFile, $newContent) === false) {
            return [
                'success' => false,
                'message' => "无法写入 hosts 文件: {$hostsFile}",
                'needs_admin' => true,
            ];
        }

        return [
            'success' => true,
            'message' => "已将 {$domain} 添加到 hosts 文件",
            'needs_admin' => false,
        ];
    }

    /**
     * 检查域名是否已存在
     */
    private static function domainExists(string $content, string $domain): bool
    {
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            // 跳过注释和空行
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            // 检查是否包含域名
            if (preg_match('/\s+' . preg_quote($domain, '/') . '(\s|$)/', $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 添加域名到内容
     */
    private static function addDomainToContent(string $content, string $domain, string $ip): string
    {
        // 查找 Weline 标记块
        if (preg_match('/' . preg_quote(self::MARKER_START, '/') . '(.*?)' . preg_quote(self::MARKER_END, '/') . '/s', $content, $matches)) {
            // 已有标记块，在块内添加
            $block = $matches[1];
            $newBlock = $block . "\n{$ip} {$domain}";
            $newContent = str_replace($matches[0], self::MARKER_START . $newBlock . "\n" . self::MARKER_END, $content);
        } else {
            // 没有标记块，创建新块
            $block = "\n" . self::MARKER_START . "\n{$ip} {$domain}\n" . self::MARKER_END . "\n";
            $newContent = $content . $block;
        }

        return $newContent;
    }

    /**
     * 获取管理员命令（用于提示用户手动执行）
     */
    private static function getAdminCommand(string $domain, string $ip): string
    {
        $hostsFile = self::getHostsFilePath();

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: 使用 PowerShell 以管理员身份添加
            return "Add-Content -Path '{$hostsFile}' -Value '{$ip} {$domain}'";
        }

        // Linux/Mac: 使用 sudo
        return "echo '{$ip} {$domain}' | sudo tee -a {$hostsFile}";
    }

    /**
     * 移除域名
     */
    public static function removeDomain(string $domain): array
    {
        $hostsFile = self::getHostsFilePath();

        if (!self::hasPermission()) {
            return [
                'success' => false,
                'message' => '需要管理员权限修改 hosts 文件',
                'needs_admin' => true,
            ];
        }

        $content = file_get_contents($hostsFile);
        if ($content === false) {
            return [
                'success' => false,
                'message' => "无法读取 hosts 文件: {$hostsFile}",
            ];
        }

        // 移除包含该域名的行
        $lines = explode("\n", $content);
        $newLines = [];
        $removed = false;

        foreach ($lines as $line) {
            if (preg_match('/\s+' . preg_quote($domain, '/') . '(\s|$)/', $line)) {
                $removed = true;
                continue; // 跳过这一行
            }
            $newLines[] = $line;
        }

        if (!$removed) {
            return [
                'success' => true,
                'message' => "域名 {$domain} 不存在于 hosts 文件中",
                'already_removed' => true,
            ];
        }

        $newContent = implode("\n", $newLines);

        if (file_put_contents($hostsFile, $newContent) === false) {
            return [
                'success' => false,
                'message' => "无法写入 hosts 文件: {$hostsFile}",
            ];
        }

        return [
            'success' => true,
            'message' => "已从 hosts 文件移除 {$domain}",
        ];
    }
}
