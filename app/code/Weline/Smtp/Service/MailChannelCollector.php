<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Api\MailChannelProviderInterface;

/**
 * 从各模块 extends.php 收集 MailChannelProviderInterface 实现（对齐 TopicCollector）。
 */
class MailChannelCollector
{
    /**
     * @return list<array{code: string, name: string, description: string, module: string}>
     */
    public function collect(): array
    {
        $channels = [];
        foreach ($this->getProviders() as $provider) {
            $moduleName = $this->resolveModuleName($provider);
            foreach ($provider->getChannels() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string)($row['code'] ?? ''));
                if ($code === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $code)) {
                    continue;
                }
                $channels[$code] = [
                    'code' => $code,
                    'name' => (string)($row['name'] ?? $code),
                    'description' => (string)($row['description'] ?? ''),
                    'module' => (string)($row['module'] ?? $moduleName),
                ];
            }
        }
        ksort($channels);
        return array_values($channels);
    }

    /** @return MailChannelProviderInterface[] */
    private function getProviders(): array
    {
        $providers = [];
        foreach ($this->getProviderClassesFromExtends() as $implClass) {
            if (!is_string($implClass) || !class_exists($implClass)) {
                continue;
            }
            $instance = ObjectManager::getInstance($implClass);
            if ($instance instanceof MailChannelProviderInterface) {
                $providers[] = $instance;
            }
        }
        return $providers;
    }

    /** @return string[] */
    private function getProviderClassesFromExtends(): array
    {
        $interface = MailChannelProviderInterface::class;
        $merged = [];
        foreach (Env::getInstance()->getModuleList() as $module) {
            $basePath = $module['base_path'] ?? '';
            if ($basePath === '' || !($module['status'] ?? false)) {
                continue;
            }
            $extendsFile = rtrim((string)$basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'extends.php';
            if (!is_file($extendsFile)) {
                continue;
            }
            $config = include $extendsFile;
            if (!is_array($config) || !isset($config[$interface]) || !is_array($config[$interface])) {
                continue;
            }
            foreach ($config[$interface] as $implClass) {
                if (is_string($implClass)) {
                    $merged[] = $implClass;
                }
            }
        }
        return array_values(array_unique($merged));
    }

    private function resolveModuleName(MailChannelProviderInterface $provider): string
    {
        $class = $provider::class;
        if (preg_match('#^Weline\\\\([A-Za-z0-9_]+)\\\\#', $class, $m) === 1) {
            return 'Weline_' . $m[1];
        }
        return 'Unknown';
    }
}
