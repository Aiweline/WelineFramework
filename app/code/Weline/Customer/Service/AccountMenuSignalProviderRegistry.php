<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Api\AccountMenuSignalProviderInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

/**
 * Discovers AccountMenuSignalProviderInterface via Customer extends registry.
 */
final class AccountMenuSignalProviderRegistry
{
    private const EXTENDS_PREFIX = 'extends/module/weline_customer/accountmenusignalprovider/';

    /** @var array<string, AccountMenuSignalProviderInterface> */
    private array $byCode = [];

    private bool $extendsLoaded = false;

    public function __construct(
        private readonly bool $autoLoadExtends = true,
    ) {
    }

    /**
     * @param list<AccountMenuSignalProviderInterface> $providers
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

    public function register(AccountMenuSignalProviderInterface $provider): void
    {
        $code = strtolower(trim($provider->code()));
        if ($code === '' || preg_match('/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)*$/', $code) !== 1) {
            throw new \InvalidArgumentException('Account menu signal provider code is invalid');
        }
        if (isset($this->byCode[$code])) {
            throw new \InvalidArgumentException(sprintf(
                'Duplicate account menu signal provider code: %s',
                $code,
            ));
        }
        $this->byCode[$code] = $provider;
    }

    /**
     * @return list<AccountMenuSignalProviderInterface>
     */
    public function all(): array
    {
        $this->boot();
        $providers = array_values($this->byCode);
        usort(
            $providers,
            static fn(
                AccountMenuSignalProviderInterface $left,
                AccountMenuSignalProviderInterface $right,
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
        foreach (ExtendsData::getExtendedBy('Weline_Customer') as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $relativePath = strtolower(str_replace('\\', '/', (string)($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, self::EXTENDS_PREFIX)) {
                    continue;
                }
                $className = $this->extensionClass((string)$sourceModule, $extension);
                if ($className === '' || !is_subclass_of($className, AccountMenuSignalProviderInterface::class, true)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                    if ($instance instanceof AccountMenuSignalProviderInterface) {
                        $this->register($instance);
                    }
                } catch (\Throwable $e) {
                    if (function_exists('w_log_error')) {
                        w_log_error('Account menu signal provider load failed: ' . $className . ' ' . $e->getMessage());
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
