<?php
declare(strict_types=1);

namespace Weline\AppStore\Service;

use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\Framework\App\Exception;

class ModuleUninstallService
{
    private const INSTALL_RECORD_DIR = BP . 'var' . DS . 'appstore' . DS . 'install-records';

    private const PROTECTED_MODULES = [
        'Weline_AppStore',
    ];

    /**
     * Use the framework module removal command so database and file backups stay owned by the system uninstall flow.
     *
     * @throws Exception
     */
    public function uninstall(AppStoreInstalledModule $module): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $moduleName = trim($module->getModuleName());
        if ($moduleName === '') {
            throw new Exception(__('缺少模块名称'));
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*(?:_[A-Za-z][A-Za-z0-9]*)+$/', $moduleName)) {
            throw new Exception(__('模块名称不合法'));
        }
        if (in_array($moduleName, self::PROTECTED_MODULES, true)) {
            throw new Exception(__('App 商城模块不能在这里卸载'));
        }

        $version = $module->getVersion();
        $displayName = (string)($module->getDisplayName() ?: $moduleName);
        $platformModuleId = $module->getPlatformModuleId();
        $licenseKeyMasked = $this->maskSecret((string)$module->getLicenseKey());
        $command = $this->buildUninstallCommand($moduleName);
        $commandResult = $this->executeCommand($command);
        if (!$commandResult['success']) {
            throw new Exception(__('系统卸载失败：') . $commandResult['message']);
        }

        $module->delete();

        $recordPath = $this->appendUninstallRecord([
            'action' => 'uninstall',
            'module_name' => $moduleName,
            'version' => $version,
            'display_name' => $displayName,
            'platform_module_id' => $platformModuleId,
            'license_key_masked' => $licenseKeyMasked,
            'command' => $command,
            'output' => $commandResult['output'],
        ]);

        return [
            'success' => true,
            'message' => __('已通过系统卸载流程卸载模块，数据和文件备份由系统卸载流程自动完成。'),
            'module_name' => $moduleName,
            'version' => $version,
            'command' => $command,
            'output' => $this->summarizeOutput($commandResult['output']),
            'install_record_path' => $recordPath,
        ];
    }

    protected function buildUninstallCommand(string $moduleName): string
    {
        $basePath = rtrim(BP, "\\/");
        $binPath = $basePath . DS . 'bin' . DS . 'w';

        return 'cd /d ' . escapeshellarg($basePath)
            . ' && echo y | '
            . escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($binPath)
            . ' module:remove '
            . escapeshellarg($moduleName);
    }

    private function executeCommand(string $command): array
    {
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);

        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'return_code' => $returnCode,
            'message' => $returnCode === 0 ? '' : implode("\n", $output),
        ];
    }

    private function appendUninstallRecord(array $payload): string
    {
        $dir = self::INSTALL_RECORD_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir . DS . date('Y-m') . '.jsonl';
        $payload['recorded_at'] = date('Y-m-d H:i:s');
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

        return $path;
    }

    private function maskSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('*', max(0, strlen($value) - 8)) . substr($value, -4);
    }

    private function summarizeOutput(string $output): string
    {
        $clean = (string)preg_replace('/\e\[[0-9;]*m/', '', $output);
        if (strlen($clean) <= 4000) {
            return $clean;
        }

        return "... " . __('已省略前面的系统输出，仅显示最后一段。') . "\n" . substr($clean, -4000);
    }
}
