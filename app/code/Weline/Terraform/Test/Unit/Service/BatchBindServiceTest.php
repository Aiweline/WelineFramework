<?php
declare(strict_types=1);

namespace Weline\Terraform\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Terraform\Service\BatchBindService;

class BatchBindServiceTest extends TestCase
{
    private BatchBindService $service;

    protected function setUp(): void
    {
        $this->service = ObjectManager::getInstance(BatchBindService::class);
    }

    public function testParseDomainsText(): void
    {
        $text = "example.com\nhttps://foo.com/path\nfoo.com\ninvalid_domain\n";
        $domains = $this->service->parseDomainsText($text);

        $this->assertContains('example.com', $domains);
        $this->assertContains('foo.com', $domains);
        $this->assertNotContains('invalid_domain', $domains);
    }
}
