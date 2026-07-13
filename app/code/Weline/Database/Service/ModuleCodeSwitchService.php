<?php

declare(strict_types=1);

namespace Weline\Database\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\RuntimeDeploymentControlInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;

final class ModuleCodeSwitchService
{
    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviderResolver,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{module_name: string, target_path: string, old_path: string}>
     */
    public function switchAll(array $items, string $operationId): array
    {
        $prepared = [];
        $switched = [];
        $token = preg_replace('/[^A-Za-z0-9_.-]/', '-', $operationId) ?: 'rollback';
        try {
            foreach ($items as $item) {
                $moduleName = (string)($item['module_name'] ?? '');
                $info = Env::getInstance()->getModuleInfo($moduleName);
                $target = is_array($info) ? rtrim((string)($info['base_path'] ?? ''), '/\\') : '';
                $artifact = (array)($item['artifact'] ?? []);
                $source = rtrim((string)($artifact['path'] ?? ''), '/\\');
                if ($target === '' || !is_dir($target) || $source === '' || !is_dir($source)) {
                    throw new \RuntimeException(__('模块 %{1} 的代码切换路径无效', $moduleName));
                }
                $target = $this->assertModuleTarget($target);
                $stage = $target . '.__rollback_stage_' . $token;
                $old = $target . '.__rollback_old_' . $token;
                $this->recursiveDelete($stage);
                $this->recursiveDelete($old);
                $this->recursiveCopy($source, $stage);
                $actual = $this->directoryChecksum($stage);
                if ($actual === '' || !hash_equals((string)($artifact['checksum'] ?? ''), $actual)) {
                    throw new \RuntimeException(__('模块 %{1} 切换前的制品校验失败', $moduleName));
                }
                $prepared[] = ['module_name' => $moduleName, 'target_path' => $target, 'stage_path' => $stage, 'old_path' => $old];
            }

            foreach ($prepared as $entry) {
                if (!@rename($entry['target_path'], $entry['old_path'])) {
                    throw new \RuntimeException(__('无法备份模块当前代码: %{1}', $entry['module_name']));
                }
                if (!@rename($entry['stage_path'], $entry['target_path'])) {
                    @rename($entry['old_path'], $entry['target_path']);
                    throw new \RuntimeException(__('无法原子切换模块代码: %{1}', $entry['module_name']));
                }
                $switched[] = [
                    'module_name' => $entry['module_name'],
                    'target_path' => $entry['target_path'],
                    'old_path' => $entry['old_path'],
                ];
            }
            return $switched;
        } catch (\Throwable $e) {
            if ($switched !== []) {
                $this->restore($switched);
            }
            foreach ($prepared as $entry) {
                $this->recursiveDelete($entry['stage_path']);
            }
            throw $e;
        }
    }

    /** @param list<array{module_name: string, target_path: string, old_path: string}> $switches */
    public function restore(array $switches): void
    {
        foreach (array_reverse($switches) as $entry) {
            $target = $this->assertModuleTarget((string)$entry['target_path']);
            $old = (string)$entry['old_path'];
            if (!is_dir($old)) {
                throw new \RuntimeException(__('模块 %{1} 的原代码快照不存在', $entry['module_name']));
            }
            $old = $this->assertRollbackDirectory($old);
            if (!str_starts_with($old, $target . '.__rollback_old_')) {
                throw new \RuntimeException(__('模块 %{1} 的原代码快照路径不匹配', $entry['module_name']));
            }
            $failed = $target . '.__rollback_failed_' . bin2hex(random_bytes(4));
            if (is_dir($target) && !@rename($target, $failed)) {
                throw new \RuntimeException(__('无法移出模块 %{1} 的失败代码', $entry['module_name']));
            }
            if (!@rename($old, $target)) {
                if (is_dir($failed)) {
                    @rename($failed, $target);
                }
                throw new \RuntimeException(__('无法恢复模块 %{1} 的原代码', $entry['module_name']));
            }
            $this->recursiveDelete($failed);
        }
    }

    /** @param list<array{module_name: string, target_path: string, old_path: string}> $switches */
    public function cleanup(array $switches): void
    {
        foreach ($switches as $entry) {
            $target = $this->assertModuleTarget((string)$entry['target_path']);
            $old = (string)$entry['old_path'];
            if (is_dir($old)) {
                $old = $this->assertRollbackDirectory($old);
                if (!str_starts_with($old, $target . '.__rollback_old_')) {
                    throw new \RuntimeException(__('模块 %{1} 的待清理快照路径不匹配', $entry['module_name']));
                }
            }
            $this->recursiveDelete($old);
        }
    }

    /** @param list<string> $moduleNames */
    public function activate(array $moduleNames): void
    {
        $moduleNames = array_values(array_unique(array_filter(array_map('strval', $moduleNames))));
        $scriptPath = $this->temporaryScriptPath('activate');
        $bootstrap = BP . 'app' . DS . 'bootstrap.php';
        $code = <<<'PHP'
<?php
require __BOOTSTRAP__;
$moduleNames = __MODULE_NAMES__;
$env = \Weline\Framework\App\Env::getInstance();
foreach ($moduleNames as $moduleName) {
    $info = $env->getModuleInfo($moduleName);
    $registerFile = is_array($info) ? rtrim((string)($info['base_path'] ?? ''), '/\\') . DIRECTORY_SEPARATOR . 'register.php' : '';
    if ($registerFile !== '' && is_file($registerFile)) {
        require $registerFile;
    }
}
$modules = $env->getModuleList(true);
$previous = \Weline\Framework\Module\Handle::isDeferRegistryUpdate();
\Weline\Framework\Module\Handle::setDeferRegistryUpdate(true);
try {
    \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Module\Helper\Data::class)->updateModules($modules);
} finally {
    \Weline\Framework\Module\Handle::setDeferRegistryUpdate($previous);
}
$env->getModuleList(true);
\Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Registry\Service\RegistryUpdateService::class)->updateAllRegistries(true, false, true);
$routeService = \Weline\Framework\Manager\ObjectManager::make(\Weline\Framework\Router\Service\RouteUpdateService::class, [
    'moduleHandle' => \Weline\Framework\Manager\ObjectManager::make(\Weline\Framework\Module\Handle::class),
]);
$routeService->updateRoutes($moduleNames);
$frameworkCompiler = \Weline\Framework\Manager\ObjectManager::getInstance(
    \Weline\Framework\Compilation\FrameworkCompiler::class
);
$frameworkCompiler->compile(
    BP . 'app' . DS . 'code' . DS . 'Weline',
    BP . 'generated' . DS . 'framework'
);
$commandUpgrade = \Weline\Framework\Manager\ObjectManager::getInstance(
    \Weline\Framework\Console\Console\Command\Upgrade::class
);
$commandUpgrade->refreshForModules($moduleNames);
PHP;
        $code = str_replace(
            ['__BOOTSTRAP__', '__MODULE_NAMES__'],
            [var_export($bootstrap, true), var_export($moduleNames, true)],
            $code
        );
        $this->runTemporaryScript($scriptPath, $code);
    }

    public function setMaintenanceMode(bool $enabled): void
    {
        $provider = $this->runtimeDeploymentControl();
        if (!Env::getInstance()->setConfig('system.maintenance', $enabled)) {
            throw new \RuntimeException($enabled
                ? __('无法进入系统维护状态')
                : __('无法恢复系统维护状态'));
        }

        if ($provider === null) {
            return;
        }

        $this->assertRuntimeDispatch(
            $provider->setMaintenanceMode($enabled, null),
            $enabled ? __('启用 WLS 维护模式') : __('禁用 WLS 维护模式')
        );
    }

    public function reloadWls(): void
    {
        $provider = $this->runtimeDeploymentControl();
        if ($provider === null) {
            return;
        }

        $this->assertRuntimeDispatch($provider->reloadCode(null), __('WLS 代码重载'));
    }

    public function assertRuntimeControlReady(): void
    {
        $this->runtimeDeploymentControl();
    }

    /** @param list<string> $moduleNames @return list<array<string, mixed>> */
    public function schemaDiff(array $moduleNames): array
    {
        $scriptPath = $this->temporaryScriptPath('schema-check');
        $bootstrap = BP . 'app' . DS . 'bootstrap.php';
        $code = <<<'PHP'
<?php
require __BOOTSTRAP__;
$moduleNames = array_fill_keys(__MODULE_NAMES__, true);
$stage = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Setup\Stage\SchemaDiffStage::class);
$stage->prepare([]);
$result = [];
foreach ($stage->getDiffOps() as $op) {
    $parts = explode('\\', (string)$op->modelClass);
    $moduleName = ($parts[0] ?? '') . (isset($parts[1]) ? '_' . $parts[1] : '');
    if (!isset($moduleNames[$moduleName])) {
        continue;
    }
    $result[] = [
        'module_name' => $moduleName,
        'model_class' => $op->modelClass,
        'table_name' => $op->tableName,
        'kind' => $op->kind,
    ];
}
echo "__WELINE_SCHEMA_DIFF__" . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
PHP;
        $code = str_replace(
            ['__BOOTSTRAP__', '__MODULE_NAMES__'],
            [var_export($bootstrap, true), var_export(array_values($moduleNames), true)],
            $code
        );
        if (file_put_contents($scriptPath, $code, LOCK_EX) === false) {
            throw new \RuntimeException(__('无法创建 Schema 只读检查脚本'));
        }
        try {
            [$exitCode, $output] = $this->runCommand([PHP_BINARY, $scriptPath], false);
        } finally {
            $this->deleteTemporaryScript($scriptPath);
        }
        if ($exitCode !== 0) {
            throw new \RuntimeException(__('Schema 只读检查执行失败: %{1}', implode("\n", $output)));
        }
        foreach ($output as $line) {
            if (str_starts_with($line, '__WELINE_SCHEMA_DIFF__')) {
                $result = json_decode(substr($line, strlen('__WELINE_SCHEMA_DIFF__')), true);
                return is_array($result) ? $result : [];
            }
        }
        throw new \RuntimeException(__('Schema 只读检查未返回可验证结果'));
    }

    private function runTemporaryScript(string $path, string $code): void
    {
        if (file_put_contents($path, $code, LOCK_EX) === false) {
            throw new \RuntimeException(__('无法创建模块激活脚本'));
        }
        try {
            [$exitCode, $output] = $this->runCommand([PHP_BINARY, $path], false);
        } finally {
            $this->deleteTemporaryScript($path);
        }
        if ($exitCode !== 0) {
            throw new \RuntimeException(__('模块注册、路由或命令刷新失败: %{1}', implode("\n", $output)));
        }
    }

    /** @param array<string, mixed> $result */
    private function assertRuntimeDispatch(array $result, string $action): void
    {
        $attempted = array_values((array)($result['attempted'] ?? []));
        $failed = (array)($result['failed_by_instance'] ?? []);
        $skipped = (array)($result['skipped_by_instance'] ?? []);
        if ($attempted === [] && $failed === [] && $skipped === []) {
            return;
        }
        if (!empty($result['success']) && $failed === [] && $skipped === []) {
            return;
        }

        throw new \RuntimeException(__('%{1}未在全部 WLS 实例上完成: %{2}', [
            $action,
            (string)($result['message'] ?? 'unknown'),
        ]));
    }

    private function runtimeDeploymentControl(): ?RuntimeDeploymentControlInterface
    {
        $provider = $this->runtimeProviderResolver->resolve(RuntimeDeploymentControlInterface::class);
        if ($provider instanceof RuntimeDeploymentControlInterface) {
            return $provider;
        }

        try {
            $server = Env::getInstance()->getModuleInfo('Weline_Server');
        } catch (\Throwable) {
            $server = null;
        }
        if (is_array($server) && !empty($server['status'])) {
            throw new \RuntimeException(__(
                'Weline_Server 已启用但未发布运行时部署控制能力，请先执行 framework:compile'
            ));
        }

        return null;
    }

    /** @return array{0: int, 1: list<string>} */
    private function runCommand(array $arguments, bool $throw = true): array
    {
        $arguments = array_values(array_map('strval', $arguments));
        if ($arguments === [] || $arguments[0] === '') {
            throw new \InvalidArgumentException(__('命令参数不能为空'));
        }

        $pipes = [];
        // nosemgrep: php.lang.security.exec-use.exec-use -- proc_open receives an argv array and bypass_shell=true; no command string is evaluated.
        $process = proc_open(
            $arguments,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['redirect', 1],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException(__('无法启动模块回滚子进程'));
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $outputText = isset($pipes[1]) && is_resource($pipes[1])
            ? (string)stream_get_contents($pipes[1])
            : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        $exitCode = proc_close($process);
        $output = $outputText === ''
            ? []
            : (preg_split('/\R/', rtrim($outputText, "\r\n")) ?: []);
        if ($throw && $exitCode !== 0) {
            throw new \RuntimeException(__('命令执行失败: %{1}', implode("\n", $output)));
        }
        return [$exitCode, $output];
    }

    private function temporaryScriptPath(string $purpose): string
    {
        $directory = BP . 'var' . DS . 'database' . DS . 'rollback-runtime';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(__('无法创建回滚运行时目录'));
        }
        return $directory . DS . $purpose . '-' . bin2hex(random_bytes(8)) . '.php';
    }

    private function deleteTemporaryScript(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $root = realpath(BP . 'var' . DS . 'database' . DS . 'rollback-runtime');
        $resolved = realpath($path);
        if ($root === false
            || $resolved === false
            || !str_starts_with($resolved, rtrim($root, '/\\') . DS)
            || preg_match('/^(?:activate|schema-check)-[a-f0-9]{16}\.php$/', basename($resolved)) !== 1
        ) {
            throw new \RuntimeException(__('拒绝删除非受管回滚运行时脚本: %{1}', $path));
        }
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath and filename are restricted to rollback-runtime.
        if (!@unlink($resolved) && is_file($resolved)) {
            throw new \RuntimeException(__('无法清理模块回滚运行时脚本: %{1}', $resolved));
        }
    }

    private function assertModuleTarget(string $path): string
    {
        $root = realpath(BP . 'app' . DS . 'code');
        $resolved = realpath($path);
        if ($root === false
            || $resolved === false
            || !is_dir($resolved)
            || !str_starts_with($resolved, rtrim($root, '/\\') . DS)
            || rtrim($path, '/\\') !== $resolved
        ) {
            throw new \RuntimeException(__('模块代码目录不在可原子切换的 app/code 路径下: %{1}', $path));
        }
        return $resolved;
    }

    private function assertRollbackDirectory(string $path): string
    {
        $root = realpath(BP . 'app' . DS . 'code');
        $resolved = realpath($path);
        if ($root === false
            || $resolved === false
            || !is_dir($resolved)
            || !str_starts_with($resolved, rtrim($root, '/\\') . DS)
            || preg_match('/\.__rollback_(?:stage|old|failed)_[A-Za-z0-9_.-]+$/', basename($resolved)) !== 1
        ) {
            throw new \RuntimeException(__('拒绝访问非受管模块回滚目录: %{1}', $path));
        }
        return $resolved;
    }

    private function directoryChecksum(string $directory): string
    {
        if (!is_dir($directory)) {
            return '';
        }
        $directory = rtrim($directory, '/\\');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                return '';
            }
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        return hash('sha256', (string)json_encode($files, JSON_UNESCAPED_SLASHES));
    }

    private function recursiveCopy(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            throw new \RuntimeException(__('无法创建代码暂存目录: %{1}', $target));
        }
        foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException(__('模块代码不允许符号链接: %{1}', $item->getPathname()));
            }
            $destination = $target . DS . $item->getBasename();
            $item->isDir()
                ? $this->recursiveCopy($item->getPathname(), $destination)
                : (copy($item->getPathname(), $destination) ?: throw new \RuntimeException(__('复制代码文件失败: %{1}', $item->getPathname())));
        }
    }

    private function recursiveDelete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $resolved = $this->assertRollbackDirectory($path);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
                continue;
            }
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- the parent is a validated app/code rollback directory and links are not followed.
            @unlink($item->getPathname());
        }
        @rmdir($resolved);
    }
}
