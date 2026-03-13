<?php

declare(strict_types=1);

namespace Weline\AutoLeadAgent\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\AutoLeadAgent\Model\AgentConfig;

class AgentConfigTest extends TestCase
{
    public function testGetDefaultConfigsReturnsArray(): void
    {
        $configs = AgentConfig::getDefaultConfigs();
        $this->assertIsArray($configs);
        $this->assertNotEmpty($configs);
    }

    public function testDefaultConfigsContainsRequiredKeys(): void
    {
        $configs = AgentConfig::getDefaultConfigs();
        $this->assertArrayHasKey(AgentConfig::CONFIG_AGENT_INTERVAL, $configs);
        $this->assertArrayHasKey(AgentConfig::CONFIG_SCORE_THRESHOLD, $configs);
        $this->assertArrayHasKey(AgentConfig::CONFIG_KEYWORD_STRATEGY, $configs);
        $this->assertArrayHasKey(AgentConfig::CONFIG_HF_MODEL_ID, $configs);
        $this->assertArrayHasKey(AgentConfig::CONFIG_HF_MODEL_ENABLED, $configs);
        $this->assertArrayHasKey(AgentConfig::CONFIG_HF_MODEL_CACHE_SIZE, $configs);
    }

    public function testDefaultKeywordStrategy(): void
    {
        $configs = AgentConfig::getDefaultConfigs();
        $this->assertEquals(AgentConfig::KEYWORD_STRATEGY_AUTO, $configs[AgentConfig::CONFIG_KEYWORD_STRATEGY]);
    }

    public function testDefaultHfModelEnabled(): void
    {
        $configs = AgentConfig::getDefaultConfigs();
        $this->assertIsBool($configs[AgentConfig::CONFIG_HF_MODEL_ENABLED]);
        $this->assertFalse($configs[AgentConfig::CONFIG_HF_MODEL_ENABLED]);
    }

    public function testDefaultTargetSitesIsArray(): void
    {
        $configs = AgentConfig::getDefaultConfigs();
        $sites = $configs[AgentConfig::CONFIG_DEFAULT_TARGET_SITES];
        $this->assertIsArray($sites);
        $this->assertContains('linkedin.com', $sites);
    }

    public function testKeywordStrategyConstants(): void
    {
        $this->assertEquals('auto', AgentConfig::KEYWORD_STRATEGY_AUTO);
        $this->assertEquals('manual', AgentConfig::KEYWORD_STRATEGY_MANUAL);
        $this->assertEquals('hybrid', AgentConfig::KEYWORD_STRATEGY_HYBRID);
    }
}
