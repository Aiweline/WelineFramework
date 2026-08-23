<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Layout\LayoutContentValidatorInterface;

final class LayoutContentValidationRegistry
{
    public const CAPABILITY_PREFIX = 'theme.layout_content_validator.';
    private const MAX_VALIDATORS = 64;

    /** @var list<class-string>|null */
    private ?array $validatorImplementations = null;

    public function __construct(private readonly ?ServiceProviderRegistry $providers = null)
    {
    }

    /** @param array<string,mixed> $layoutData @param array<string,mixed> $context */
    public function validate(array $layoutData, array $context): void
    {
        foreach ($this->validatorImplementations() as $implementation) {
            $validator = ObjectManager::getInstance($implementation);
            if (!$validator instanceof LayoutContentValidatorInterface) {
                throw new \RuntimeException($implementation . ' must implement ' . LayoutContentValidatorInterface::class);
            }
            $validator->validate($layoutData, $context);
        }
    }

    /** @return list<class-string> */
    private function validatorImplementations(): array
    {
        if ($this->validatorImplementations !== null) {
            return $this->validatorImplementations;
        }
        $registry = $this->providers ?? ObjectManager::getInstance(ServiceProviderRegistry::class);
        $implementations = [];
        foreach ($registry->implementationsWithPrefix(self::CAPABILITY_PREFIX) as $implementation) {
            if (!is_string($implementation) || trim($implementation) === '') {
                throw new \RuntimeException('theme_layout_validator_registration_invalid');
            }
            $implementations[$implementation] = true;
            if (count($implementations) > self::MAX_VALIDATORS) {
                throw new \RuntimeException('theme_layout_validator_registration_limit_exceeded');
            }
        }
        return $this->validatorImplementations = array_keys($implementations);
    }
}
