<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

use Weline\Captcha\Interface\VerificationProviderInterface;
use Weline\Captcha\Provider\GoogleRecaptchaEnterprise;
use Weline\Captcha\Provider\LocalImageCaptcha;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

final class CaptchaProviderRegistry
{
    /** @var array<string, VerificationProviderInterface>|null */
    private ?array $providers = null;

    public function get(string $code): ?VerificationProviderInterface
    {
        return $this->all()[\strtolower(\trim($code))] ?? null;
    }

    /** @return array<string, VerificationProviderInterface> */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [
            'local_image' => ObjectManager::getInstance(LocalImageCaptcha::class),
            'google_enterprise' => ObjectManager::getInstance(GoogleRecaptchaEnterprise::class),
        ];
        $data = new DataObject(['providers' => $providers]);
        ObjectManager::getInstance(EventsManager::class)->dispatch('Weline_Captcha::providers::collect', $data);
        $collected = $data->getData('providers');
        if (\is_array($collected)) {
            foreach ($collected as $provider) {
                if (!$provider instanceof VerificationProviderInterface) {
                    continue;
                }
                $providers[$provider->code()] = $provider;
            }
        }

        return $this->providers = $providers;
    }
}
