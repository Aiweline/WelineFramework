<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\TranslationCollector;

/**
 * Acl 属性里的 source_name / document 在运行时会经 __()，收集器必须能扫到，
 * 否则 setup:upgrade 整表重写 CSV 会丢掉 ACL 文案。
 */
final class TranslationCollectorAclAttributeContractTest extends TestCase
{
    public function testCollectExtractsAclSourceNameAndDocument(): void
    {
        $dir = sys_get_temp_dir() . '/weline-i18n-acl-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0777, true));
        $php = <<<'PHP'
<?php
namespace Demo;
use Weline\Framework\Acl\Acl;
#[Acl('Demo::resource', '演示资源名', 'mdi-test', '演示资源说明', 'Demo::parent')]
class Sample
{
    #[Acl('Demo::action', '演示动作', 'mdi-action', '演示动作说明')]
    public function run(): void {}
}
PHP;
        file_put_contents($dir . '/Sample.php', $php);

        try {
            $collector = new TranslationCollector();
            $words = $collector->collect($dir, 'Demo_Module');

            self::assertArrayHasKey('演示资源名', $words);
            self::assertArrayHasKey('演示资源说明', $words);
            self::assertArrayHasKey('演示动作', $words);
            self::assertArrayHasKey('演示动作说明', $words);
            self::assertArrayNotHasKey('Demo::resource', $words);
            self::assertArrayNotHasKey('mdi-test', $words);
            self::assertSame('Acl', $words['演示资源名']['context'] ?? null);
        } finally {
            @unlink($dir . '/Sample.php');
            @rmdir($dir);
        }
    }
}
