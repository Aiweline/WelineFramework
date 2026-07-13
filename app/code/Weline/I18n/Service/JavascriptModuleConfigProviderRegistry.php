<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Javascript\JavascriptModuleConfigProviderInterface;

final class JavascriptModuleConfigProviderRegistry
{
    public const CAPABILITY_PREFIX = 'i18n.javascript_module_config.';

    /** @var list<JavascriptModuleConfigProviderInterface>|null */
    private ?array $providers = null;

    /** @var array<string, string> */
    private array $content = [];

    public function __construct(
        private readonly ServiceProviderRegistry $serviceProviders,
    ) {
    }

    public function content(string $area): string
    {
        $area = \strtolower(\trim($area));
        if (isset($this->content[$area])) {
            return $this->content[$area];
        }

        $parts = [];
        foreach ($this->providers() as $provider) {
            $content = $provider->content($area);
            if ($content !== '') {
                $parts[] = $content;
            }
        }
        return $this->content[$area] = \implode("\n", $parts);
    }

    /** @return list<JavascriptModuleConfigProviderInterface> */
    private function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];
        foreach ($this->serviceProviders->implementationsWithPrefix(self::CAPABILITY_PREFIX) as $implementation) {
            try {
                $provider = ObjectManager::getInstance($implementation);
                if ($provider instanceof JavascriptModuleConfigProviderInterface) {
                    $providers[] = $provider;
                }
            } catch (\Throwable) {
            }
        }
        \usort(
            $providers,
            static fn(JavascriptModuleConfigProviderInterface $left, JavascriptModuleConfigProviderInterface $right): int =>
                $right->priority() <=> $left->priority(),
        );
        return $this->providers = $providers;
    }
}
