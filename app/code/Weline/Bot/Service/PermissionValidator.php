<?php
declare(strict_types=1);

namespace Weline\Bot\Service;

use Weline\Bot\Model\BotRole;

/**
 * 权限验证器
 *
 * 验证角色是否有权限执行特定操作
 */
class PermissionValidator
{
    public function __construct(
        private readonly SkillPackageManager $skillManager,
    ) {}

    /**
     * 验证权限
     *
     * @param string $skillCode 技能代码
     * @param array $params 参数
     * @param BotRole $role 角色
     * @return bool
     */
    public function validate(string $skillCode, array $params, BotRole $role): bool
    {
        // 获取技能所需权限
        $skill = $this->skillManager->getSkill($skillCode);
        if (!$skill) {
            return false;
        }

        $requiredPermissions = $skill->getPermissionRequired();
        if (empty($requiredPermissions)) {
            return true; // 无权限要求
        }

        // 获取角色已授权的权限
        $grantedPermissions = $role->getPermissions();

        // 检查每个所需权限
        foreach ($requiredPermissions as $required) {
            if (!$this->checkPermission($required, $params, $grantedPermissions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 检查单个权限
     *
     * @param string $required 所需权限
     * @param array $params 参数
     * @param array $granted 已授权权限（格式：['fs.read:/app/*', 'http.request:*', ...]）
     * @return bool
     */
    private function checkPermission(string $required, array $params, array $granted): bool
    {
        // 解析权限类型
        $parts = explode('.', $required, 2);
        $type = $parts[0] ?? '';
        $spec = $parts[1] ?? '';

        // 遍历已授权权限，支持通配符和精确匹配
        foreach ($granted as $grantedPerm) {
            // 完全通配符
            if ($grantedPerm === '*') {
                return true;
            }

            // 解析已授权权限
            $grantedParts = explode('.', $grantedPerm, 2);
            $grantedType = $grantedParts[0] ?? '';
            $grantedSpec = $grantedParts[1] ?? '';

            // 类型不匹配，跳过
            if ($grantedType !== $type && $grantedType !== '*') {
                continue;
            }

            // 类型匹配且规格为通配符
            if ($grantedSpec === '*') {
                return true;
            }

            // 规格匹配（支持前缀和通配符）
            if ($this->matchPermissionSpec($type, $spec, $grantedSpec, $params)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 匹配权限规格
     */
    private function matchPermissionSpec(string $type, string $required, string $granted, array $params): bool
    {
        // 精确匹配
        if ($granted === $required) {
            return true;
        }

        // 通配符匹配（如 fs.read:* 匹配 fs.read:/app/*）
        if (str_ends_with($granted, '*')) {
            $prefix = rtrim($granted, '*');
            if (str_starts_with($required, $prefix)) {
                return true;
            }
        }

        // 特定类型的深度匹配
        switch ($type) {
            case 'fs':
                return $this->matchFsSpec($required, $granted, $params);

            case 'http':
                return $this->matchHttpSpec($required, $granted, $params);

            case 'shell':
                return $this->matchShellSpec($required, $granted, $params);

            case 'db':
                return $this->matchDbSpec($required, $granted, $params);

            default:
                return $this->matchPattern($required, $granted);
        }
    }

    /**
     * 匹配文件系统权限规格
     */
    private function matchFsSpec(string $required, string $granted, array $params): bool
    {
        // granted 格式如: /app/* 或 /var/log/*
        $path = $params['path'] ?? '';
        if (empty($path)) {
            return false;
        }

        $path = $this->normalizePath($path);
        $grantedPath = $this->normalizePath($granted);

        // 路径前缀匹配
        if (str_ends_with($grantedPath, '/*')) {
            $prefix = dirname($grantedPath);
            return str_starts_with($path, $prefix);
        }

        return $this->matchPath($path, $grantedPath);
    }

    /**
     * 匹配 HTTP 权限规格
     */
    private function matchHttpSpec(string $required, string $granted, array $params): bool
    {
        $url = $params['url'] ?? '';
        if (empty($url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST) ?? '';

        // granted 格式如: * 或 api.example.com 或 *.example.com
        if ($granted === '*') {
            return true;
        }

        if (str_starts_with($granted, '*.')) {
            // 子域名通配符
            $domain = substr($granted, 2);
            return str_ends_with($host, $domain) || $host === substr($domain, 1);
        }

        return $host === $granted;
    }

    /**
     * 匹配 Shell 权限规格
     */
    private function matchShellSpec(string $required, string $granted, array $params): bool
    {
        $command = $params['command'] ?? '';
        if (empty($command)) {
            return false;
        }

        // 提取命令名
        $commandName = preg_split('/\s+/', trim($command))[0] ?? '';

        if ($granted === '*') {
            return true;
        }

        return $commandName === $granted || str_starts_with($commandName, $granted);
    }

    /**
     * 匹配数据库权限规格
     */
    private function matchDbSpec(string $required, string $granted, array $params): bool
    {
        $sql = $params['sql'] ?? '';
        if (empty($sql)) {
            return false;
        }

        // 提取表名
        preg_match('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?/i', $sql, $matches);
        $table = $matches[1] ?? '';

        if ($granted === '*') {
            return true;
        }

        return $this->matchPattern($table, $granted);
    }

    /**
     * 匹配模式
     */
    private function matchPattern(string $value, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        // 简单通配符匹配
        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
            return preg_match($regex, $value) === 1;
        }

        return $value === $pattern;
    }

    /**
     * 匹配路径
     */
    private function matchPath(string $path, string $pattern): bool
    {
        // 支持 /path/* 格式
        if (str_ends_with($pattern, '/*')) {
            $prefix = dirname($pattern);
            return str_starts_with($path, $prefix);
        }

        return $this->matchPattern($path, $pattern);
    }

    /**
     * 规范化路径
     */
    private function normalizePath(string $path): string
    {
        // 替换反斜杠为正斜杠
        $path = str_replace('\\', '/', $path);
        // 移除多余的斜杠
        $path = preg_replace('#/+#', '/', $path);
        return $path;
    }
}
