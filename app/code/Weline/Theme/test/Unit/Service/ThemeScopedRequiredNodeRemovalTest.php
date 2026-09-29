<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer;
use Weline\Theme\Service\Scoped\ThemePatchEngine;
use Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService;
use Weline\Theme\Service\WidgetImageContentContractValidator;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;

final class ThemeScopedRequiredNodeRemovalTest extends TestCase
{
    public function testRemovingRenderedDefaultKeepsItsCanonicalUninstallIntent(): void
    {
        $uid=str_repeat('a',32);
        $node=['node_uid'=>$uid,'widget_module'=>'Weline_Test','widget_type'=>'content','widget_code'=>'music',
            'area'=>'content','slot_id'=>'content','is_active'=>true,'source'=>'default_injection','config'=>['volume'=>25]];
        $context=new ThemeEditorContext(new ScopeContext(ScopeIdentity::website(0,'default'),'default.__store__.__channel__','normal',['default.__store__.__channel__']),
            'frontend','layout',1,'homepage');
        $workspace=$this->createMock(ThemeScopedWorkspaceInterface::class);
        $workspace->method('load')->willReturn(['revision'=>0,'expected_parent_release_id'=>284,'draft_payload'=>['nodes'=>[$uid=>$node]]]);
        $workspace->expects(self::once())->method('applyChanges')->willReturnCallback(function($context,$expectedRevision,$expectedParentReleaseId,$changes)use($uid,$node):array{
            self::assertSame(0,$expectedRevision);self::assertSame(284,$expectedParentReleaseId);
            $payload=(new ThemePatchEngine())->apply(['nodes'=>[$uid=>$node]],$changes);
            return ['revision'=>1,'content_revision'=>2,'draft_payload'=>$payload];
        });
        $service=new ThemeScopedLayoutWriteService($workspace,
            (new \ReflectionClass(WidgetImageContentContractValidator::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ThemeLayoutSnapshotNormalizer::class))->newInstanceWithoutConstructor());
        $saved=$service->removeWidget($context,$uid,'test-actor');
        $nodes=$saved['workspace']['draft_payload']['nodes'];
        self::assertArrayHasKey($uid,$nodes,'The exact default UID must survive as an immutable tombstone.');
        self::assertFalse($nodes[$uid]['is_active']);
        self::assertSame('user_deleted',$nodes[$uid]['source']);
        self::assertSame(['volume'=>25],$nodes[$uid]['config']);
        $declarations=[['module'=>'Weline_Test','type'=>'content','code'=>'music','default_injections'=>[['layout_type'=>'homepage','slot'=>'content','area'=>'content','required'=>true]]]];
        $merged=RequiredDefaultInjectionContract::merge(['content'=>array_values($nodes)],'homepage',$declarations,[]);
        self::assertCount(1,$merged['content']);
        self::assertSame($uid,$merged['content'][0]['node_uid']);
        self::assertFalse($merged['content'][0]['is_active']);
    }
}
