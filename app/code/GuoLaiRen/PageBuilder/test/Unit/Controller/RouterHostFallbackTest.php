<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Router;
use GuoLaiRen\PageBuilder\Model\Page;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

final class RouterHostFallbackTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $objectManagerInstancesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManagerInstancesBackup = $this->getObjectManagerInstances();
    }

    protected function tearDown(): void
    {
        WelineEnv::getInstance()->reset();
        WebsiteData::reset();
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup);

        parent::tearDown();
    }

    public function testRootPathDoesNotUseStaleWebsiteContextWhenLoopbackHostIsUnbound(): void
    {
        $this->initRequestContext([
            'HTTP_HOST' => '127.0.0.1',
            'SERVER_NAME' => '127.0.0.1',
            'REQUEST_URI' => '/',
            'WELINE_WEBSITE_ID' => '268',
            'WELINE_WEBSITE_URL' => 'http://p11005ce4.weline.test',
        ]);

        $path = '/';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('/', $path);
        self::assertArrayNotHasKey('module', $rule);
    }

    public function testShortPathDoesNotResolvePageBuilderHandleWithoutBoundHost(): void
    {
        $this->initRequestContext([
            'HTTP_HOST' => '127.0.0.1',
            'SERVER_NAME' => '127.0.0.1',
            'REQUEST_URI' => '/home',
            'WELINE_WEBSITE_ID' => '268',
            'WELINE_WEBSITE_URL' => 'http://p11005ce4.weline.test',
        ]);

        $path = '/home';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('/home', $path);
        self::assertArrayNotHasKey('module', $rule);
    }

    public function testHostCandidatesIgnoreDerivedWebsiteUrl(): void
    {
        $this->initRequestContext([
            'HTTP_HOST' => '127.0.0.1',
            'SERVER_NAME' => '127.0.0.1',
            'REQUEST_URI' => '/',
            'WELINE_WEBSITE_URL' => 'http://p11005ce4.weline.test',
        ]);

        $method = new ReflectionMethod(Router::class, 'currentHostCandidates');
        $method->setAccessible(true);

        self::assertNotContains('p11005ce4.weline.test', $method->invoke(null));
    }

    public function testHostMatchingRequiresActiveWebsiteDomainBinding(): void
    {
        $domainModel = new RouterHostFallbackDomainStub([]);
        $websiteModel = new RouterHostFallbackWebsiteStub([
            Website::schema_fields_ID => 268,
            Website::schema_fields_URL => 'http://p11005ce4.weline.test',
        ]);
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup + [
            WebsiteDomain::class => $domainModel,
            Website::class => $websiteModel,
        ]);

        $method = new ReflectionMethod(Router::class, 'findWebsiteIdByHost');
        $method->setAccessible(true);

        self::assertNull($method->invoke(null, 'p11005ce4.weline.test'));
        self::assertSame(0, $websiteModel->findCalls);
    }

    public function testStandardProjectHostIgnoresStaleActivePageBuilderBinding(): void
    {
        $domainModel = new RouterHostFallbackDomainStub([
            WebsiteDomain::schema_fields_ID => 174,
            WebsiteDomain::schema_fields_WEBSITE_ID => 268,
            WebsiteDomain::schema_fields_DOMAIN => 'p11005ce4.weline.test',
            WebsiteDomain::schema_fields_STATUS => WebsiteDomain::STATUS_ACTIVE,
        ]);
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup + [
            WebsiteDomain::class => $domainModel,
        ]);

        $method = new ReflectionMethod(Router::class, 'findWebsiteIdByHost');
        $method->setAccessible(true);

        self::assertNull($method->invoke(null, 'p11005ce4.weline.test'));
    }

    public function testGeneratedLocalPageBuilderDomainCanUseActiveBinding(): void
    {
        $domainModel = new RouterHostFallbackDomainStub([
            WebsiteDomain::schema_fields_ID => 175,
            WebsiteDomain::schema_fields_WEBSITE_ID => 268,
            WebsiteDomain::schema_fields_DOMAIN => 'teenpatti-demo.weline.test',
            WebsiteDomain::schema_fields_STATUS => WebsiteDomain::STATUS_ACTIVE,
        ]);
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup + [
            WebsiteDomain::class => $domainModel,
        ]);

        $method = new ReflectionMethod(Router::class, 'findWebsiteIdByHost');
        $method->setAccessible(true);

        self::assertSame(268, $method->invoke(null, 'teenpatti-demo.weline.test'));
    }

    public function testWebsiteSpecificHomeDoesNotFallbackToGlobalPageBuilderHome(): void
    {
        $pageModel = new RouterHostFallbackPageStub();
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup + [
            Page::class => $pageModel,
        ]);

        $method = new ReflectionMethod(Router::class, 'getHomePageHandle');
        $method->setAccessible(true);

        self::assertNull($method->invoke(null, 268));
        self::assertSame([268], $pageModel->state->queriedWebsiteIds);
    }

    /**
     * @param array<string, mixed> $server
     */
    private function initRequestContext(array $server): void
    {
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $server);
    }

    /**
     * @return array<string, mixed>
     */
    private function getObjectManagerInstances(): array
    {
        $reflection = new ReflectionClass(ObjectManager::class);
        $property = $reflection->getProperty('instances');
        $property->setAccessible(true);

        /** @var array<string, mixed> $instances */
        $instances = $property->getValue();
        return $instances;
    }

    /**
     * @param array<string, mixed> $instances
     */
    private function setObjectManagerInstances(array $instances): void
    {
        $reflection = new ReflectionClass(ObjectManager::class);
        $property = $reflection->getProperty('instances');
        $property->setAccessible(true);
        $property->setValue(null, $instances);
    }
}

final class RouterHostFallbackDomainStub extends WebsiteDomain
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public function clearData(bool $with_query = true): static
    {
        return $this;
    }

    public function clearQuery(string $type = ''): static
    {
        return $this;
    }

    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
    {
        return $this;
    }

    public function find(string $find_fields = ''): static
    {
        return $this;
    }

    public function fetch(string $model_class = ''): mixed
    {
        return $this;
    }

    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->data;
        }

        return $this->data[$key] ?? null;
    }
}

final class RouterHostFallbackWebsiteStub extends Website
{
    public int $findCalls = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public function clear(bool $with_query = true): static
    {
        return $this;
    }

    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
    {
        return $this;
    }

    public function find(string $find_fields = ''): static
    {
        $this->findCalls++;
        return $this;
    }

    public function fetch(string $model_class = ''): mixed
    {
        return $this;
    }

    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->data;
        }

        return $this->data[$key] ?? null;
    }
}

final class RouterHostFallbackPageStub extends Page
{
    public object $state;

    public function __construct()
    {
        $this->state = (object)[
            'where' => [],
            'data' => [],
            'queriedWebsiteIds' => [],
        ];
    }

    public function clear(bool $with_query = true): static
    {
        $this->state->where = [];
        $this->state->data = [];
        return $this;
    }

    public function where(array|string $field, mixed $value = null, string $condition = '=', string $where_logic = 'AND', string $array_where_logic_type = 'AND'): static
    {
        $this->state->where[] = [$field, $value, $condition];
        return $this;
    }

    public function find(string $find_fields = ''): static
    {
        return $this;
    }

    public function fetch(string $model_class = ''): mixed
    {
        $websiteId = null;
        foreach ($this->state->where as $where) {
            if (($where[0] ?? null) === Page::schema_fields_WEBSITE_ID) {
                $websiteId = (int)($where[1] ?? 0);
            }
        }

        if ($websiteId !== null) {
            $this->state->queriedWebsiteIds[] = $websiteId;
        }

        $this->state->data = $websiteId === 0
            ? [
                Page::schema_fields_ID => 900,
                Page::schema_fields_HANDLE => 'global-card-game-home',
            ]
            : [];

        return $this;
    }

    public function getData(string $key = '', $index = null): mixed
    {
        if ($key === '') {
            return $this->state->data;
        }

        return $this->state->data[$key] ?? null;
    }

    public function getId(mixed $default = 0): mixed
    {
        return $this->state->data[Page::schema_fields_ID] ?? $default;
    }
}
