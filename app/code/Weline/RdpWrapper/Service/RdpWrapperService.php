<?php

declare(strict_types=1);

namespace Weline\RdpWrapper\Service;

use Weline\RdpWrapper\Model\RdpUser;
use Weline\Framework\Manager\ObjectManager;

/**
 * @DESC | RDP Wrapper 服务：管理安装、启停、Windows 用户账户
 */
class RdpWrapperService
{
    /**
     * RDP Wrapper 默认安装路径
     */
    private const INSTALL_DIR = 'C:\\Program Files\\RDP Wrapper';

    /**
     * RDP Wrapper 安装脚本相对路径（相对模块根目录）
     */
    private const INSTALL_SCRIPT = 'setup' . DIRECTORY_SEPARATOR . 'install_rdpwrap.ps1';

    private RdpUser $rdpUser;

    public function __construct(
        RdpUser $rdpUser
    ) {
        $this->rdpUser = $rdpUser;
    }

    // ==================== RDP Wrapper 状态检测 ====================

    /**
     * 检查当前操作系统是否为 Windows
     */
    public function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * 检查 RDP Wrapper 是否已安装
     */
    public function isInstalled(): bool
    {
        if (!$this->isWindows()) {
            return false;
        }
        return is_dir(self::INSTALL_DIR) && file_exists(self::INSTALL_DIR . '\\rdpwrap.dll');
    }

    /**
     * 获取 RDP Wrapper 安装状态详情
     *
     * @return array{installed: bool, path: string, service_running: bool, rdp_enabled: bool, version: string}
     */
    public function getStatus(): array
    {
        $status = [
            'is_windows'      => $this->isWindows(),
            'installed'        => false,
            'path'             => self::INSTALL_DIR,
            'service_running'  => false,
            'rdp_enabled'      => false,
            'rdp_port'         => 3389,
            'computer_name'    => '',
            'ip_addresses'     => [],
        ];

        if (!$this->isWindows()) {
            return $status;
        }

        $status['installed']       = $this->isInstalled();
        $status['service_running'] = $this->isTermServiceRunning();
        $status['rdp_enabled']     = $this->isRdpEnabled();
        $status['rdp_port']        = $this->getRdpPort();
        $status['computer_name']   = gethostname() ?: '';
        $status['ip_addresses']    = $this->getLocalIpAddresses();

        return $status;
    }

    /**
     * 检查 TermService（远程桌面服务）是否运行
     */
    public function isTermServiceRunning(): bool
    {
        if (!$this->isWindows()) {
            return false;
        }
        $output = [];
        exec('sc query TermService 2>nul', $output);
        $outputStr = implode(' ', $output);
        return str_contains($outputStr, 'RUNNING');
    }

    /**
     * 检查系统是否启用了远程桌面
     */
    public function isRdpEnabled(): bool
    {
        if (!$this->isWindows()) {
            return false;
        }
        $output = [];
        exec('reg query "HKLM\\SYSTEM\\CurrentControlSet\\Control\\Terminal Server" /v fDenyTSConnections 2>nul', $output);
        $outputStr = implode(' ', $output);
        // fDenyTSConnections = 0 表示允许远程连接
        return str_contains($outputStr, '0x0');
    }

    /**
     * 获取 RDP 端口号
     */
    public function getRdpPort(): int
    {
        if (!$this->isWindows()) {
            return 3389;
        }
        $output = [];
        exec('reg query "HKLM\\SYSTEM\\CurrentControlSet\\Control\\Terminal Server\\WinStations\\RDP-Tcp" /v PortNumber 2>nul', $output);
        foreach ($output as $line) {
            if (preg_match('/PortNumber\s+REG_DWORD\s+0x([0-9a-fA-F]+)/i', $line, $matches)) {
                return (int)hexdec($matches[1]);
            }
        }
        return 3389;
    }

    /**
     * 获取本机 IP 地址列表
     *
     * @return string[]
     */
    public function getLocalIpAddresses(): array
    {
        if (!$this->isWindows()) {
            return [];
        }
        $ips = [];
        $output = [];
        exec('ipconfig 2>nul', $output);
        foreach ($output as $line) {
            if (preg_match('/IPv4.*?:\s*([\d.]+)/', $line, $matches)) {
                $ips[] = $matches[1];
            }
        }
        return $ips;
    }

    // ==================== RDP Wrapper 安装管理 ====================

    /**
     * 安装 RDP Wrapper（通过 PowerShell 脚本）
     *
     * @return array{success: bool, message: string}
     */
    public function install(): array
    {
        if (!$this->isWindows()) {
            return ['success' => false, 'message' => __('RDP Wrapper 仅支持 Windows 系统')];
        }

        if ($this->isInstalled()) {
            return ['success' => true, 'message' => __('RDP Wrapper 已安装')];
        }

        $scriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . self::INSTALL_SCRIPT;
        if (!file_exists($scriptPath)) {
            return ['success' => false, 'message' => __('安装脚本不存在：%{path}', ['path' => $scriptPath])];
        }

        $output = [];
        $returnCode = 0;
        $cmd = "powershell -ExecutionPolicy Bypass -File \"{$scriptPath}\" 2>&1";
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0) {
            return ['success' => true, 'message' => __('RDP Wrapper 安装成功')];
        }

        return [
            'success' => false,
            'message' => __('RDP Wrapper 安装失败：%{error}', ['error' => implode("\n", $output)])
        ];
    }

    /**
     * 启用系统远程桌面
     *
     * @return array{success: bool, message: string}
     */
    public function enableRdp(): array
    {
        if (!$this->isWindows()) {
            return ['success' => false, 'message' => __('仅支持 Windows 系统')];
        }

        $output = [];
        $returnCode = 0;

        // 设置注册表允许远程连接
        exec('reg add "HKLM\\SYSTEM\\CurrentControlSet\\Control\\Terminal Server" /v fDenyTSConnections /t REG_DWORD /d 0 /f 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            return ['success' => false, 'message' => __('启用远程桌面失败，请以管理员权限运行')];
        }

        // 开启防火墙远程桌面规则
        exec('netsh advfirewall firewall set rule group="remote desktop" new enable=yes 2>&1', $output, $returnCode);

        // 确保 TermService 启动
        exec('sc config TermService start= auto 2>&1', $output);
        exec('net start TermService 2>&1', $output);

        return ['success' => true, 'message' => __('远程桌面已启用')];
    }

    /**
     * 禁用系统远程桌面
     *
     * @return array{success: bool, message: string}
     */
    public function disableRdp(): array
    {
        if (!$this->isWindows()) {
            return ['success' => false, 'message' => __('仅支持 Windows 系统')];
        }

        $output = [];
        exec('reg add "HKLM\\SYSTEM\\CurrentControlSet\\Control\\Terminal Server" /v fDenyTSConnections /t REG_DWORD /d 1 /f 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            return ['success' => false, 'message' => __('禁用远程桌面失败，请以管理员权限运行')];
        }

        return ['success' => true, 'message' => __('远程桌面已禁用')];
    }

    // ==================== Windows 用户管理 ====================

    /**
     * 创建 Windows 用户并记录到数据库
     *
     * @param string $username     用户名
     * @param string $password     密码
     * @param string $displayName  显示名称
     * @param bool   $isAdmin      是否加入管理员组
     * @param string $remark       备注
     * @return array{success: bool, message: string}
     */
    public function createUser(
        string $username,
        string $password,
        string $displayName = '',
        bool   $isAdmin = false,
        string $remark = ''
    ): array {
        if (!$this->isWindows()) {
            return ['success' => false, 'message' => __('仅支持 Windows 系统')];
        }

        if (empty($username) || empty($password)) {
            return ['success' => false, 'message' => __('用户名和密码不能为空')];
        }

        // 检查用户名是否合法
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,19}$/', $username)) {
            return ['success' => false, 'message' => __('用户名格式不正确：3-20位，字母开头，仅允许字母数字下划线')];
        }

        // 检查系统中是否已存在该用户
        $output = [];
        exec("net user \"{$username}\" 2>&1", $output, $returnCode);
        if ($returnCode === 0) {
            return ['success' => false, 'message' => __('系统中已存在用户：%{user}', ['user' => $username])];
        }

        // 创建 Windows 用户
        $output = [];
        $escapedPassword = str_replace('"', '""', $password);
        $cmd = "net user \"{$username}\" \"{$escapedPassword}\" /add /comment:\"{$displayName}\" /fullname:\"{$displayName}\" 2>&1";
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => __('创建用户失败：%{error}', ['error' => implode(' ', $output)])
            ];
        }

        // 设置密码永不过期
        exec("wmic useraccount where \"Name='{$username}'\" set PasswordExpires=FALSE 2>&1", $output);

        // 添加到 Remote Desktop Users 组（允许远程桌面连接）
        exec("net localgroup \"Remote Desktop Users\" \"{$username}\" /add 2>&1", $output);

        // 如果需要管理员权限
        if ($isAdmin) {
            exec("net localgroup Administrators \"{$username}\" /add 2>&1", $output);
        }

        // 保存到数据库
        try {
            $user = ObjectManager::getInstance(RdpUser::class);
            $user->setData([
                RdpUser::schema_fields_USERNAME     => $username,
                RdpUser::schema_fields_DISPLAY_NAME => $displayName ?: $username,
                RdpUser::schema_fields_PASSWORD_HINT => mb_substr($password, 0, 1) . '***' . mb_substr($password, -1),
                RdpUser::schema_fields_IS_ADMIN     => $isAdmin ? 1 : 0,
                RdpUser::schema_fields_STATUS       => RdpUser::STATUS_ENABLED,
                RdpUser::schema_fields_REMARK       => $remark,
            ])->save();
        } catch (\Exception $e) {
            // 数据库保存失败不影响用户创建
        }

        return ['success' => true, 'message' => __('用户 %{user} 创建成功', ['user' => $username])];
    }

    /**
     * 删除 Windows 用户
     *
     * @param string $username
     * @return array{success: bool, message: string}
     */
    public function removeUser(string $username): array
    {
        if (!$this->isWindows()) {
            return ['success' => false, 'message' => __('仅支持 Windows 系统')];
        }

        if (empty($username)) {
            return ['success' => false, 'message' => __('用户名不能为空')];
        }

        // 不允许删除内置管理员账户
        $protectedUsers = ['Administrator', 'DefaultAccount', 'Guest', 'WDAGUtilityAccount'];
        if (in_array($username, $protectedUsers, true)) {
            return ['success' => false, 'message' => __('不允许删除系统内置用户：%{user}', ['user' => $username])];
        }

        // 删除 Windows 用户
        $output = [];
        exec("net user \"{$username}\" /delete 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => __('删除用户失败：%{error}', ['error' => implode(' ', $output)])
            ];
        }

        // 从数据库中删除记录
        try {
            $user = ObjectManager::getInstance(RdpUser::class);
            $user->reset()
                ->where(RdpUser::schema_fields_USERNAME, $username)
                ->find()
                ->fetch();
            if ($user->getId()) {
                $user->delete();
            }
        } catch (\Exception $e) {
            // 数据库删除失败不影响用户删除
        }

        return ['success' => true, 'message' => __('用户 %{user} 已删除', ['user' => $username])];
    }

    /**
     * 启用/禁用 Windows 用户
     *
     * @param string $username
     * @param bool   $enable
     * @return array{success: bool, message: string}
     */
    public function toggleUser(string $username, bool $enable): array
    {
        if (!$this->isWindows()) {
            return ['success' => false, 'message' => __('仅支持 Windows 系统')];
        }

        $action = $enable ? 'yes' : 'no';
        $output = [];
        exec("net user \"{$username}\" /active:{$action} 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => __('操作失败：%{error}', ['error' => implode(' ', $output)])
            ];
        }

        // 更新数据库状态
        try {
            $user = ObjectManager::getInstance(RdpUser::class);
            $user->reset()
                ->where(RdpUser::schema_fields_USERNAME, $username)
                ->find()
                ->fetch();
            if ($user->getId()) {
                $user->setData(RdpUser::schema_fields_STATUS, $enable ? RdpUser::STATUS_ENABLED : RdpUser::STATUS_DISABLED)
                    ->save();
            }
        } catch (\Exception $e) {
            // 忽略
        }

        $msg = $enable ? __('用户 %{user} 已启用', ['user' => $username]) : __('用户 %{user} 已禁用', ['user' => $username]);
        return ['success' => true, 'message' => $msg];
    }

    /**
     * 重置 Windows 用户密码
     *
     * @param string $username
     * @param string $newPassword
     * @return array{success: bool, message: string}
     */
    public function resetPassword(string $username, string $newPassword): array
    {
        if (!$this->isWindows()) {
            return ['success' => false, 'message' => __('仅支持 Windows 系统')];
        }

        if (empty($username) || empty($newPassword)) {
            return ['success' => false, 'message' => __('用户名和密码不能为空')];
        }

        $escapedPassword = str_replace('"', '""', $newPassword);
        $output = [];
        exec("net user \"{$username}\" \"{$escapedPassword}\" 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => __('重置密码失败：%{error}', ['error' => implode(' ', $output)])
            ];
        }

        // 更新密码提示
        try {
            $user = ObjectManager::getInstance(RdpUser::class);
            $user->reset()
                ->where(RdpUser::schema_fields_USERNAME, $username)
                ->find()
                ->fetch();
            if ($user->getId()) {
                $user->setData(RdpUser::schema_fields_PASSWORD_HINT, mb_substr($newPassword, 0, 1) . '***' . mb_substr($newPassword, -1))
                    ->save();
            }
        } catch (\Exception $e) {
            // 忽略
        }

        return ['success' => true, 'message' => __('用户 %{user} 密码已重置', ['user' => $username])];
    }

    /**
     * 获取已管理的 RDP 用户列表（从数据库）
     *
     * @return array
     */
    public function getUserList(): array
    {
        try {
            return $this->rdpUser
                ->reset()
                ->select()
                ->fetchArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取系统中所有 Windows 用户列表
     *
     * @return string[]
     */
    public function getSystemUsers(): array
    {
        if (!$this->isWindows()) {
            return [];
        }

        $output = [];
        exec('net user 2>nul', $output);

        $users = [];
        $capture = false;
        foreach ($output as $line) {
            if (str_contains($line, '----')) {
                $capture = true;
                continue;
            }
            if ($capture && !empty(trim($line)) && !str_contains($line, __('命令成功完成')) && !str_contains($line, 'The command completed successfully')) {
                $parts = preg_split('/\s{2,}/', trim($line));
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (!empty($part)) {
                        $users[] = $part;
                    }
                }
            }
        }

        return $users;
    }
}
