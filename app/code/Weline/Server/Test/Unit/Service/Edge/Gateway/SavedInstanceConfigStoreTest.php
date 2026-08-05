<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\SavedInstanceConfigStore;

final class SavedInstanceConfigStoreTest extends TestCase
{
    public function testOwnedFieldRollbackUsesAfterImageCas(): void
    {
        $directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-config-cas-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($directory, 0700, true));
        try {
            $store = new SavedInstanceConfigStore($directory);
            $store->update('default', static fn (array $config): array => [[
                'edge_mode' => 'legacy',
                'saved_at' => 'before',
                'unrelated' => 'keep',
            ], null]);
            $before = [
                'edge_mode' => ['exists' => true, 'value' => 'legacy'],
                'saved_at' => ['exists' => true, 'value' => 'before'],
            ];
            $after = [
                'edge_mode' => ['exists' => true, 'value' => 'auto'],
                'saved_at' => ['exists' => true, 'value' => 'promotion'],
            ];
            $store->update('default', static function (array $config): array {
                $config['edge_mode'] = 'auto';
                $config['saved_at'] = 'concurrent';
                return [$config, null];
            });
            $result = $store->restoreOwnedFields('default', $before, $after);

            self::assertSame(['edge_mode'], $result['restored']);
            self::assertSame(['saved_at'], $result['conflicts']);
            $saved = \json_decode((string)\file_get_contents(
                $store->file('default'),
            ), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('legacy', $saved['edge_mode']);
            self::assertSame('concurrent', $saved['saved_at']);
            self::assertSame('keep', $saved['unrelated']);
        } finally {
            foreach ((array)\glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
                @\unlink($file);
            }
            @\unlink($directory . DIRECTORY_SEPARATOR . 'default.json.lock');
            @\rmdir($directory);
        }
    }
}
