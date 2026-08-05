<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;

/**
 * P2 固定 Payment/Order/Inventory 共用 default connector（DEC-013 / TEST-PAY-05）。
 * 指纹不一致时在任何状态写前硬失败并报告计划偏差。
 */
final class PaymentConnectorGuard
{
    public const ERROR_CONNECTOR_MISMATCH = 'payment_connector_plan_deviation';
    public const DEFAULT_CONNECTOR = 'default';

    /** @var array{payment:string,order:string,inventory:string} */
    private array $fingerprints = [
        'payment' => self::DEFAULT_CONNECTOR,
        'order' => self::DEFAULT_CONNECTOR,
        'inventory' => self::DEFAULT_CONNECTOR,
    ];
    private bool $explicitFingerprints = false;

    /**
     * @param array{payment?:string,order?:string,inventory?:string} $fingerprints
     */
    public function __construct(array $fingerprints = [])
    {
        $this->explicitFingerprints = $fingerprints !== [];
        foreach (['payment', 'order', 'inventory'] as $key) {
            if (isset($fingerprints[$key]) && \is_string($fingerprints[$key]) && $fingerprints[$key] !== '') {
                $this->fingerprints[$key] = $fingerprints[$key];
            }
        }
    }

    public static function forTesting(
        string $payment = self::DEFAULT_CONNECTOR,
        string $order = self::DEFAULT_CONNECTOR,
        string $inventory = self::DEFAULT_CONNECTOR,
    ): self {
        return new self([
            'payment' => $payment,
            'order' => $order,
            'inventory' => $inventory,
        ]);
    }

    public function setFingerprint(string $subsystem, string $fingerprint): void
    {
        if (!isset($this->fingerprints[$subsystem])) {
            throw new \InvalidArgumentException('unknown_subsystem:' . $subsystem);
        }
        $this->fingerprints[$subsystem] = $fingerprint;
        $this->explicitFingerprints = true;
    }

    /**
     * @return array{payment:string,order:string,inventory:string}
     */
    public function fingerprints(): array
    {
        if (!$this->explicitFingerprints) {
            $modulePrefix = Env::framework_name . '_';
            $this->fingerprints = [
                'payment' => $this->moduleConnectorFingerprint($modulePrefix . 'Payment'),
                'order' => $this->moduleConnectorFingerprint($modulePrefix . 'Order'),
                'inventory' => $this->moduleConnectorFingerprint($modulePrefix . 'Inventory'),
            ];
        }

        return $this->fingerprints;
    }

    public function assertSameDefaultConnector(): void
    {
        $fingerprints = $this->fingerprints();
        $values = array_values($fingerprints);
        $unique = array_unique($values);
        if (count($unique) !== 1 || ($values[0] ?? null) !== self::DEFAULT_CONNECTOR) {
            throw new \RuntimeException(self::ERROR_CONNECTOR_MISMATCH . ':' . json_encode($fingerprints, JSON_UNESCAPED_SLASHES));
        }
    }

    public function isAligned(): bool
    {
        try {
            $this->assertSameDefaultConnector();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function moduleConnectorFingerprint(string $moduleName): string
    {
        $module = Env::getInstance()->getModuleInfo($moduleName);
        if (!\is_array($module)) {
            return 'missing:' . $moduleName;
        }
        $basePath = rtrim((string) ($module['base_path'] ?? ''), '/\\');
        if ($basePath === '') {
            return 'missing:' . $moduleName;
        }

        // AbstractModel falls back to the framework default connector when a
        // module has no etc/db.php. P2 deliberately rejects every module-local
        // connector, even if two custom configurations happen to point at the
        // same physical database.
        return is_file($basePath . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'db.php')
            ? 'module:' . $moduleName
            : self::DEFAULT_CONNECTOR;
    }
}
