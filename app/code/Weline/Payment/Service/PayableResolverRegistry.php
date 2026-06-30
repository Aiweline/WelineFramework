<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Interface\PayableResolverInterface;

class PayableResolverRegistry
{
    public const EXTENSION_PATH = 'extends/module/Weline_Payment/PayableResolver/';
    public const DEFAULT_PAYABLE_TYPE = DefaultPayableResolver::PAYABLE_TYPE;

    private ObjectManager $objectManager;
    private PayableResolverInterface $defaultResolver;

    /**
     * @var array<string, PayableResolverInterface>
     */
    private array $manualResolvers = [];

    /**
     * @var array<string, PayableResolverInterface>|null
     */
    private ?array $cachedResolvers = null;
    private ?int $cachedExtendsMtime = null;

    public function __construct(
        ?ObjectManager $objectManager = null,
        ?DefaultPayableResolver $defaultResolver = null
    ) {
        $this->objectManager = $objectManager ?? ObjectManager::getInstance();
        $this->defaultResolver = $defaultResolver ?? $this->objectManager->getInstance(DefaultPayableResolver::class);
    }

    public function register(PayableResolverInterface $resolver): void
    {
        $payableType = $this->normalizePayableType($resolver->getPayableType());
        if ($payableType === self::DEFAULT_PAYABLE_TYPE) {
            throw new \InvalidArgumentException('payment_default_payable_resolver_is_reserved');
        }

        $this->manualResolvers[$payableType] = $resolver;
        $this->cachedResolvers = null;
    }

    public function has(string $payableType, bool $forceReload = false): bool
    {
        return isset($this->getResolvers($forceReload)[$this->normalizePayableType($payableType)]);
    }

    public function getResolver(string $payableType = self::DEFAULT_PAYABLE_TYPE, bool $forceReload = false): PayableResolverInterface
    {
        $payableType = $this->normalizePayableType($payableType);
        $resolvers = $this->getResolvers($forceReload);

        if (!isset($resolvers[$payableType])) {
            throw new \InvalidArgumentException('payment_payable_resolver_not_found:' . $payableType);
        }

        return $resolvers[$payableType];
    }

    /**
     * @return array<string, PayableResolverInterface>
     */
    public function getResolvers(bool $forceReload = false): array
    {
        $currentMtime = ExtendsData::getRegistryFileMtime();
        if (
            !$forceReload
            && $this->cachedResolvers !== null
            && $this->cachedExtendsMtime === $currentMtime
        ) {
            return $this->cachedResolvers;
        }

        $resolvers = [
            self::DEFAULT_PAYABLE_TYPE => $this->defaultResolver,
        ];

        foreach ($this->manualResolvers as $payableType => $resolver) {
            $resolvers[$payableType] = $resolver;
        }

        foreach ($this->scanResolverClasses($forceReload) as $className) {
            try {
                $resolver = $this->objectManager->getInstance($className);
                if (!$resolver instanceof PayableResolverInterface) {
                    continue;
                }

                $payableType = $this->normalizePayableType($resolver->getPayableType());
                if ($payableType === self::DEFAULT_PAYABLE_TYPE || isset($resolvers[$payableType])) {
                    w_log_error('Duplicate payment payable resolver ignored: ' . $payableType . ' (' . $className . ')');
                    continue;
                }

                $resolvers[$payableType] = $resolver;
            } catch (\Throwable $exception) {
                w_log_error('Instantiate payment payable resolver failed: ' . $className . ', error: ' . $exception->getMessage());
            }
        }

        $this->cachedResolvers = $resolvers;
        $this->cachedExtendsMtime = $currentMtime;

        return $resolvers;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function resolveSnapshot(string $payableType, string $payableId, ?Actor $actor = null): PayableSnapshot
    {
        $resolver = $this->getResolver($payableType);

        return $resolver->snapshot($resolver->resolve($payableId, $actor));
    }

    public function normalizePayableType(string $payableType): string
    {
        $payableType = strtolower(trim($payableType));
        if ($payableType === '') {
            return self::DEFAULT_PAYABLE_TYPE;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $payableType)) {
            throw new \InvalidArgumentException('payment_payable_type_invalid:' . $payableType);
        }

        return $payableType;
    }

    /**
     * @return string[]
     */
    private function scanResolverClasses(bool $forceReload = false): array
    {
        $resolverClasses = [];

        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Payment', $forceReload);
            if (empty($extendedBy)) {
                return [];
            }

            $modules = Env::getInstance()->getModuleList();
            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        continue;
                    }

                    $relativePath = (string) ($extension['relative_path'] ?? '');
                    if (!str_starts_with($relativePath, self::EXTENSION_PATH)) {
                        continue;
                    }

                    $sourceFile = (string) ($extension['source_file'] ?? '');
                    if ($sourceFile === '' || !is_file($sourceFile)) {
                        continue;
                    }

                    $sourceModuleInfo = $modules[$sourceModule] ?? null;
                    if (empty($sourceModuleInfo) || !($sourceModuleInfo['status'] ?? false)) {
                        continue;
                    }

                    $className = $this->getClassNameFromFile($sourceFile);
                    if ($className === null || !class_exists($className)) {
                        continue;
                    }

                    try {
                        $reflection = new \ReflectionClass($className);
                        if ($reflection->implementsInterface(PayableResolverInterface::class)) {
                            $resolverClasses[] = $className;
                        }
                    } catch (\Throwable $exception) {
                        w_log_error('Check payment payable resolver failed: ' . $className . ', error: ' . $exception->getMessage());
                    }
                }
            }
        } catch (\Throwable $exception) {
            w_log_error('Scan payment payable resolvers failed: ' . $exception->getMessage());
        }

        return array_values(array_unique($resolverClasses));
    }

    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            return null;
        }

        if (!preg_match('/\bclass\s+(\w+)/', $content, $classMatch)) {
            return null;
        }

        return trim($namespaceMatch[1]) . '\\' . trim($classMatch[1]);
    }
}
