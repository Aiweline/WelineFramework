<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Service\ShippingConfigurationAdminService;

/**
 * ObjectManager::getInstance 为 static；$om->getInstance() 走实际类的 LSB。
 */
final class RegionCreateTestObjectManager extends ObjectManager
{
    /** @var null|callable(string):object */
    public static $factory = null;

    public static function getInstance(string $class = '', array $arguments = [], bool $shared = true, bool $cache = false): mixed
    {
        if (is_callable(self::$factory)) {
            return (self::$factory)($class);
        }

        return parent::getInstance($class, $arguments, $shared, $cache);
    }
}

final class ShippingConfigurationAdminServiceRegionSelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        RegionCreateTestObjectManager::$factory = null;
        parent::tearDown();
    }

    public function testNormalizeAddressSelectionOrdersAndDedupes(): void
    {
        $rows = ShippingConfigurationAdminService::normalizeAddressSelection([
            [
                'region_type' => 'district',
                'country_code' => 'AF',
                'region_code' => 'AF-01-A',
                'region_name' => 'District A',
                'parent_region_id' => 12,
            ],
            [
                'region_type' => 'country',
                'country_code' => 'AF',
                'region_code' => 'AF',
                'region_name' => 'Afghanistan',
            ],
            [
                'region_type' => 'province',
                'country_code' => 'AF',
                'region_code' => 'AF-01',
                'region_name' => 'Badakhshan',
                'parent_region_id' => 1,
            ],
            [
                'region_type' => 'province',
                'country_code' => 'AF',
                'region_code' => 'AF-01',
                'region_name' => 'Badakhshan duplicate',
            ],
            [
                'region_type' => 'street',
                'country_code' => 'AF',
                'region_code' => 'ST-1',
                'region_name' => 'ignored',
            ],
        ]);

        self::assertCount(3, $rows);
        self::assertSame(Region::TYPE_COUNTRY, $rows[0]['region_type']);
        self::assertSame(Region::TYPE_PROVINCE, $rows[1]['region_type']);
        self::assertSame(Region::TYPE_DISTRICT, $rows[2]['region_type']);
        self::assertSame('AF', $rows[0]['region_code']);
        self::assertSame('AF-01', $rows[1]['region_code']);
        self::assertSame('AF-01-A', $rows[2]['region_code']);
        self::assertSame('Badakhshan', $rows[1]['region_name']);
    }

    public function testNormalizeAddressSelectionFillsCountryCodeAsRegionCode(): void
    {
        $rows = ShippingConfigurationAdminService::normalizeAddressSelection([
            [
                'region_type' => 'country',
                'country_code' => 'cn',
                'region_code' => '',
                'label' => '中国',
            ],
        ]);

        self::assertCount(1, $rows);
        self::assertSame('CN', $rows[0]['country_code']);
        self::assertSame('CN', $rows[0]['region_code']);
        self::assertSame('中国', $rows[0]['region_name']);
    }

    public function testNormalizeAddressSelectionRejectsInvalidRows(): void
    {
        $rows = ShippingConfigurationAdminService::normalizeAddressSelection([
            ['region_type' => 'province', 'country_code' => 'A', 'region_code' => 'X', 'region_name' => 'Bad'],
            ['region_type' => 'province', 'country_code' => 'AF', 'region_code' => 'bad code', 'region_name' => 'Bad'],
            null,
            'x',
        ]);

        self::assertSame([], $rows);
    }

    public function testCreateManualProvinceDistrictRequiresCountry(): void
    {
        $service = new ShippingConfigurationAdminService($this->blankObjectManager());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('请先选择国家');
        $service->createManualProvinceDistrict([
            'add_province_name' => 'Test',
            'add_province_code' => 'AF-T1',
        ]);
    }

    public function testCreateManualProvinceDistrictRequiresProvinceName(): void
    {
        $service = new ShippingConfigurationAdminService($this->blankObjectManager());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('请填写要新增的省份名称');
        $service->createManualProvinceDistrict([
            'country_code' => 'AF',
            'add_province_code' => 'AF-T1',
        ]);
    }

    public function testCreateManualProvinceDistrictRequiresProvinceCode(): void
    {
        $service = new ShippingConfigurationAdminService($this->blankObjectManager());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('请填写要新增的省份代码');
        $service->createManualProvinceDistrict([
            'country_code' => 'AF',
            'add_province_name' => 'Test Province',
        ]);
    }

    public function testCreateManualProvinceDistrictRequiresDistrictCodeWhenNamePresent(): void
    {
        $service = new ShippingConfigurationAdminService($this->blankObjectManager());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('请填写要新增的区县代码');
        $service->createManualProvinceDistrict([
            'country_code' => 'AF',
            'add_province_name' => 'Test Province',
            'add_province_code' => 'AF-T1',
            'add_district_name' => 'District Only',
        ]);
    }

    public function testCreateManualProvinceDistrictRejectsExistingProvinceWithoutDistrict(): void
    {
        $service = new ShippingConfigurationAdminService($this->createRegionObjectManagerStub([
            'AF|AF' => 1,
            'AF|AF-T1' => 10,
        ]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('该地区代码已存在，不能重复添加');
        $service->createManualProvinceDistrict([
            'country_code' => 'AF',
            'country_name' => 'Afghanistan',
            'add_province_name' => 'Test Province',
            'add_province_code' => 'AF-T1',
        ]);
    }

    public function testCreateManualProvinceDistrictCreatesOnlyNewDistrictUnderExistingProvince(): void
    {
        $service = new ShippingConfigurationAdminService($this->createRegionObjectManagerStub([
            'AF|AF' => 1,
            'AF|AF-T1' => 10,
        ]));
        $result = $service->createManualProvinceDistrict([
            'country_code' => 'AF',
            'country_name' => 'Afghanistan',
            'add_province_name' => 'Test Province',
            'add_province_code' => 'AF-T1',
            'add_district_name' => 'New District',
            'add_district_code' => 'AF-T1-D1',
        ]);

        self::assertSame(1, $result['created']);
        self::assertSame('AF', $result['primary_country']);
        self::assertCount(2, $result['regions']);
        $district = $result['regions'][1];
        self::assertSame('AF-T1-D1', (string)$district->getData(Region::schema_fields_REGION_CODE));
        self::assertSame(Region::TYPE_DISTRICT, (string)$district->getData(Region::schema_fields_REGION_TYPE));
        self::assertSame(10, (int)$district->getData(Region::schema_fields_PARENT_REGION_ID));
    }

    public function testCreateManualProvinceDistrictRejectsExistingDistrictCode(): void
    {
        $service = new ShippingConfigurationAdminService($this->createRegionObjectManagerStub([
            'AF|AF' => 1,
            'AF|AF-T1' => 10,
            'AF|AF-T1-D1' => 20,
        ]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('该地区代码已存在，不能重复添加');
        $service->createManualProvinceDistrict([
            'country_code' => 'AF',
            'add_province_name' => 'Test Province',
            'add_province_code' => 'AF-T1',
            'add_district_name' => 'Dup District',
            'add_district_code' => 'AF-T1-D1',
        ]);
    }

    private function blankObjectManager(): ObjectManager
    {
        return (new \ReflectionClass(RegionCreateTestObjectManager::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string,int> $existingByCountryCode key = "{country}|{region_code}" => id
     */
    private function createRegionObjectManagerStub(array $existingByCountryCode): ObjectManager
    {
        $seq = 1000;
        $existing = $existingByCountryCode;
        RegionCreateTestObjectManager::$factory = static function (string $class) use (&$seq, $existing): object {
            if ($class !== Region::class) {
                throw new \RuntimeException('unexpected class ' . $class);
            }
            $state = [
                'id' => 0,
                'data' => [],
                'wheres' => [],
            ];

            return new class($state, $existing, $seq) {
                /** @param array{id:int,data:array<string,mixed>,wheres:array<string,mixed>} $state */
                public function __construct(
                    private array &$state,
                    private array $existing,
                    private int &$seq
                ) {
                }

                public function reset(): self
                {
                    $this->state['wheres'] = [];

                    return $this;
                }

                public function where(string $field, mixed $value): self
                {
                    $this->state['wheres'][$field] = $value;

                    return $this;
                }

                public function find(): self
                {
                    return $this;
                }

                public function fetch(): self
                {
                    $country = strtoupper((string)($this->state['wheres'][Region::schema_fields_COUNTRY_CODE] ?? ''));
                    $code = strtoupper((string)($this->state['wheres'][Region::schema_fields_REGION_CODE] ?? ''));
                    $key = $country . '|' . $code;
                    $this->state['id'] = (int)($this->existing[$key] ?? 0);

                    return $this;
                }

                public function load(int|string $id): self
                {
                    $this->state['id'] = (int)$id;
                    foreach ($this->existing as $key => $existingId) {
                        if ((int)$existingId === (int)$id) {
                            [$country, $code] = explode('|', $key, 2);
                            $this->state['data'] = [
                                Region::schema_fields_COUNTRY_CODE => $country,
                                Region::schema_fields_REGION_CODE => $code,
                                Region::schema_fields_REGION_TYPE => $code === $country ? Region::TYPE_COUNTRY : Region::TYPE_PROVINCE,
                                Region::schema_fields_REGION_NAME => $code,
                            ];
                            break;
                        }
                    }

                    return $this;
                }

                public function getId(): int
                {
                    return (int)$this->state['id'];
                }

                /** @param array<string,mixed> $data */
                public function setData(array $data): self
                {
                    $this->state['data'] = $data;

                    return $this;
                }

                public function save(): self
                {
                    if ((int)$this->state['id'] <= 0) {
                        $this->seq++;
                        $this->state['id'] = $this->seq;
                    }

                    return $this;
                }

                public function getData(?string $key = null): mixed
                {
                    if ($key === null) {
                        return $this->state['data'];
                    }

                    return $this->state['data'][$key] ?? null;
                }
            };
        };

        return (new \ReflectionClass(RegionCreateTestObjectManager::class))->newInstanceWithoutConstructor();
    }
}
