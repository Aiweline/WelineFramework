<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Service\GroupMemberDisplayPresenter;

require_once dirname(__DIR__) . '/bootstrap.php';

final class GroupMemberDisplayPresenterTest extends TestCase
{
    public function testEnrichPutsReadableNamePrimaryAndWebsiteLabel(): void
    {
        $presenter = new GroupMemberDisplayPresenter(
            static fn (int $id): ?array => $id === 47
                ? [
                    'label' => 'alice',
                    'email' => 'alice@example.com',
                    'username' => 'alice',
                    'meta' => 'alice@example.com',
                ]
                : null,
            static fn (int $wid): string => $wid === 0 ? '默认站' : ('站点 #' . $wid),
        );

        $enriched = $presenter->enrich([
            [
                'customer_id' => '47',
                'website_id' => 0,
                'group_id' => 'vip0',
                'updated_at' => '2026-09-10 08:44:13',
            ],
        ]);

        self::assertCount(1, $enriched);
        self::assertSame('alice', $enriched[0]['display_name']);
        self::assertSame('alice@example.com', $enriched[0]['email']);
        self::assertSame('默认站', $enriched[0]['website_name']);
        self::assertSame('47', $enriched[0]['customer_id']);
    }

    public function testFilterMatchesNameEmailOrId(): void
    {
        $presenter = new GroupMemberDisplayPresenter(
            static fn (int $id): ?array => match ($id) {
                47 => [
                    'label' => 'alice',
                    'email' => 'alice@example.com',
                    'username' => 'alice',
                    'meta' => 'alice@example.com',
                ],
                48 => [
                    'label' => 'bob',
                    'email' => 'bob@example.com',
                    'username' => 'bob',
                    'meta' => 'bob@example.com',
                ],
                default => null,
            },
            static fn (int $wid): string => '默认站',
        );

        $members = $presenter->enrich([
            ['customer_id' => '47', 'website_id' => 0, 'group_id' => 'vip0', 'updated_at' => ''],
            ['customer_id' => '48', 'website_id' => 0, 'group_id' => 'vip0', 'updated_at' => ''],
        ]);

        self::assertCount(1, $presenter->filter($members, 'alice'));
        self::assertCount(1, $presenter->filter($members, 'bob@example.com'));
        self::assertCount(1, $presenter->filter($members, '47'));
        self::assertCount(0, $presenter->filter($members, 'nobody'));
    }
}
