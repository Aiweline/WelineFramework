<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Data;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
require_once BP . 'app/code/Weline/Framework/Common/functions.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\WebsiteDomain;

final class WebsiteDataDomainsMemoTest extends TestCase
{
    private ?object $originalDomain = null;

    protected function setUp(): void
    {
        parent::setUp();
        Context::leave();
        Context::enter(new Context());
        RequestContext::init();
        WebsiteData::resetRequestState();
        WebsiteData::clearProcessCache();

        $instances = ObjectManager::getInstances();
        $this->originalDomain = $instances[WebsiteDomain::class] ?? null;
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(WebsiteDomain::class);
        if ($this->originalDomain !== null) {
            ObjectManager::setInstance(WebsiteDomain::class, $this->originalDomain);
        }
        WebsiteData::resetRequestState();
        WebsiteData::clearProcessCache();
        Context::leave();
        parent::tearDown();
    }

    public function testDomainsForWebsiteHitsDatabaseOncePerProcess(): void
    {
        $probe = new WebsiteDomainAssociationProbe([
            [
                WebsiteDomain::schema_fields_ID => 1,
                WebsiteDomain::schema_fields_WEBSITE_ID => 0,
                WebsiteDomain::schema_fields_DOMAIN => 'example.test',
                WebsiteDomain::schema_fields_STATUS => WebsiteDomain::STATUS_ACTIVE,
                WebsiteDomain::schema_fields_IS_PRIMARY => 1,
            ],
        ]);
        ObjectManager::setInstance(WebsiteDomain::class, $probe);

        $first = WebsiteData::domainsForWebsite(0);
        $second = WebsiteData::domainsForWebsite(0);

        self::assertSame(['example.test'], \array_column($first, WebsiteDomain::schema_fields_DOMAIN));
        self::assertSame($first, $second);
        self::assertSame([0], $probe->websiteIds);

        // Model public API also delegates into the same memo.
        self::assertSame($first, $probe->getWebsiteDomains(0));
        self::assertSame([0], $probe->websiteIds);
    }
}

final class WebsiteDomainAssociationProbe extends WebsiteDomain
{
    /** @var list<int> */
    public array $websiteIds = [];

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function fetchAssociationDomainsFromDatabase(int $websiteId): array
    {
        $this->websiteIds[] = $websiteId;

        return $this->rows;
    }
}
