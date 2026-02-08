<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Env\Api\Data\EnvRequirements;
use Weline\Framework\Env\Service\EnvChecker;
use Weline\Framework\Env\Service\EnvRequirementsCollector;
use Weline\Framework\Env\Service\RecommendedStatusService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 环境管理后台控制器
 *
 * @DESC 环境依赖检测、推荐项安装状态管理
 */
class EnvManager extends BackendController
{
    private EnvRequirementsCollector $collector;
    private EnvChecker $checker;
    private RecommendedStatusService $statusService;

    public function __construct(
        EnvRequirementsCollector $collector,
        EnvChecker $checker,
        RecommendedStatusService $statusService
    ) {
        $this->collector = $collector;
        $this->checker = $checker;
        $this->statusService = $statusService;
    }

    /**
     * 环境管理首页
     */
    public function getIndex(): string
    {
        // 收集需求
        $requirements = $this->collector->collect();

        // 检测环境
        $this->checker->setRequirements($requirements);
        $result = $this->checker->check();

        // 获取安装状态记录
        $allStatuses = $this->statusService->getAllStatuses();

        // 系统信息
        $systemInfo = [
            'php_version'    => PHP_VERSION,
            'os_family'      => PHP_OS_FAMILY,
            'os'             => PHP_OS,
            'sapi'           => PHP_SAPI,
            'php_ini'        => \php_ini_loaded_file() ?: __('未知'),
            'ext_dir'        => \ini_get('extension_dir'),
            'loaded_exts'    => \get_loaded_extensions(),
            'disable_funcs'  => \ini_get('disable_functions'),
        ];

        // 推荐扩展（带平台过滤）
        $recommendedExts = [];
        foreach ($requirements->getRecommendedExtensions() as $item) {
            $extName  = $item['name'] ?? '';
            $platform = $item['platform'] ?? 'all';
            $reason   = $item['reason'] ?? '';
            $matches  = EnvRequirements::matchesPlatform($platform);
            $loaded   = \extension_loaded($extName);
            $status   = $this->statusService->getStatus('extension', $extName);

            $recommendedExts[] = [
                'name'     => $extName,
                'platform' => $platform,
                'reason'   => $reason,
                'matches'  => $matches,
                'loaded'   => $loaded,
                'status'   => $status,
                'guide'    => $this->getInstallGuide($extName),
            ];
        }

        // 推荐函数
        $recommendedFuncs = [];
        foreach ($requirements->getRecommendedFunctions() as $func) {
            $exists = \function_exists($func);
            $status = $this->statusService->getStatus('function', $func);
            $recommendedFuncs[] = [
                'name'   => $func,
                'exists' => $exists,
                'status' => $status,
            ];
        }

        // 推荐 items
        $recommendedItems = [];
        foreach ($requirements->getRecommendedItems() as $item) {
            $name   = $item['name'] ?? '';
            $desc   = $item['description'] ?? '';
            $status = $this->statusService->getStatus('item', $name);
            $recommendedItems[] = [
                'name'        => $name,
                'description' => $desc,
                'status'      => $status,
            ];
        }

        $this->assign('title', __('环境管理'));
        $this->assign('system_info', $systemInfo);
        $this->assign('check_result', $result->toArray());
        $this->assign('recommended_exts', $recommendedExts);
        $this->assign('recommended_funcs', $recommendedFuncs);
        $this->assign('recommended_items', $recommendedItems);
        $this->assign('all_statuses', $allStatuses);
        $this->assign('platform', PHP_OS_FAMILY);

        return $this->fetch();
    }

    /**
     * AJAX: 重试安装某个推荐项
     */
    public function postRetryInstall(): string
    {
        $type = $this->request->getParam('type', '');   // extension / function / item
        $name = $this->request->getParam('name', '');

        if (empty($type) || empty($name)) {
            return $this->fetchJson(['code' => 400, 'msg' => __('参数不完整')]);
        }

        // 重置状态允许重试
        $this->statusService->resetStatus($type, $name);

        // 执行安装尝试
        $success = false;
        $message = '';

        switch ($type) {
            case 'extension':
                $success = $this->tryInstallExtensionFromBackend($name);
                $message = $success ? __('扩展 %{name} 安装成功', ['name' => $name]) : __('扩展 %{name} 安装失败', ['name' => $name]);
                break;

            case 'function':
                // 函数依赖扩展安装，无法单独处理
                $message = __('请先安装对应扩展，函数自动可用');
                break;

            case 'item':
                $message = __('请通过 CLI 执行: php bin/w env:install --force');
                break;

            default:
                return $this->fetchJson(['code' => 400, 'msg' => __('未知类型: %{type}', ['type' => $type])]);
        }

        if ($success) {
            $this->statusService->markInstalled($type, $name, $message);
        } else {
            $this->statusService->markFailed($type, $name, $message);
        }

        return $this->fetchJson([
            'code'    => $success ? 200 : 500,
            'msg'     => $message,
            'success' => $success,
        ]);
    }

    /**
     * AJAX: 重置所有安装记录
     */
    public function postResetAll(): string
    {
        $this->statusService->resetAll();
        return $this->fetchJson([
            'code' => 200,
            'msg'  => __('已重置所有安装记录，下次 env:install 将重新尝试'),
        ]);
    }

    /**
     * 后台尝试安装扩展（简化版本，仅 Windows 启用 DLL）
     */
    private function tryInstallExtensionFromBackend(string $ext): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: 检查 DLL 是否存在，尝试在 php.ini 中启用
            $extDir = \ini_get('extension_dir');
            $dllPath = $extDir . DIRECTORY_SEPARATOR . 'php_' . $ext . '.dll';
            if (!\file_exists($dllPath)) {
                return false;
            }

            $phpIniPath = \php_ini_loaded_file();
            if (!$phpIniPath || !\is_writable($phpIniPath)) {
                return false;
            }

            $content = \file_get_contents($phpIniPath);
            if ($content === false) {
                return false;
            }

            // 检查是否已启用
            if (\preg_match('/^\s*extension\s*=\s*(?:php_)?' . \preg_quote($ext, '/') . '(?:\.dll)?\s*$/mi', $content)) {
                return true;
            }

            // 取消注释或追加
            $pattern = '/^;(\s*extension\s*=\s*(?:php_)?' . \preg_quote($ext, '/') . '(?:\.dll)?\s*)$/mi';
            if (\preg_match($pattern, $content)) {
                $content = \preg_replace($pattern, '$1', $content);
            } else {
                $content .= "\nextension=" . $ext . "\n";
            }

            return \file_put_contents($phpIniPath, $content) !== false;
        }

        // Linux/macOS: 后台环境权限受限，建议通过 CLI
        return false;
    }

    /**
     * 获取扩展安装教程（区分系统）
     */
    private function getInstallGuide(string $ext): array
    {
        $guides = [];

        // Windows
        $guides['Windows'] = [
            __('从 https://pecl.php.net 或 https://windows.php.net/downloads/pecl/ 下载 php_%{ext}.dll', ['ext' => $ext]),
            __('放入 PHP 的 ext 目录: %{dir}', ['dir' => \ini_get('extension_dir')]),
            __('在 php.ini 中添加: extension=%{ext}', ['ext' => $ext]),
            __('重启 PHP 服务'),
        ];

        // Ubuntu/Debian
        $phpVer = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $guides['Ubuntu/Debian'] = [
            'sudo apt-get install -y php' . $phpVer . '-' . $ext,
            __('或: sudo apt-get install -y php-%{ext}', ['ext' => $ext]),
        ];

        // CentOS/RHEL
        $guides['CentOS/RHEL'] = [
            'sudo yum install -y php-' . $ext,
            __('或: sudo dnf install -y php-%{ext}', ['ext' => $ext]),
        ];

        // macOS
        $guides['macOS (Homebrew)'] = [
            'brew install php',
            'pecl install ' . $ext,
        ];

        // Docker
        $guides['Docker'] = [
            'docker-php-ext-install ' . $ext,
        ];

        return $guides;
    }
}
