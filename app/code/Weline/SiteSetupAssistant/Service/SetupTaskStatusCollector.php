<?php

declare(strict_types=1);

namespace Weline\SiteSetupAssistant\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\SiteSetupAssistant\Api\SetupTaskStatusProviderInterface;

/**
 * 收集各模块 Extends 的建站任务状态覆盖。
 */
class SetupTaskStatusCollector
{
    /**
     * @param array{website_id?:int,website_code?:string,storage_scope?:string} $context
     * @return array<string, array{code:string,status:string,tip?:string,title?:string,href?:string,meta?:array}>
     */
    public function collectByCode(array $context = []): array
    {
        $byCode = [];
        foreach ($this->getProviders() as $provider) {
            foreach ($provider->resolveTaskStatus($context) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string)($row['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $status = (string)($row['status'] ?? 'todo');
                if (!in_array($status, ['todo', 'doing', 'done'], true)) {
                    $status = 'todo';
                }
                $row['code'] = $code;
                $row['status'] = $status;
                $byCode[$code] = $row;
            }
        }

        return $byCode;
    }

    /** @return SetupTaskStatusProviderInterface[] */
    private function getProviders(): array
    {
        $providers = [];
        foreach ($this->getProviderClassesFromExtends() as $implClass) {
            if (!is_string($implClass) || !class_exists($implClass)) {
                continue;
            }
            $instance = ObjectManager::getInstance($implClass);
            if ($instance instanceof SetupTaskStatusProviderInterface) {
                $providers[] = $instance;
            }
        }
        return $providers;
    }

    /** @return string[] */
    private function getProviderClassesFromExtends(): array
    {
        $interface = SetupTaskStatusProviderInterface::class;
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
}
