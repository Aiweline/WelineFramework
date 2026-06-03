<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanContractSchema;
use PHPUnit\Framework\TestCase;

final class BuildPlanContractSchemaTest extends TestCase
{
    public function testSchemaExposesV22ContractRequirements(): void
    {
        $schema = new BuildPlanContractSchema();

        self::assertSame('2.2', $schema->version());
        self::assertContains('policy_ref', $schema->requiredTopLevelFields());
        self::assertContains('policy_projection', $schema->requiredTopLevelFields());
        self::assertContains('content_manifest', $schema->requiredTopLevelFields());
        self::assertNotContains('tasks', $schema->requiredTopLevelFields());
        self::assertContains('source_contracts', $schema->requiredTopLevelFields());
        self::assertContains('tasks', $schema->forbiddenTopLevelFields());
        self::assertContains('reason', $schema->forbiddenFieldNames());
        self::assertContains('typography', $schema->requiredDesignTokenGroups());
        self::assertContains('policy_hash', $schema->requiredPolicyFields());
        self::assertContains('content_keys', $schema->requiredBlockFields());
    }
}
