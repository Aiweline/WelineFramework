<?php

declare(strict_types=1);

namespace WeShop\Analytics\Service;

use Weline\Framework\App\Env;

class AnalyticsConfigService
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_FACEBOOK = 'facebook';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getProviderDefinitions(): array
    {
        return [
            self::PROVIDER_GOOGLE => [
                'code' => self::PROVIDER_GOOGLE,
                'label' => 'Google Analytics',
                'description' => 'GA4 measurement protocol events for storefront commerce flows.',
                'env_path' => 'analytics.google',
                'fields' => [
                    [
                        'name' => 'measurement_id',
                        'label' => 'Measurement ID',
                        'required' => true,
                        'input_type' => 'text',
                        'sensitive' => false,
                    ],
                    [
                        'name' => 'api_secret',
                        'label' => 'API Secret',
                        'required' => true,
                        'input_type' => 'password',
                        'sensitive' => true,
                    ],
                ],
                'defaults' => [
                    'enabled' => false,
                    'measurement_id' => '',
                    'api_secret' => '',
                    'event_mapping' => $this->getDefaultEventMapping(),
                ],
            ],
            self::PROVIDER_FACEBOOK => [
                'code' => self::PROVIDER_FACEBOOK,
                'label' => 'Facebook Pixel',
                'description' => 'Meta Pixel and Conversions API tracking for storefront commerce flows.',
                'env_path' => 'analytics.facebook',
                'fields' => [
                    [
                        'name' => 'pixel_id',
                        'label' => 'Pixel ID',
                        'required' => true,
                        'input_type' => 'text',
                        'sensitive' => false,
                    ],
                    [
                        'name' => 'access_token',
                        'label' => 'Access Token',
                        'required' => true,
                        'input_type' => 'password',
                        'sensitive' => true,
                    ],
                    [
                        'name' => 'test_event_code',
                        'label' => 'Test Event Code',
                        'required' => false,
                        'input_type' => 'text',
                        'sensitive' => false,
                    ],
                ],
                'defaults' => [
                    'enabled' => false,
                    'pixel_id' => '',
                    'access_token' => '',
                    'test_event_code' => '',
                    'event_mapping' => $this->getDefaultEventMapping(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProviderConfig(string $providerCode): array
    {
        $definition = $this->getProviderDefinition($providerCode);
        $stored = $this->readEnvConfig((string) $definition['env_path'], []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $config = array_replace($definition['defaults'] ?? [], $stored);
        $config['enabled'] = !empty($config['enabled']);
        $config['event_mapping'] = is_array($config['event_mapping'] ?? null)
            ? $config['event_mapping']
            : $this->getDefaultEventMapping();

        foreach ($definition['fields'] ?? [] as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $config[$name] = trim((string) ($config[$name] ?? ''));
        }

        return $config;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProviderStatuses(): array
    {
        $statuses = [];

        foreach ($this->getProviderDefinitions() as $providerCode => $definition) {
            $config = $this->getProviderConfig($providerCode);
            $statuses[] = [
                'code' => $providerCode,
                'label' => (string) ($definition['label'] ?? $providerCode),
                'description' => (string) ($definition['description'] ?? ''),
                'enabled' => !empty($config['enabled']),
                'ready' => $this->isProviderReady($providerCode, $config),
                'config' => $config,
                'fields' => $definition['fields'] ?? [],
            ];
        }

        return $statuses;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveProviderConfig(array $payload): array
    {
        $providerCode = trim((string) ($payload['provider'] ?? ''));
        $definition = $this->getProviderDefinition($providerCode);
        $defaults = is_array($definition['defaults'] ?? null) ? $definition['defaults'] : [];
        $config = array_replace($defaults, $this->getProviderConfig($providerCode));
        $config['enabled'] = !empty($payload['enabled']);

        foreach ($definition['fields'] ?? [] as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $value = trim((string) ($payload[$name] ?? $config[$name] ?? ''));
            if (!empty($field['sensitive']) && $value === '' && trim((string) ($config[$name] ?? '')) !== '') {
                continue;
            }

            $config[$name] = $value;
        }

        if ($config['enabled']) {
            $missingLabels = [];
            foreach ($definition['fields'] ?? [] as $field) {
                if (empty($field['required'])) {
                    continue;
                }

                $name = (string) ($field['name'] ?? '');
                if ($name !== '' && trim((string) ($config[$name] ?? '')) === '') {
                    $missingLabels[] = (string) ($field['label'] ?? $name);
                }
            }

            if ($missingLabels !== []) {
                throw new \InvalidArgumentException(
                    (string) __('Missing required analytics fields: %{1}', [implode(', ', $missingLabels)])
                );
            }
        }

        $config['event_mapping'] = $this->getDefaultEventMapping();

        if (!$this->writeEnvConfig((string) $definition['env_path'], $config)) {
            throw new \RuntimeException((string) __('Failed to persist analytics provider config.'));
        }

        return $config;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getTrackedEvents(): array
    {
        return [
            ['code' => 'page_view', 'label' => 'Page View'],
            ['code' => 'view_item', 'label' => 'View Item'],
            ['code' => 'add_to_cart', 'label' => 'Add To Cart'],
            ['code' => 'add_to_wishlist', 'label' => 'Add To Wishlist'],
            ['code' => 'begin_checkout', 'label' => 'Begin Checkout'],
            ['code' => 'purchase', 'label' => 'Purchase'],
            ['code' => 'login', 'label' => 'Login'],
            ['code' => 'register', 'label' => 'Register'],
        ];
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function isProviderReady(string $providerCode, ?array $config = null): bool
    {
        $definition = $this->getProviderDefinition($providerCode);
        $config = $config ?? $this->getProviderConfig($providerCode);
        if (empty($config['enabled'])) {
            return false;
        }

        foreach ($definition['fields'] ?? [] as $field) {
            if (empty($field['required'])) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            if ($name !== '' && trim((string) ($config[$name] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function getDefaultEventMapping(): array
    {
        return [
            'page_view' => 'page_view',
            'view_item' => 'view_item',
            'add_to_cart' => 'add_to_cart',
            'add_to_wishlist' => 'add_to_wishlist',
            'begin_checkout' => 'begin_checkout',
            'purchase' => 'purchase',
            'login' => 'login',
            'register' => 'register',
        ];
    }

    protected function readEnvConfig(string $path, mixed $default = []): mixed
    {
        return Env::getInstance()->getConfig($path, $default);
    }

    protected function writeEnvConfig(string $path, mixed $value): bool
    {
        return Env::getInstance()->setConfig($path, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function getProviderDefinition(string $providerCode): array
    {
        $definition = $this->getProviderDefinitions()[$providerCode] ?? null;
        if (!is_array($definition)) {
            throw new \InvalidArgumentException((string) __('Unsupported analytics provider: %{1}', [$providerCode]));
        }

        return $definition;
    }
}
