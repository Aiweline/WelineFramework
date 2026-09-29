<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;

final class ThemeVersionLegacyCurrentMappingTest extends TestCase
{
    public function testCanonicalIdentityWinsOverLegacyLocalizedLayoutWithoutChangingEitherRow(): void
    {
        [$context,$identity,$rows]=$this->inputs('draft');$before=$rows;
        $result=$this->map($identity,$context,$rows);
        self::assertCount(1,$result);
        self::assertSame(2,$result[0]['row']['workspace_id']);
        self::assertSame(1958,$result[0]['intent']);
        self::assertSame($before,$rows);
    }

    public function testSealedCurrentUsesItsPublishedReleaseEvenWhenItIsAlsoCurrent(): void
    {
        [$context,$identity,$rows]=$this->inputs('formal');
        $result=$this->map($identity,$context,$rows);
        self::assertSame(0,$result[0]['intent']);
        self::assertSame(284,$result[0]['release']);
    }

    public function testAmbiguousUnprovenRowsRemainAnErrorWithExactOwnerAndWorkspaceDiagnostics(): void
    {
        [$context,$identity,$rows]=$this->inputs('draft');
        $rows[0]['identity_hash']=str_repeat('b',64);
        try{$this->map($identity,$context,$rows);self::fail('Unproven ambiguity must not pick a current row.');}
        catch(\RuntimeException $error){
            self::assertStringContainsString('theme_version_current_resource_ambiguous',$error->getMessage());
            self::assertStringContainsString('V1/R5',$error->getMessage());
            self::assertStringContainsString('default.default.default',$error->getMessage());
            self::assertStringContainsString('2,18',$error->getMessage());
        }
    }

    private function map($identity,$context,$rows): array
    {
        return (new \ReflectionMethod(ThemeVersionResourceSnapshotService::class,'migrationResources'))
            ->invoke(new ThemeVersionResourceSnapshotService(),$identity,$context,$rows,false);
    }

    private function inputs(string $mode): array
    {
        $scope=new ScopeContext(ScopeIdentity::website(0,'default'),'default.default.default','normal',['default.default.default']);
        $context=new ThemeEditorContext($scope,'frontend','layout',1,'homepage','default');
        $identity=new ThemeVersionIdentity(1,'default.default.default','normal','frontend',1,$mode,5);
        $row=['workspace_id'=>2,'identity_hash'=>$context->identityHash(),'resource_type'=>'layout','layout_type'=>'homepage',
            'layout_option'=>'default','locale'=>'default','target_type'=>'global','target_id'=>0,'draft_revision_id'=>1958,'published_release_id'=>284];
        return [$context,$identity,[$row,array_replace($row,['workspace_id'=>18,'identity_hash'=>str_repeat('a',64),'locale'=>'zh_Hans_CN','draft_revision_id'=>289,'published_release_id'=>null])]];
    }
}
