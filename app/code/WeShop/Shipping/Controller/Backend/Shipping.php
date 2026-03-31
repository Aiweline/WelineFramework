<?php

declare(strict_types=1);

namespace WeShop\Shipping\Controller\Backend;

use WeShop\Shipping\Service\ShippingService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\App\Env;

class Shipping extends BaseController
{
    public function __construct(
        private readonly ShippingService $shippingService
    ) {
    }

    #[Acl('WeShop_Shipping::shipping_index', '查看配送方式', 'mdi mdi-truck-delivery', '查看配送方式配置页面')]
    public function index(): string
    {
        $methods = $this->shippingService->getCheckoutShippingMethods(['area' => 'backend']);
        $availableMethods = $this->shippingService->getAvailableShippingMethods(['area' => 'backend']);
        $stats = $this->buildStats($methods);

        $this->assign('page_title', (string) __('Shipping Methods'));
        $this->assign('save_url', $this->_url->getBackendUrl('*/backend/shipping/save'));
        $this->assign('methods', $methods);
        $this->assign('stats', $stats);

        return $this->fetchBase();
    }

    public function save(): string
    {
        if (!$this->request->isPost()) {
            $this->getMessageManager()->addError(__('Invalid request method.'));
            $this->redirect('*/backend/shipping');

            return '';
        }

        try {
            $methods = $this->request->getPost('methods', []);
            $defaultMethod = (string) $this->request->getPost('default_method', '');
            $flatRatePrice = (float) $this->request->getPost('flat_rate_price', 5.00);

            $this->saveShippingConfig([
                'methods' => $methods,
                'default_method' => $defaultMethod,
                'flat_rate_price' => $flatRatePrice,
            ]);

            $this->getMessageManager()->addSuccess(__('Shipping settings saved successfully.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(__('Shipping settings save failed: %{1}', [$throwable->getMessage()]));
        }

        $this->redirect('*/backend/shipping');

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    protected function saveShippingConfig(array $data): void
    {
        $configPath = BASE_PATH . 'app/etc/env.php';
        $config = [];

        if (file_exists($configPath)) {
            $content = file_get_contents($configPath);
            if ($content !== false && $content !== '') {
                $config = include $configPath;
                if (!\is_array($config)) {
                    $config = [];
                }
            }
        }

        if (!isset($config['shipping'])) {
            $config['shipping'] = [];
        }

        $methods = $data['methods'] ?? [];
        $methodOverrides = [];

        foreach ($methods as $code => $settings) {
            if (!\is_array($settings)) {
                continue;
            }

            $override = [];
            if (isset($settings['enabled'])) {
                $override['enabled'] = (bool) $settings['enabled'];
            }
            if (isset($settings['is_default'])) {
                $override['is_default'] = (bool) $settings['is_default'];
            }
            if (isset($settings['sort_order'])) {
                $override['sort_order'] = (int) $settings['sort_order'];
            }
            if (isset($settings['price'])) {
                $override['price'] = (float) $settings['price'];
            }
            if (isset($settings['name'])) {
                $override['name'] = (string) $settings['name'];
            }
            if (isset($settings['description'])) {
                $override['description'] = (string) $settings['description'];
            }

            if ($override !== []) {
                $methodOverrides[$code] = $override;
            }
        }

        if (isset($data['flat_rate_price'])) {
            $methodOverrides['flat_rate']['price'] = $data['flat_rate_price'];
        }

        $config['shipping']['methods'] = $methodOverrides;

        if (isset($data['default_method'])) {
            foreach ($methodOverrides as $code => $settings) {
                $isDefault = ($code === $data['default_method']);
                if (!isset($methodOverrides[$code]['is_default'])) {
                    $methodOverrides[$code]['is_default'] = $isDefault;
                }
            }
            $config['shipping']['default_method'] = $data['default_method'];
        }

        $this->writeEnvConfig($configPath, $config);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $config
     * @return void
     */
    protected function writeEnvConfig(string $path, array $config): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException((string) __('Failed to write shipping configuration.'));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     * @return array<string, mixed>
     */
    protected function buildStats(array $methods): array
    {
        $totalMethods = count($methods);
        $enabledMethods = 0;
        $defaultMethod = '';
        $defaultMethodTitle = '';

        foreach ($methods as $method) {
            if (!empty($method['enabled'])) {
                $enabledMethods++;
            }
            if (!empty($method['is_default'])) {
                $defaultMethod = (string) ($method['code'] ?? '');
                $defaultMethodTitle = (string) ($method['name'] ?? '');
            }
        }

        return [
            'total_methods' => $totalMethods,
            'enabled_methods' => $enabledMethods,
            'reserved_methods' => $totalMethods - $enabledMethods,
            'default_method' => $defaultMethod,
            'default_method_title' => $defaultMethodTitle,
        ];
    }
}
