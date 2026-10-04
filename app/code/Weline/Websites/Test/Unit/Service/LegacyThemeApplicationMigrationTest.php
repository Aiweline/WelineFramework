<?php
declare(strict_types=1);
namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;
use Weline\Websites\Api\Theme\ThemeApplicationRepositoryInterface;
use Weline\Websites\Api\Theme\ThemeApplicationReference;
use Weline\Websites\Service\ThemeApplicationService;
use Weline\Websites\Service\LegacyThemeApplicationMigration;

final class LegacyThemeApplicationMigrationTest extends TestCase
{
    public function testUpgradeBacksUpBeforeSavingAndPreservesExactOwnerAndIndependentApplications(): void
    {
        self::assertTrue(class_exists(LegacyThemeApplicationMigration::class));
        self::assertSame(realpath(dirname(__DIR__, 3).'/Service/LegacyThemeApplicationMigration.php'), (new \ReflectionClass(LegacyThemeApplicationMigration::class))->getFileName());
        $directory=sys_get_temp_dir().'/weline_application_migration_'.bin2hex(random_bytes(6));
        $repository=new class($directory) implements ThemeApplicationRepositoryInterface {
            public array $rows=[];
            public function __construct(private string $directory) {}
            public function read(string $scopeKey,string $storeMode,string $area):array {return $this->rows[$scopeKey.'|'.$storeMode]??['reference'=>null,'revision'=>0];}
            public function compareAndSwap(string $scopeKey,string $storeMode,string $area,?ThemeApplicationReference $reference,int $expectedRevision):array {
                if(count(glob($this->directory.'/*/backup.json')?:[])!==1)throw new \RuntimeException('backup_missing_before_write');
                $current=$this->read($scopeKey,$storeMode,$area);
                if($current['revision']!==$expectedRevision)throw new \RuntimeException('revision_conflict');
                return $this->rows[$scopeKey.'|'.$storeMode]=['reference'=>$reference,'revision'=>$expectedRevision+1];
            }
        };
        $bindings=[
            ['scope'=>'default.default.default','store_mode'=>'normal','area'=>'frontend','theme_id'=>1,'theme_version_id'=>1,'content_revision'=>6,'owner_scope'=>'default.default.default','owner_store_mode'=>'normal','own_intent'=>true],
            ['scope'=>'default.__website__.default','store_mode'=>'normal','area'=>'frontend','theme_id'=>3,'theme_version_id'=>9,'content_revision'=>2,'owner_scope'=>'default.__website__.default','owner_store_mode'=>'normal','own_intent'=>true],
            ['scope'=>'default.child.default','store_mode'=>'normal','area'=>'frontend','theme_id'=>1,'theme_version_id'=>1,'content_revision'=>6,'owner_scope'=>'default.default.default','owner_store_mode'=>'normal','own_intent'=>true],
            ['scope'=>'default.inherited.default','store_mode'=>'normal','area'=>'frontend','theme_id'=>3,'theme_version_id'=>9,'content_revision'=>2,'owner_scope'=>'default.__website__.default','owner_store_mode'=>'normal','own_intent'=>false],
            ['scope'=>'default.test_store.__channel__','store_mode'=>'test','area'=>'frontend','theme_id'=>1,'theme_version_id'=>1156,'content_revision'=>11,'owner_scope'=>'default.test_store.__channel__','owner_store_mode'=>'test','own_intent'=>true],
            ['scope'=>'default.dev_store.__channel__','store_mode'=>'dev','area'=>'frontend','theme_id'=>1,'theme_version_id'=>1157,'content_revision'=>3,'owner_scope'=>'default.dev_store.__channel__','owner_store_mode'=>'dev','own_intent'=>true],
        ];
        $reader=$this->createStub(ThemeApplicationReferenceReaderInterface::class);
        $reader->method('exportLegacyApplicationSnapshot')->willReturn(['schema'=>'theme-application-legacy-export.v1','bindings'=>$bindings,'active_defaults'=>['backend'=>['theme_id'=>1]],'unresolved'=>[['reason'=>'legacy_version_selection_missing']],'source_records'=>['immutable_original'=>'saved']]);
        $reader->method('validateReference')->willReturnCallback(static fn(array $reference):array=>$reference);
        $catalog=$this->createStub(ScopeIdentityCatalogInterface::class);
        $catalog->method('authoritativeIdentity')->willReturnCallback(static function(ScopeIdentity $identity):ScopeIdentity {
            $mode=match($identity->storeCode){'test_store'=>'test','dev_store'=>'dev',default=>null};
            if($mode!==null && $identity->storeMode!==$mode)throw new \InvalidArgumentException('system_config_scope_claim_identity_mismatch');
            return $identity;
        });
        $service=new LegacyThemeApplicationMigration($reader,new SystemConfigScopeResolver(),$catalog,new ThemeApplicationService($repository));
        try {
            $first=$service->migrate($directory);
            self::assertSame(5,$first['saved']);
            self::assertCount(1,$first['unresolved']);
            self::assertCount(5,$repository->rows);
            self::assertSame(1156,$repository->read(ScopeIdentity::channel(0,'default','test_store','default','test')->canonicalKey(),'test','frontend')['reference']->themeVersionId);
            self::assertSame(1157,$repository->read(ScopeIdentity::channel(0,'default','dev_store','default','dev')->canonicalKey(),'dev','frontend')['reference']->themeVersionId);
            $website=$repository->read(ScopeIdentity::website(0,'default')->canonicalKey(),'normal','frontend');
            self::assertSame([3,9,2,'default.__website__.default'],[$website['reference']->themeId,$website['reference']->themeVersionId,$website['reference']->contentRevision,$website['reference']->versionOwnerScope]);
            $second=$service->migrate($directory);
            self::assertSame(0,$second['saved']);
            self::assertSame(1,$repository->read(ScopeIdentity::website(0,'default')->canonicalKey(),'normal','frontend')['revision']);
            $backup=json_decode(file_get_contents($first['backup_path']),true,flags:JSON_THROW_ON_ERROR);
            self::assertSame('saved',$backup['legacy_export']['source_records']['immutable_original']);
            self::assertCount(5,$backup['manifest']['applications']);
            self::assertSame(0,$backup['manifest']['applications'][1]['current_revision']);
            self::assertNull($backup['manifest']['applications'][1]['current_reference']);
            $key=ScopeIdentity::website(0,'default')->canonicalKey().'|normal';
            $repository->rows[$key]=['reference'=>null,'revision'=>2];
            $third=$service->migrate($directory);
            self::assertSame(0,$third['saved']);
            self::assertSame(['reference'=>null,'revision'=>2],$repository->rows[$key]);
            $thirdBackup=json_decode(file_get_contents($third['backup_path']),true,flags:JSON_THROW_ON_ERROR);
            self::assertSame(2,$thirdBackup['manifest']['applications'][1]['current_revision']);
            self::assertNull($thirdBackup['manifest']['applications'][1]['current_reference']);
        } finally {
            foreach(glob($directory.'/*/*')?:[] as $file)unlink($file);
            foreach(glob($directory.'/*')?:[] as $folder)rmdir($folder);
            if(is_dir($directory))rmdir($directory);
        }
    }
}
