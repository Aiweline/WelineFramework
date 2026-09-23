<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('VENDOR_PATH') || \define('VENDOR_PATH', BP . 'vendor' . DS);
\defined('PUB') || \define('PUB', BP . 'pub' . DS);
\defined('DEV') || \define('DEV', false);
\defined('DEBUG') || \define('DEBUG', false);
\defined('SANDBOX') || \define('SANDBOX', false);
require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployEnvMapService;

final class DeployEnvMapServiceTest extends TestCase
{
    private string $tempRoot = '';

    protected function tearDown(): void
    {
        if ($this->tempRoot !== '' && is_dir($this->tempRoot)) {
            $map = $this->tempRoot . DIRECTORY_SEPARATOR . DeployEnvMapService::MAP_FILENAME;
            if (is_file($map)) {
                @unlink($map);
            }
            @rmdir($this->tempRoot);
        }
        parent::tearDown();
    }

    public function testMissingMapFileSkips(): void
    {
        $root = $this->makeTempRoot();
        $service = new DeployEnvMapService(null, null, static fn (): string => 'prod', null);

        $result = $service->apply(['deploy_root' => $root]);

        self::assertSame(DeployEnvMapService::RESULT_SCHEMA, $result['schema']);
        self::assertSame('skipped', $result['status']);
        self::assertSame('map_file_missing', $result['reason']);
        self::assertSame([], $result['changes']);
    }

    public function testProdLevelWritesMappedScopedValues(): void
    {
        $root = $this->makeTempRoot();
        $this->writeMap($root, [
            'schema' => DeployEnvMapService::SCHEMA,
            'rules' => [
                [
                    'module' => 'Weline_Payment',
                    'area' => 'backend',
                    'key' => 'payment/method/paypal/environment',
                    'scopes' => ['global', 'shop.__website__.default'],
                    'values_by_level' => [
                        'dev' => 'sandbox',
                        'prod' => 'live',
                    ],
                ],
            ],
        ]);

        $writes = [];
        $service = new DeployEnvMapService(
            null,
            null,
            static fn (): string => 'prod',
            static function (string $key, mixed $value, string $module, string $area, string $scope) use (&$writes): bool {
                $writes[] = compact('key', 'value', 'module', 'area', 'scope');

                return true;
            },
        );

        $result = $service->apply(['deploy_root' => $root, 'target_level' => 'prod']);

        self::assertSame('applied', $result['status']);
        self::assertSame('prod', $result['target_level']);
        self::assertCount(2, $result['changes']);
        self::assertSame(
            [
                [
                    'key' => 'payment/method/paypal/environment',
                    'value' => 'live',
                    'module' => 'Weline_Payment',
                    'area' => 'backend',
                    'scope' => 'default.default.default',
                ],
                [
                    'key' => 'payment/method/paypal/environment',
                    'value' => 'live',
                    'module' => 'Weline_Payment',
                    'area' => 'backend',
                    'scope' => 'shop.__website__.default',
                ],
            ],
            $writes,
        );
    }

    public function testDryRunDoesNotWrite(): void
    {
        $root = $this->makeTempRoot();
        $this->writeMap($root, [
            'schema' => DeployEnvMapService::SCHEMA,
            'rules' => [
                [
                    'module' => 'Weline_Payment',
                    'area' => 'backend',
                    'key' => 'payment/method/fake_card/environment',
                    'scopes' => ['global'],
                    'values_by_level' => ['prod' => 'live'],
                ],
            ],
        ]);

        $writes = 0;
        $service = new DeployEnvMapService(
            null,
            null,
            static fn (): string => 'prod',
            static function () use (&$writes): bool {
                $writes++;

                return true;
            },
        );

        $result = $service->apply(['deploy_root' => $root, 'dry_run' => true, 'target_level' => 'prod']);

        self::assertSame(0, $writes);
        self::assertSame('applied', $result['status']);
        self::assertSame('dry_run', $result['reason']);
        self::assertFalse($result['changes'][0]['written']);
    }

    public function testLevelWithoutMappingSkipsRuleWithoutWrite(): void
    {
        $root = $this->makeTempRoot();
        $this->writeMap($root, [
            'schema' => DeployEnvMapService::SCHEMA,
            'rules' => [
                [
                    'module' => 'Weline_Payment',
                    'area' => 'backend',
                    'key' => 'payment/method/paypal/environment',
                    'scopes' => ['global'],
                    'values_by_level' => ['prod' => 'live'],
                ],
            ],
        ]);

        $writes = 0;
        $service = new DeployEnvMapService(
            null,
            null,
            static fn (): string => 'dev',
            static function () use (&$writes): bool {
                $writes++;

                return true;
            },
        );

        $result = $service->apply(['deploy_root' => $root, 'target_level' => 'dev']);

        self::assertSame(0, $writes);
        self::assertSame('applied', $result['status']);
        self::assertSame([], $result['changes']);
    }

    public function testStarScopeIsRejected(): void
    {
        $service = new DeployEnvMapService();
        $this->expectException(\InvalidArgumentException::class);
        $service->normalizeScope('*');
    }

    public function testNormalizeLevelAliases(): void
    {
        $service = new DeployEnvMapService();
        self::assertSame('dev', $service->normalizeLevel('local'));
        self::assertSame('staging', $service->normalizeLevel('pre'));
        self::assertSame('prod', $service->normalizeLevel('production'));
    }

    public function testWritePathPinsLocaleDefault(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/DeployEnvMapService.php');
        self::assertStringContainsString('ConfigReader::LOCALE_DEFAULT', $src);
        self::assertMatchesRegularExpression(
            '/setScopedConfig\(\s*\$key,\s*\$value,\s*\$module,\s*\$area,\s*\$scope,\s*ConfigReader::LOCALE_DEFAULT/s',
            $src,
        );
    }

    private function makeTempRoot(): string
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-env-map-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->tempRoot, 0777, true));

        return $this->tempRoot;
    }

    /**
     * @param array<string, mixed> $map
     */
    private function writeMap(string $root, array $map): void
    {
        $path = $root . DIRECTORY_SEPARATOR . DeployEnvMapService::MAP_FILENAME;
        $export = var_export($map, true);
        self::assertNotFalse(file_put_contents($path, "<?php\nreturn {$export};\n"));
    }
}
