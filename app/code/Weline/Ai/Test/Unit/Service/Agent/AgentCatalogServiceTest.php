<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Agent;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Api\ToolInterface;
use Weline\Ai\Model\AiAgent;
use Weline\Ai\Model\AiAgentTool;
use Weline\Ai\Service\Agent\AgentCatalogService;
use Weline\Ai\Service\AgentScanner;
use Weline\Framework\Manager\ObjectManager;

final class AgentCatalogServiceTest extends TestCase
{
    private const CODE = 'unit_test_agent_catalog';

    private AgentCatalogService $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = ObjectManager::getInstance(AgentCatalogService::class);
        $this->purgeFixture();
    }

    protected function tearDown(): void
    {
        $this->purgeFixture();
        parent::tearDown();
    }

    public function testScanSyncPreservesOverridesEnablementAndMarksMissingTools(): void
    {
        $this->insertAgentRow();
        $this->catalog->syncToolsFromAgent(self::CODE, [
            $this->tool('inspect_site_state', 'source inspect'),
            $this->tool('publish_site', 'source publish'),
        ]);

        $this->catalog->saveOverrides(self::CODE, 'Override Name', 'Override Description');
        $this->catalog->setActive(self::CODE, false);
        $this->catalog->saveToolOverride(self::CODE, 'inspect_site_state', 'custom inspect');
        $this->catalog->setToolEnabled(self::CODE, 'publish_site', false);

        $this->catalog->syncToolsFromAgent(self::CODE, [
            $this->tool('inspect_site_state', 'updated inspect'),
            $this->tool('retry_plan', 'new retry'),
        ]);

        $item = $this->catalog->findByCode(self::CODE);
        self::assertNotNull($item);
        self::assertSame('Override Name', $item['name_override']);
        self::assertSame('Override Description', $item['description_override']);
        self::assertFalse($item['is_active']);
        self::assertSame('updated inspect', $this->toolByName($item, 'inspect_site_state')['description']);
        self::assertSame('custom inspect', $this->toolByName($item, 'inspect_site_state')['description_override']);
        self::assertTrue($this->toolByName($item, 'inspect_site_state')['is_present']);
        self::assertFalse($this->toolByName($item, 'publish_site')['is_present']);
        self::assertFalse($this->toolByName($item, 'publish_site')['is_enabled']);
        self::assertTrue($this->toolByName($item, 'retry_plan')['is_present']);
        self::assertTrue($this->toolByName($item, 'retry_plan')['is_enabled']);

        $cleared = $this->catalog->saveOverrides(self::CODE, '', '');
        self::assertNull($cleared['name_override']);
        self::assertNull($cleared['description_override']);
        self::assertSame('Unit Test Agent', $cleared['effective_name']);
        self::assertSame('Source description', $cleared['effective_description']);

        $clearedTool = $this->catalog->saveToolOverride(self::CODE, 'inspect_site_state', '');
        self::assertNull($this->toolByName($clearedTool, 'inspect_site_state')['description_override']);
        self::assertSame(
            'updated inspect',
            $this->toolByName($clearedTool, 'inspect_site_state')['effective_description']
        );
    }

    public function testScannerSourceNoLongerForcesActiveOnUpdate(): void
    {
        $source = (string)file_get_contents(BP . '/app/code/Weline/Ai/Service/AgentScanner.php');
        self::assertStringContainsString('syncToolsFromAgent', $source);
        self::assertStringContainsString('markMissingAgents', $source);
        self::assertStringContainsString(AgentScanner::class, AgentScanner::class);
        self::assertMatchesRegularExpression(
            '/if \(\$existing->getId\(\)\) \{[^}]*setData\(\$field, \$value\);/s',
            $source
        );
        self::assertDoesNotMatchRegularExpression(
            '/if \(\$existing->getId\(\)\) \{[^}]*IS_ACTIVE\s*=>\s*1/s',
            $source
        );
    }

    public function testBackendAgentTemplateBuildsToolStatusBadgeWithValidConcatenation(): void
    {
        $template = (string)file_get_contents(
            BP . '/app/code/Weline/Ai/view/templates/Backend/Agent/index.phtml'
        );
        $condition = "(tool.is_enabled && tool.is_present ? 'text-bg-success' : 'text-bg-secondary')";

        self::assertStringContainsString($condition . " + '\">'", $template);
        self::assertStringNotContainsString($condition . "\">'", $template);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function toolByName(array $item, string $name): array
    {
        foreach ((array)($item['tools'] ?? []) as $tool) {
            if (is_array($tool) && (string)($tool['tool_name'] ?? '') === $name) {
                return $tool;
            }
        }
        self::fail('Tool not found: ' . $name);
    }

    private function tool(string $name, string $description): ToolInterface
    {
        return new class($name, $description) implements ToolInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $description,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return $this->description;
            }

            public function getParameters(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): mixed
            {
                return [];
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };
    }

    private function insertAgentRow(): void
    {
        $agent = ObjectManager::getInstance(AiAgent::class)->reset();
        $agent->setData([
            AiAgent::schema_fields_CODE => self::CODE,
            AiAgent::schema_fields_NAME => 'Unit Test Agent',
            AiAgent::schema_fields_DESCRIPTION => 'Source description',
            AiAgent::schema_fields_VERSION => '1.0.0',
            AiAgent::schema_fields_CLASS_NAME => 'Unit\\Test\\Agent',
            AiAgent::schema_fields_FILE_PATH => 'tests/unit-agent.php',
            AiAgent::schema_fields_SCENARIOS => json_encode(['unit_test'], JSON_UNESCAPED_UNICODE),
            AiAgent::schema_fields_TOOLS_COUNT => 0,
            AiAgent::schema_fields_MAX_ITERATIONS => 5,
            AiAgent::schema_fields_MODULE => 'Weline_Ai',
            AiAgent::schema_fields_IS_ACTIVE => 1,
            AiAgent::schema_fields_IS_PRESENT => 1,
            AiAgent::schema_fields_CREATED_TIME => time(),
            AiAgent::schema_fields_UPDATED_TIME => time(),
        ])->save();
    }

    private function purgeFixture(): void
    {
        try {
            $tool = ObjectManager::getInstance(AiAgentTool::class)->reset();
            $tool->where(AiAgentTool::schema_fields_AGENT_CODE, self::CODE)->delete()->fetch();
        } catch (\Throwable) {
        }
        try {
            $agent = ObjectManager::getInstance(AiAgent::class)->reset();
            $agent->where(AiAgent::schema_fields_CODE, self::CODE)->delete()->fetch();
        } catch (\Throwable) {
        }
    }
}
