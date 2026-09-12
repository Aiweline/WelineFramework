<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipProviderInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;

/**
 * Scans DropshipProvider controller* methods for contract / docs.
 */
final class DropshipProviderControllerRouteScanner
{
    /** @var list<string> */
    public const FORBIDDEN_CONTROLLER_METHODS = [
        'controllerNotify',
        'controllerCallback',
        'controllerWebhook',
    ];

    /**
     * @return list<array{provider_code:string,action:string,method_name:string,class:string,route:string}>
     */
    public function scan(bool $forceReload = false): array
    {
        static $cache = null;
        if (!$forceReload && $cache !== null) {
            return $cache;
        }

        $routes = [];
        $extendedBy = ExtendsData::getExtendedBy('Weline_Dropship', $forceReload);
        $modules = Env::getInstance()->getModuleList();

        foreach ($extendedBy as $sourceModule => $extensions) {
            $sourceModuleInfo = $modules[$sourceModule] ?? null;
            if (empty($sourceModuleInfo) || !($sourceModuleInfo['status'] ?? false)) {
                continue;
            }

            foreach ((array) $extensions as $extension) {
                $relativePath = (string) ($extension['relative_path'] ?? '');
                if (!str_starts_with($relativePath, 'extends/module/Weline_Dropship/DropshipProvider/')) {
                    continue;
                }

                $sourceFile = (string) ($extension['source_file'] ?? '');
                if ($sourceFile === '' || !is_file($sourceFile)) {
                    continue;
                }

                require_once $sourceFile;
                $className = $this->classNameFromFile($sourceFile);
                if ($className === '' || !class_exists($className)) {
                    continue;
                }

                try {
                    $reflection = new \ReflectionClass($className);
                    if (!$reflection->implementsInterface(DropshipProviderInterface::class)) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }

                $provider = $reflection->newInstanceWithoutConstructor();
                if (!method_exists($provider, 'getCode')) {
                    continue;
                }
                $providerCode = strtolower(trim((string) $provider->getCode()));
                if ($providerCode === '') {
                    continue;
                }

                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    $methodName = $method->getName();
                    if (!str_starts_with($methodName, 'controller') || $methodName === 'controller') {
                        continue;
                    }
                    if (\in_array($methodName, self::FORBIDDEN_CONTROLLER_METHODS, true)) {
                        continue;
                    }

                    $action = $this->actionFromMethodName($methodName);
                    $routes[] = [
                        'provider_code' => $providerCode,
                        'action' => $action,
                        'method_name' => $methodName,
                        'class' => $className,
                        'route' => sprintf(
                            'dropship/frontend/provider-gateway/dispatch?provider_code=%s&action=%s',
                            $providerCode,
                            $action
                        ),
                    ];
                }
            }
        }

        $cache = $routes;

        return $routes;
    }

    private function classNameFromFile(string $sourceFile): string
    {
        $contents = (string) file_get_contents($sourceFile);
        if (preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch) !== 1) {
            return '';
        }
        if (preg_match('/\bfinal\s+class\s+(\w+)/', $contents, $classMatch) !== 1
            && preg_match('/\bclass\s+(\w+)/', $contents, $classMatch) !== 1) {
            return '';
        }

        return trim($namespaceMatch[1]) . '\\' . trim($classMatch[1]);
    }

    private function actionFromMethodName(string $methodName): string
    {
        $suffix = substr($methodName, strlen('controller'));
        $kebab = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $suffix) ?? $suffix);

        return trim($kebab, '-');
    }
}
