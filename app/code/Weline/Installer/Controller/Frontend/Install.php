<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Installer\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Env\Api\EnvCheckerInterface;
use Weline\Framework\Env\Api\EnvRequirementsCollectorInterface;
use Weline\Framework\Env\Service\EnvChecker;
use Weline\Framework\Env\Service\EnvRequirementsCollector;
use Weline\Framework\Ui\FormKey;

/**
 * Web 安装器控制器
 * 
 * @DESC 提供 Web 界面的系统安装功能，包括环境检测、数据库配置、系统初始化
 */
class Install extends FrontendController
{
    private EnvRequirementsCollectorInterface $collector;
    private EnvCheckerInterface $checker;
    private FormKey $formKey;

    public function __construct()
    {
        $this->collector = ObjectManager::getInstance(EnvRequirementsCollector::class);
        $this->checker = ObjectManager::getInstance(EnvChecker::class);
        $this->formKey = ObjectManager::getInstance(FormKey::class);
    }

    /**
     * 检查是否允许访问安装器
     * 
     * @return bool
     */
    private function canAccess(): bool
    {
        // 如果 install.lock 存在，则系统已安装，禁止访问
        $installLock = BP . 'setup/install.lock';
        return !file_exists($installLock);
    }

    /**
     * 显示已安装页面
     */
    private function showInstalledPage()
    {
        $this->assign('title', __('系统已安装'));
        $this->assign('message', __('系统已经安装完成，安装器已禁用。'));
        return $this->fetch('installed');
    }

    /**
     * 安装器首页
     */
    public function index()
    {
        if (!$this->canAccess()) {
            return $this->showInstalledPage();
        }

        $this->assign('title', __('系统安装'));
        $this->assign('form_key', $this->formKey->getFormKey());
        $this->assign('steps', [
            ['id' => 'env', 'name' => __('环境检测'), 'icon' => 'check-circle'],
            ['id' => 'db', 'name' => __('数据库配置'), 'icon' => 'database'],
            ['id' => 'admin', 'name' => __('管理员设置'), 'icon' => 'user'],
            ['id' => 'complete', 'name' => __('安装完成'), 'icon' => 'flag'],
        ]);
        
        return $this->fetch();
    }

    /**
     * 环境检测 API（JSON）
     */
    public function getEnvCheck()
    {
        if (!$this->canAccess()) {
            return $this->fetchJson(['code' => 403, 'msg' => __('安装器已禁用')]);
        }

        try {
            // 收集环境需求
            $requirements = $this->collector->collect();
            
            // 执行检测
            $this->checker->setRequirements($requirements);
            $result = $this->checker->check();

            return $this->fetchJson([
                'code' => 200,
                'data' => [
                    'passed' => !$result->hasError(),
                    'result' => $result->toArray(),
                    'requirements' => $requirements->toArray(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('环境检测失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 环境修复 API（JSON）
     */
    public function postEnvInstall()
    {
        if (!$this->canAccess()) {
            return $this->fetchJson(['code' => 403, 'msg' => __('安装器已禁用')]);
        }

        // 验证 CSRF
        if (!$this->formKey->validate($this->request->getParam('form_key'))) {
            return $this->fetchJson(['code' => 403, 'msg' => __('无效的表单密钥')]);
        }

        try {
            // 调用 env:install 的逻辑
            $envInstall = ObjectManager::getInstance(\Weline\Framework\Env\Console\Env\Install::class);
            
            // 重定向到 SSE 流式接口
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('请使用 SSE 接口获取安装进度'),
                'sse_url' => $this->getUrl('install/envInstallStream'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('环境修复失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 环境修复 SSE 流式接口
     */
    public function getEnvInstallStream()
    {
        if (!$this->canAccess()) {
            header('Content-Type: text/event-stream');
            echo "data: " . json_encode(['type' => 'error', 'msg' => __('安装器已禁用')]) . "\n\n";
            return;
        }

        // 设置 SSE 头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // 禁用输出缓冲
        while (ob_get_level()) {
            ob_end_flush();
        }

        $this->sendSseMessage('start', ['msg' => __('开始环境修复...')]);

        try {
            // 收集环境需求
            $this->sendSseMessage('step', ['step' => 'collect', 'msg' => __('正在收集环境需求...')]);
            $requirements = $this->collector->collect();

            // 执行检测
            $this->sendSseMessage('step', ['step' => 'check', 'msg' => __('正在检测环境...')]);
            $this->checker->setRequirements($requirements);
            $result = $this->checker->check();

            if (!$result->hasError()) {
                $this->sendSseMessage('success', ['msg' => __('环境检测通过，无需修复')]);
                $this->sendSseMessage('complete', ['success' => true]);
                return;
            }

            // 处理禁用的函数
            $disabled = $result->getDisabledFunctions();
            if (!empty($disabled)) {
                $this->sendSseMessage('step', ['step' => 'functions', 'msg' => __('尝试解禁函数: %{funcs}', ['funcs' => implode(', ', $disabled)])]);
                $success = $this->tryUnblockFunctions($disabled);
                if ($success) {
                    $this->sendSseMessage('success', ['msg' => __('函数解禁成功')]);
                } else {
                    $phpIniPath = php_ini_loaded_file() ?: __('未知');
                    $this->sendSseMessage('warning', [
                        'msg' => __('函数解禁失败'),
                        'guide' => [
                            'location' => $phpIniPath,
                            'action' => __('从 disable_functions 中移除: %{funcs}', ['funcs' => implode(', ', $disabled)]),
                            'verify' => __('重启 PHP 后刷新页面'),
                        ],
                    ]);
                }
            }

            // 处理缺失的扩展
            $missing = $result->getMissingExtensions();
            if (!empty($missing)) {
                foreach ($missing as $ext) {
                    $this->sendSseMessage('warning', [
                        'msg' => __('扩展 %{ext} 需要手动安装', ['ext' => $ext]),
                        'guide' => $this->getExtensionInstallGuide($ext),
                    ]);
                }
            }

            // 处理未满足的 items
            $unsatisfied = $result->getUnsatisfiedItems();
            if (!empty($unsatisfied)) {
                $executor = $this->getScriptExecutor();
                foreach ($unsatisfied as $item) {
                    $name = $item['name'] ?? __('未命名');
                    $modulePath = $item['module_path'] ?? '';

                    if (empty($modulePath)) {
                        $this->sendSseMessage('warning', ['msg' => __('跳过 %{name}（无模块路径）', ['name' => $name])]);
                        continue;
                    }

                    $this->sendSseMessage('step', ['step' => 'item', 'msg' => __('正在安装: %{name}', ['name' => $name])]);

                    $envDir = $modulePath . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR;
                    $execResult = $executor->execute(
                        $modulePath,
                        $item,
                        $envDir,
                        \Weline\Framework\Env\Api\InstallScriptExecutorInterface::ACTION_INSTALL
                    );

                    if ($execResult->isSuccess()) {
                        $this->sendSseMessage('success', ['msg' => __('%{name} 安装成功', ['name' => $name])]);
                    } else {
                        $this->sendSseMessage('error', [
                            'msg' => __('%{name} 安装失败', ['name' => $name]),
                            'error' => $execResult->getErrorOutput(),
                            'guide' => [
                                'description' => $item['description'] ?? '',
                            ],
                        ]);
                    }
                }
            }

            // 重新检测
            $this->sendSseMessage('step', ['step' => 'recheck', 'msg' => __('重新检测环境...')]);
            $requirements = $this->collector->collect();
            $this->checker->setRequirements($requirements);
            $result = $this->checker->check();

            if ($result->hasError()) {
                $this->sendSseMessage('complete', [
                    'success' => false,
                    'msg' => __('部分问题仍未修复，请手动处理'),
                    'result' => $result->toArray(),
                ]);
            } else {
                $this->sendSseMessage('complete', [
                    'success' => true,
                    'msg' => __('环境修复完成'),
                ]);
            }

        } catch (\Exception $e) {
            $this->sendSseMessage('error', ['msg' => $e->getMessage()]);
            $this->sendSseMessage('complete', ['success' => false, 'msg' => __('修复过程出错')]);
        }
    }

    /**
     * 数据库配置页面数据
     */
    public function getDbConfig()
    {
        if (!$this->canAccess()) {
            return $this->fetchJson(['code' => 403, 'msg' => __('安装器已禁用')]);
        }

        return $this->fetchJson([
            'code' => 200,
            'data' => [
                'db_types' => [
                    ['value' => 'mysql', 'label' => 'MySQL'],
                    ['value' => 'pgsql', 'label' => 'PostgreSQL'],
                    ['value' => 'sqlite', 'label' => 'SQLite'],
                ],
                'default' => [
                    'type' => 'mysql',
                    'hostname' => 'localhost',
                    'hostport' => '3306',
                    'database' => 'weline',
                    'username' => 'root',
                    'prefix' => 'w_',
                    'charset' => 'utf8mb4',
                ],
            ],
        ]);
    }

    /**
     * 保存数据库配置
     */
    public function postDbConfig()
    {
        if (!$this->canAccess()) {
            return $this->fetchJson(['code' => 403, 'msg' => __('安装器已禁用')]);
        }

        // 验证 CSRF
        if (!$this->formKey->validate($this->request->getParam('form_key'))) {
            return $this->fetchJson(['code' => 403, 'msg' => __('无效的表单密钥')]);
        }

        try {
            $params = $this->request->getParams();
            
            // 测试数据库连接
            $testResult = $this->testDbConnection($params);
            if (!$testResult['success']) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('数据库连接失败：%{error}', ['error' => $testResult['error']]),
                ]);
            }

            // 保存数据库配置
            $dbConfig = [
                'master' => [
                    'type' => $params['type'] ?? 'mysql',
                    'hostname' => $params['hostname'] ?? 'localhost',
                    'hostport' => $params['hostport'] ?? '3306',
                    'database' => $params['database'] ?? 'weline',
                    'username' => $params['username'] ?? 'root',
                    'password' => $params['password'] ?? '',
                    'prefix' => $params['prefix'] ?? 'w_',
                    'charset' => $params['charset'] ?? 'utf8mb4',
                    'collate' => 'utf8mb4_general_ci',
                ],
                'slaves' => [],
            ];

            // SQLite 特殊处理
            if ($params['type'] === 'sqlite') {
                $dbConfig['master'] = [
                    'type' => 'sqlite',
                    'path' => $params['sqlite_path'] ?? (APP_PATH . 'etc/db.sqlite'),
                    'prefix' => $params['prefix'] ?? 'w_',
                    'charset' => 'utf8mb4',
                    'collate' => 'utf8mb4_general_ci',
                ];
            }

            Env::set('db', $dbConfig);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('数据库配置已保存'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('保存配置失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 保存管理员配置并完成安装
     */
    public function postComplete()
    {
        if (!$this->canAccess()) {
            return $this->fetchJson(['code' => 403, 'msg' => __('安装器已禁用')]);
        }

        // 验证 CSRF
        if (!$this->formKey->validate($this->request->getParam('form_key'))) {
            return $this->fetchJson(['code' => 403, 'msg' => __('无效的表单密钥')]);
        }

        try {
            $params = $this->request->getParams();

            // 生成后台入口密钥
            $adminKey = $params['admin_key'] ?? \Weline\Framework\System\Text::random_string(32);
            $apiAdminKey = $params['api_admin_key'] ?? \Weline\Framework\System\Text::random_string(32);

            Env::set('admin', $adminKey);
            Env::set('api_admin', $apiAdminKey);
            Env::set('user', Env::user());

            // 创建 install.lock
            $installLockPath = BP . 'setup/install.lock';
            $setupDir = dirname($installLockPath);
            if (!is_dir($setupDir)) {
                mkdir($setupDir, 0755, true);
            }
            file_put_contents($installLockPath, date('Y-m-d H:i:s'));

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('安装完成'),
                'data' => [
                    'admin_key' => $adminKey,
                    'api_admin_key' => $apiAdminKey,
                    'admin_url' => $this->getUrl($adminKey . '/login'),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('安装失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * 发送 SSE 消息
     */
    private function sendSseMessage(string $type, array $data): void
    {
        $message = json_encode([
            't' => date('c'),
            'type' => $type,
            ...$data,
        ], JSON_UNESCAPED_UNICODE);
        
        echo "data: {$message}\n\n";
        flush();
    }

    /**
     * 尝试解禁函数
     */
    private function tryUnblockFunctions(array $functions): bool
    {
        $phpIniPath = php_ini_loaded_file();
        if (!$phpIniPath || !is_writable($phpIniPath)) {
            return false;
        }

        $content = file_get_contents($phpIniPath);
        if ($content === false) {
            return false;
        }

        $pattern = '/^(disable_functions\s*=\s*)(.*)$/m';
        if (!preg_match($pattern, $content, $matches)) {
            return false;
        }

        $currentDisabled = array_map('trim', explode(',', $matches[2]));
        $newDisabled = array_diff($currentDisabled, $functions);
        $newLine = 'disable_functions = ' . implode(',', array_filter($newDisabled));

        $newContent = preg_replace($pattern, $newLine, $content);
        if ($newContent === null) {
            return false;
        }

        return file_put_contents($phpIniPath, $newContent) !== false;
    }

    /**
     * 获取扩展安装指南
     */
    private function getExtensionInstallGuide(string $ext): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'platform' => 'Windows',
                'steps' => [
                    __('从 https://pecl.php.net 下载 php_%{ext}.dll', ['ext' => $ext]),
                    __('放入 PHP 的 ext 目录'),
                    __('在 php.ini 中添加 extension=%{ext}', ['ext' => $ext]),
                    __('重启 Web 服务'),
                ],
            ];
        } else {
            return [
                'platform' => 'Linux/macOS',
                'steps' => [
                    __('Ubuntu/Debian: sudo apt install php-%{ext}', ['ext' => $ext]),
                    __('CentOS/RHEL: sudo yum install php-%{ext}', ['ext' => $ext]),
                    __('macOS: brew install php && pecl install %{ext}', ['ext' => $ext]),
                    __('重启 PHP-FPM 或 Web 服务'),
                ],
            ];
        }
    }

    /**
     * 获取脚本执行器
     */
    private function getScriptExecutor(): \Weline\Framework\Env\Api\InstallScriptExecutorInterface
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ObjectManager::getInstance(\Weline\Framework\Env\Service\WindowsScriptExecutor::class);
        } else {
            return ObjectManager::getInstance(\Weline\Framework\Env\Service\LinuxScriptExecutor::class);
        }
    }

    /**
     * 测试数据库连接
     */
    private function testDbConnection(array $params): array
    {
        try {
            $type = $params['type'] ?? 'mysql';

            if ($type === 'sqlite') {
                $path = $params['sqlite_path'] ?? (APP_PATH . 'etc/db.sqlite');
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $pdo = new \PDO("sqlite:{$path}");
                return ['success' => true];
            }

            $dsn = "{$type}:host={$params['hostname']};port={$params['hostport']}";
            if (!empty($params['database'])) {
                $dsn .= ";dbname={$params['database']}";
            }

            $pdo = new \PDO($dsn, $params['username'], $params['password']);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return ['success' => true];
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
