<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\MediaManager\Setup\Db\Migration\BindMediaManagerAiDrawToCurrentText2image20260708V100;

final class BindMediaManagerAiDrawMigrationMetadataTest extends TestCase
{
    public function testBindingMigrationIsDataOnlyAndDoesNotClaimAiTables(): void
    {
        require_once BP
            . 'app/code/Weline/MediaManager/Setup/Db/Migration/'
            . 'bind_media_manager_ai_draw_to_current_text2image_20260708-v1.0.0.php';

        $migration = new BindMediaManagerAiDrawToCurrentText2image20260708V100();

        self::assertSame('data_migration', $migration->getType());
        self::assertSame([], $migration->getAffectedTables());
    }
}
