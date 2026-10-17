<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Admin\Api\BackendMenuSignalProviderInterface;
use Weline\Framework\Authorization\Resource\SourceIdParser;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

/**
 * Discovers BackendMenuSignalProviderInterface via Admin extends registry.
 *
 * Codes use ACL source shape (Vendor_Module::name), not AccountMenuSignal dot-codes.
 */
final class BackendMenuSignalProviderRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_admin/backendmenusignalprovider/';

    /** @var array<string, BackendMenuSignalProviderInterface> */
    private array $byCode = [];

    private bool $extendsLoaded = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
    ) {
    }

    /**
     * @param list<BackendMenuSignalProviderInterface> $providers
     */
    public static function forTesting(array $providers = []): self
    {
        $reg = new self(autoLoadExtends: false);
        foreach ($providers as $provider) {
            $reg->register($provider);
        }
        $reg->extendsLoaded = true;

        return $reg;
    }

    public function register(BackendMenuSignalProviderInterface $provider): void
    {
        $code = trim($provider->code());
        if ($code === '' || SourceIdParser::parse($code) === null) {
            throw new \InvalidArgumentException('Backend menu signal provider code is invalid');
        }
        if (isset($this->byCode[$code])) {
            throw new \InvalidArgumentException(sprintf(
                'Duplicate backend menu signal provider code: %s',
                $code,
            ));
        }
        $this->byCode[$code] = $provider;
    }

    /**
     * @return list<BackendMenuSignalProviderInterface>
     */
    public function all(): array
    {
        $this->boot();
        $providers = array_values($this->byCode);
        usort(
            $providers,
            static fn(
                BackendMenuSignalProviderInterface $left,
                BackendMenuSignalProviderInterface $right,
            ): int => strcmp($left->code(), $right->code()),
        );

        return $providers;
    }

    private function boot(): void
    {
        if ($this->extendsLoaded || !$this->autoLoadExtends) {
            return;
        }
        $this->extendsLoaded = true;
        if (!class_exists(ExtendsData::class)) {
            return;
        }
        foreach (ExtendsData::getExtendedBy('Weline_Admin') as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }
                $className = $this->extensionClass((string)$sourceModule, $extension);
                if ($className === '' || !is_subclass_of($className, BackendMenuSignalProviderInterface::class, true)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                    if ($instance instanceof BackendMenuSignalProviderInterface) {
                        $this->register($instance);
                    }
                } catch (\Throwable $e) {
                    if (function_exists('w_log_error')) {
                        w_log_error('Backend menu signal provider load failed: ' . $className . ' ' . $e->getMessage());
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $extension */
    private function extensionClass(string $sourceModule, array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $className = trim((string)($extension[$key] ?? ''));
            if ($className !== '') {
                return ltrim($className, '\\');
            }
        }

        $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
        if (!str_starts_with(strtolower($relativePath), 'extends/module/')) {
            return '';
        }
        $classPath = substr($relativePath, strlen('extends/module/'));
        if (!str_ends_with(strtolower($classPath), '.php')) {
            return '';
        }
        $classPath = substr($classPath, 0, -4);
        $moduleNamespace = str_replace('_', '\\', trim($sourceModule));
        if ($moduleNamespace === '' || $classPath === '') {
            return '';
        }

        return $moduleNamespace . '\\Extends\\Module\\' . str_replace('/', '\\', $classPath);
    }
}
