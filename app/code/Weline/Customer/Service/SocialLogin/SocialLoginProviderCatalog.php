<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\FacebookProvider;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\GoogleProvider;
use Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider\InstagramProvider;
use Weline\Customer\Interface\SocialLoginProviderInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Registry of storefront social-login providers discovered via extends.
 */
final class SocialLoginProviderCatalog
{
    public const GOOGLE = 'google';
    public const FACEBOOK = 'facebook';
    public const INSTAGRAM = 'instagram';

    /** @var list<SocialLoginProviderInterface>|null */
    private ?array $resolved = null;

    /** @var array<string, string> */
    private array $sourceModules = [];

    public function __construct(
        private readonly ?SocialLoginProviderScanner $scanner = null
    ) {
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_map(
            static fn(SocialLoginProviderInterface $provider): string => $provider->getCode(),
            $this->providers()
        ));
    }

    public function isKnown(string $provider): bool
    {
        return $this->get($provider) !== null;
    }

    public function get(string $provider): ?SocialLoginProviderInterface
    {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            return null;
        }
        foreach ($this->providers() as $instance) {
            if ($instance->getCode() === $provider) {
                return $instance;
            }
        }

        return null;
    }

    public function sourceModule(string $provider): string
    {
        $this->providers();
        $code = strtolower(trim($provider));

        return $this->sourceModules[$code] ?? 'Weline_Customer';
    }

    /**
     * @return list<SocialLoginProviderInterface>
     */
    public function providers(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $instances = $this->scanProviders();
        if ($instances === []) {
            $instances = $this->builtinProviders();
            foreach ($instances as $instance) {
                $this->sourceModules[$instance->getCode()] = 'Weline_Customer';
            }
        }

        $this->resolved = $this->dedupeAndSort($instances);

        return $this->resolved;
    }

    /**
     * Display / status metadata (OAuth endpoints live on each provider).
     *
     * @return array{
     *   code:string,label:string,icon:string,brand_class:string,icon_svg:string,sort_order:int,
     *   summary:string,guide_title:string,policy_title:string,source_module:string
     * }|null
     */
    public function definition(string $provider): ?array
    {
        $instance = $this->get($provider);
        if ($instance === null) {
            return null;
        }

        return [
            'code' => $instance->getCode(),
            'label' => $instance->getLabel(),
            'icon' => $instance->getIcon(),
            'brand_class' => $instance->getBrandClass(),
            'icon_svg' => $instance->getIconSvgMarkup(),
            'sort_order' => $instance->getSortOrder(),
            'summary' => $instance->getSummary(),
            'guide_title' => $instance->getGuideTitle(),
            'policy_title' => $instance->getPolicyTitle(),
            'source_module' => $this->sourceModule($instance->getCode()),
        ];
    }

    /**
     * @return list<SocialLoginProviderInterface>
     */
    private function scanProviders(): array
    {
        try {
            $scanner = $this->scanner;
            if ($scanner === null) {
                $scanner = ObjectManager::getInstance(SocialLoginProviderScanner::class);
            }

            $instances = [];
            foreach ($scanner->scanProviderDefinitions() as $definition) {
                $className = (string) ($definition['class_name'] ?? '');
                if ($className === '') {
                    continue;
                }
                try {
                    $provider = ObjectManager::getInstance($className);
                    if (!$provider instanceof SocialLoginProviderInterface) {
                        continue;
                    }
                    $code = strtolower(trim($provider->getCode()));
                    if ($code === '') {
                        continue;
                    }
                    $sourceModule = trim((string) ($definition['source_module'] ?? ''));
                    $this->sourceModules[$code] = $sourceModule !== '' ? $sourceModule : 'Weline_Customer';
                    $instances[] = $provider;
                } catch (\Throwable $throwable) {
                    w_log_error('实例化社媒登录提供商失败: ' . $className . ', 错误: ' . $throwable->getMessage());
                }
            }

            return $instances;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<SocialLoginProviderInterface>
     */
    private function builtinProviders(): array
    {
        return [
            new GoogleProvider(),
            new FacebookProvider(),
            new InstagramProvider(),
        ];
    }

    /**
     * @param list<SocialLoginProviderInterface> $instances
     * @return list<SocialLoginProviderInterface>
     */
    private function dedupeAndSort(array $instances): array
    {
        $byCode = [];
        foreach ($instances as $instance) {
            $code = strtolower(trim($instance->getCode()));
            if ($code === '') {
                continue;
            }
            $byCode[$code] = $instance;
        }

        $list = array_values($byCode);
        usort(
            $list,
            static function (SocialLoginProviderInterface $a, SocialLoginProviderInterface $b): int {
                $order = $a->getSortOrder() <=> $b->getSortOrder();
                if ($order !== 0) {
                    return $order;
                }

                return strcmp($a->getCode(), $b->getCode());
            }
        );

        return $list;
    }
}
