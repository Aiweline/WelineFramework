<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\AccountService;

class AccountServiceTest extends TestCase
{
    private AccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccountService();
    }

    public function testSupportsModelReturnsTrueForConfiguredStaticModel(): void
    {
        $this->assertTrue($this->service->supportsModel('openai', 'gpt-4o-mini'));
    }

    public function testSupportsModelFallsBackToProviderPrefixForUnsyncedModel(): void
    {
        $this->assertTrue($this->service->supportsModel('openai', 'gpt-4.1'));
    }

    public function testSupportsModelReturnsFalseForWrongProvider(): void
    {
        $this->assertFalse($this->service->supportsModel('anthropic', 'gpt-4o-mini'));
    }
}
