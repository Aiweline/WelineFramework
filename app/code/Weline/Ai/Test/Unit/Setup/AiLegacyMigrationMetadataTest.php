<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Database\Service\HistoricalMigrationMetadataRegistry;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class AiLegacyMigrationMetadataTest extends TestCase
{
    public function testLegacyAiModelMigrationsDeclareEveryPhysicalTableTheyCanChange(): void
    {
        $createLegacyTable = BP
            . 'app/code/Weline/Ai/Setup/Db/Migration/'
            . 'create_table__ai_models_20250101-v1.0.0.php';
        $addCurrentPriceFields = BP
            . 'app/code/Weline/Ai/Setup/Db/Migration/'
            . 'add_token_price_fields_20250111-v1.1.0.php';
        $registry = new HistoricalMigrationMetadataRegistry();

        self::assertSame(
            'd01b68ae7e28e9cfa562ce9b672542f07cf9e5e11744f55e1171bc28b6cb2c59',
            hash_file('sha256', $createLegacyTable),
            'Historical migration bytes are immutable because installed records bind this checksum.',
        );
        self::assertSame(
            'ddf3cdff1eef3fbb4fc8587f514d8c3118c39b225e27ceb1bdd991e1aa8cc098',
            hash_file('sha256', $addCurrentPriceFields),
            'Historical migration bytes are immutable because installed records bind this checksum.',
        );
        self::assertSame(['ai_models'], $registry->affectedTables($createLegacyTable));
        self::assertSame([AiModel::schema_table], $registry->affectedTables($addCurrentPriceFields));
    }
}
