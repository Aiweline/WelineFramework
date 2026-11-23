<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Health;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Health\HealthIssueCatalog;

final class HealthIssueGroupTest extends TestCase
{
    public function testRequiredGroupsExist(): void
    {
        $codes = array_column(HealthIssueCatalog::tabGroups(), 'code');

        foreach (['not_found', 'forbidden', 'other_4xx', 'server_error', 'soft_404', 'redirect', 'robots'] as $required) {
            self::assertContains($required, $codes, 'missing group: ' . $required);
        }
    }

    public function testGroupCoversIssues(): void
    {
        self::assertSame(
            [HealthIssueCatalog::NOT_FOUND, HealthIssueCatalog::GONE],
            HealthIssueCatalog::groupCodes('not_found')
        );
        self::assertSame(
            [HealthIssueCatalog::FORBIDDEN, HealthIssueCatalog::UNAUTHORIZED],
            HealthIssueCatalog::groupCodes('forbidden')
        );
        self::assertSame(
            [HealthIssueCatalog::SERVER_503, HealthIssueCatalog::SERVER_5XX],
            HealthIssueCatalog::groupCodes('server_error')
        );
        self::assertContains(
            HealthIssueCatalog::REDIRECT_BROKEN,
            HealthIssueCatalog::groupCodes('redirect')
        );
        self::assertSame(
            [HealthIssueCatalog::ROBOTS_BLOCKED],
            HealthIssueCatalog::groupCodes('robots')
        );
    }

    public function testIssueMapsToGroup(): void
    {
        self::assertSame('not_found', HealthIssueCatalog::groupForIssue(HealthIssueCatalog::NOT_FOUND));
        self::assertSame('forbidden', HealthIssueCatalog::groupForIssue(HealthIssueCatalog::FORBIDDEN));
        self::assertSame('other_4xx', HealthIssueCatalog::groupForIssue(HealthIssueCatalog::RATE_LIMITED));
        self::assertSame('server_error', HealthIssueCatalog::groupForIssue(HealthIssueCatalog::SERVER_503));
        self::assertSame('soft_404', HealthIssueCatalog::groupForIssue(HealthIssueCatalog::SOFT_404));
        self::assertSame('redirect', HealthIssueCatalog::groupForIssue(HealthIssueCatalog::REDIRECT_OK));
        self::assertSame('robots', HealthIssueCatalog::groupForIssue(HealthIssueCatalog::ROBOTS_BLOCKED));
        self::assertSame('other', HealthIssueCatalog::groupForIssue(HealthIssueCatalog::TIMEOUT));
    }

    public function testGroupCodesAreKnownIssues(): void
    {
        foreach (HealthIssueCatalog::tabGroups() as $group) {
            if ($group['code'] === 'all') {
                continue;
            }
            self::assertNotSame([], $group['codes'], 'empty group: ' . $group['code']);
            foreach ($group['codes'] as $code) {
                self::assertArrayHasKey($code, HealthIssueCatalog::all(), 'unknown issue in group: ' . $code);
            }
        }
    }

    public function testEveryLocalIssueBelongsToAGroup(): void
    {
        foreach (HealthIssueCatalog::issueCodes() as $code) {
            $group = HealthIssueCatalog::groupForIssue($code);
            self::assertContains($code, HealthIssueCatalog::groupCodes($group), 'issue not covered by group: ' . $code);
        }
    }
}
