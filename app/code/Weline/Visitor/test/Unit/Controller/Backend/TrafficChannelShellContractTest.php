<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Acl\Acl;
use Weline\Visitor\Controller\Backend\TrafficChannel;

/**
 * B03–B05：TrafficChannel 列表/新建/编辑 ACL 与模板契约。
 */
final class TrafficChannelShellContractTest extends TestCase
{
    public function testControllerDeclaresTrafficChannelAclTree(): void
    {
        $class = new ReflectionClass(TrafficChannel::class);
        $classAcls = $class->getAttributes(Acl::class);
        self::assertCount(1, $classAcls);
        /** @var Acl $classAcl */
        $classAcl = $classAcls[0]->newInstance();
        self::assertSame('Weline_Visitor::traffic_channel', $classAcl->getData('source_id'));
        self::assertSame('Weline_Backend::data_tools_group', $classAcl->getData('parent_source'));

        $index = $class->getMethod('index');
        $methodAcls = $index->getAttributes(Acl::class);
        self::assertCount(1, $methodAcls);
        /** @var Acl $methodAcl */
        $methodAcl = $methodAcls[0]->newInstance();
        self::assertSame('Weline_Visitor::traffic_channel_index', $methodAcl->getData('source_id'));
    }

    public function testMenuXmlRegistersTrafficChannelUnderDataTools(): void
    {
        $menu = (string)\file_get_contents(
            dirname(__DIR__, 4) . '/etc/backend/menu.xml'
        );
        self::assertStringContainsString('Weline_Visitor::traffic_channel', $menu);
        self::assertStringContainsString('visitor/backend/traffic-channel/index', $menu);
        self::assertStringContainsString('Weline_Backend::data_tools_group', $menu);
    }

    public function testCreateEditAndToggleActionsExistWithReadonlyCodeInForm(): void
    {
        $root = dirname(__DIR__, 4);
        $ref = new ReflectionClass(TrafficChannel::class);
        foreach (['getAdd', 'postAdd', 'getEdit', 'postEdit', 'postToggleEnabled', 'getDetail'] as $method) {
            self::assertTrue($ref->hasMethod($method), $method);
        }
        self::assertFalse($ref->hasMethod('postDelete'));
        self::assertFalse($ref->hasMethod('delete'));

        self::assertSame(
            'Weline_Visitor::traffic_channel_edit',
            $ref->getMethod('getEdit')->getAttributes(Acl::class)[0]->newInstance()->getData('source_id')
        );
        self::assertSame(
            'Weline_Visitor::traffic_channel_detail',
            $ref->getMethod('getDetail')->getAttributes(Acl::class)[0]->newInstance()->getData('source_id')
        );
        self::assertSame(
            'Weline_Visitor::traffic_channel_toggle',
            $ref->getMethod('postToggleEnabled')->getAttributes(Acl::class)[0]->newInstance()->getData('source_id')
        );

        $index = (string)\file_get_contents($root . '/view/templates/Backend/TrafficChannel/index.phtml');
        self::assertStringContainsString('traffic-channel/getEdit', $index);
        self::assertStringContainsString('traffic-channel/getDetail', $index);
        self::assertStringContainsString('postToggleEnabled', $index);
        self::assertStringContainsString('停用', $index);
        self::assertStringContainsString('详情', $index);

        $form = (string)\file_get_contents($root . '/view/templates/Backend/TrafficChannel/form.phtml');
        self::assertStringContainsString('code_readonly', $form);
        self::assertStringContainsString('渠道码创建后不可修改', $form);
        self::assertStringContainsString('readonly', $form);
        // B06：投放链接预览/复制
        self::assertStringContainsString('channel-landing-url', $form);
        self::assertStringContainsString('复制链接', $form);
        self::assertStringContainsString('landing_path', $form);
        self::assertFileExists($root . '/Service/PixelChannelLandingUrlService.php');
        self::assertStringContainsString('js-copy-landing', $index);
    }
}
