<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\MediaReference;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Service\MediaReference\MediaReferenceIdentityBuilder;
use Weline\FileManager\Service\MediaReference\MediaReferenceScopeResolver;
use Weline\Framework\Event\ResourceChange\ResourceChange;

final class MediaReferenceIdentityBuilderTest extends TestCase
{
    public function testBuildProductPathKeepsScopeAndSkuSeparate(): void
    {
        $builder = new MediaReferenceIdentityBuilder(new MediaReferenceScopeResolver());
        $identity = $builder->build(
            'default.default.default',
            'product',
            'HF-HANFU-188',
            ['kind' => 'media', 'role' => 'main'],
        );
        self::assertSame('product', $identity->root);
        self::assertSame('default.default.default', $identity->scope);
        self::assertSame('HF-HANFU-188', $identity->code);
        self::assertStringContainsString('sku:HF-HANFU-188', $identity->path);
        self::assertStringContainsString('scope:default.default.default', $identity->path);
        self::assertSame('HF-HANFU-188', $identity->tags['sku']);
        self::assertSame('default.default.default', $identity->tags['scope']);
        self::assertStringNotContainsString('~', $identity->path);
    }

    public function testMissingScopeWithoutContextFails(): void
    {
        $builder = new MediaReferenceIdentityBuilder(new MediaReferenceScopeResolver());
        $this->expectException(\InvalidArgumentException::class);
        $builder->build(null, 'product', 'SKU-1', []);
    }

    public function testResourceChangeAcceptsOptionalScopeAndCode(): void
    {
        $payload = [
            'schema_version' => ResourceChange::SCHEMA_VERSION,
            'event_id' => '0123456789abcdef0123456789abcdef',
            'event_name' => ResourceChange::EVENT_NAME,
            'occurred_at' => '2026-07-23T03:16:00.123456Z',
            'resource' => [
                'type' => 'product',
                'id' => '12',
                'action' => 'delete',
                'revision' => 1,
                'code' => 'HF-HANFU-188',
                'scope' => 'default.default.default',
            ],
            'website' => [
                'id' => 0,
                'code' => 'default',
                'previous_code' => null,
                'site_id' => 0,
            ],
            'impact' => [
                'namespaces' => [],
                'previous_namespaces' => [],
                'urls' => [],
                'previous_urls' => [],
            ],
            'changed_fields' => [],
            'before' => ['sku' => 'HF-HANFU-188'],
            'after' => null,
            'origin' => [
                'area' => 'backend',
                'entry' => 'test',
                'request_id' => '',
                'instance' => '',
                'trigger_by' => ['type' => 'system', 'id' => null],
            ],
            'context' => [
                'website_id' => 0,
                'website_code' => 'default',
                'lang' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'area' => 'backend',
                'timezone' => 'Asia/Shanghai',
                'user' => ['type' => 'system', 'id' => null],
            ],
        ];
        $change = ResourceChange::fromArray($payload);
        self::assertSame('HF-HANFU-188', $change->resourceCode());
        self::assertSame('default.default.default', $change->resourceScope());
    }

    public function testResourceChangeStillValidWithoutScope(): void
    {
        $payload = [
            'schema_version' => ResourceChange::SCHEMA_VERSION,
            'event_id' => '0123456789abcdef0123456789abcdef',
            'event_name' => ResourceChange::EVENT_NAME,
            'occurred_at' => '2026-07-23T03:16:00.123456Z',
            'resource' => [
                'type' => 'product',
                'id' => '12',
                'action' => 'delete',
                'revision' => 1,
                'code' => 'HF-HANFU-188',
            ],
            'website' => [
                'id' => 0,
                'code' => 'default',
                'previous_code' => null,
                'site_id' => 0,
            ],
            'impact' => [
                'namespaces' => [],
                'previous_namespaces' => [],
                'urls' => [],
                'previous_urls' => [],
            ],
            'changed_fields' => [],
            'before' => [],
            'after' => null,
            'origin' => [
                'area' => 'backend',
                'entry' => 'test',
                'request_id' => '',
                'instance' => '',
                'trigger_by' => ['type' => 'system', 'id' => null],
            ],
            'context' => [
                'website_id' => 0,
                'website_code' => 'default',
                'lang' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'area' => 'backend',
                'timezone' => 'Asia/Shanghai',
                'user' => ['type' => 'system', 'id' => null],
            ],
        ];
        $change = ResourceChange::fromArray($payload);
        self::assertSame('HF-HANFU-188', $change->resourceCode());
        self::assertNull($change->resourceScope());
    }
}
