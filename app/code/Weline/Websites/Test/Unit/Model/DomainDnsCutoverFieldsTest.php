<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Model\Domain;

/**
 * dns_cutover_complete / cron_resolved 字段行为（无 DB）
 */
class DomainDnsCutoverFieldsTest extends TestCase
{
    public function testDnsCutoverCompleteNullTreatedAsOneForBackwardCompat(): void
    {
        $d = new Domain();
        $d->clearData();
        $d->setData(Domain::schema_fields_DOMAIN, 'example.com');
        $d->setData(Domain::schema_fields_DNS_CUTOVER_COMPLETE, null);

        $this->assertSame(1, $d->getDnsCutoverComplete());
        $this->assertTrue($d->isDnsCutoverComplete());
    }

    public function testDnsCutoverCompleteZeroMeansPending(): void
    {
        $d = new Domain();
        $d->clearData();
        $d->setData(Domain::schema_fields_DOMAIN, 'example.com');
        $d->setDnsCutoverComplete(0);

        $this->assertSame(0, $d->getDnsCutoverComplete());
        $this->assertFalse($d->isDnsCutoverComplete());
    }

    public function testCronResolvedAccessor(): void
    {
        $d = new Domain();
        $d->clearData();
        $d->setCronResolved(1);
        $this->assertTrue($d->isCronResolved());
        $d->setCronResolved(0);
        $this->assertFalse($d->isCronResolved());
    }
}
