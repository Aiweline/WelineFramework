<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Health;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Health\HealthIssueCatalog;
use Weline\Seo\Service\Health\IssueClassifier;

final class HealthIssueClassifierTest extends TestCase
{
    private IssueClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new IssueClassifier();
    }

    public function test404(): void
    {
        $result = $this->classifier->classify($this->probe(404));

        self::assertSame(HealthIssueCatalog::NOT_FOUND, $result['issue_code']);
    }

    public function test503(): void
    {
        $result = $this->classifier->classify($this->probe(503));

        self::assertSame(HealthIssueCatalog::SERVER_503, $result['issue_code']);
    }

    public function testOther5xx(): void
    {
        $result = $this->classifier->classify($this->probe(500));

        self::assertSame(HealthIssueCatalog::SERVER_5XX, $result['issue_code']);
    }

    public function test403WithoutEdgeMarkersIsForbidden(): void
    {
        $result = $this->classifier->classify($this->probe(403, '', ['server' => 'nginx']));

        self::assertSame(HealthIssueCatalog::FORBIDDEN, $result['issue_code']);
    }

    public function test403FromCloudflareProxyWithoutChallengeIsForbidden(): void
    {
        $result = $this->classifier->classify($this->probe(403, '', ['server' => 'cloudflare']));

        self::assertSame(HealthIssueCatalog::FORBIDDEN, $result['issue_code']);
    }

    public function test403WithCloudflareChallengeIsEdgeBlocked(): void
    {
        $result = $this->classifier->classify($this->probe(403, '', ['cf-mitigated' => 'challenge']));

        self::assertSame(HealthIssueCatalog::EDGE_BLOCKED, $result['issue_code']);
    }

    public function testSameOriginRedirectIsRedirectOk(): void
    {
        $probe = $this->probe(200);
        $probe['final_url'] = 'https://example.com/new';
        $probe['redirects'] = [['status' => 301, 'location' => 'https://example.com/new']];

        $result = $this->classifier->classify($probe);

        self::assertSame(HealthIssueCatalog::REDIRECT_OK, $result['issue_code']);
    }

    public function testCrossOriginRedirectIsExternal(): void
    {
        $probe = $this->probe(200);
        $probe['final_url'] = 'https://other.example/new';
        $probe['redirects'] = [['status' => 301, 'location' => 'https://other.example/new']];

        $result = $this->classifier->classify($probe);

        self::assertSame(HealthIssueCatalog::REDIRECT_EXTERNAL, $result['issue_code']);
    }

    public function testRedirectEndingIn404IsBroken(): void
    {
        $probe = $this->probe(404);
        $probe['redirects'] = [['status' => 301, 'location' => 'https://example.com/new']];

        $result = $this->classifier->classify($probe);

        self::assertSame(HealthIssueCatalog::REDIRECT_BROKEN, $result['issue_code']);
    }

    public function testRobotsBlockedWinsOverSoft404(): void
    {
        $result = $this->classifier->classify(
            $this->probe(200, '<html><title>页面不存在</title><body>页面不存在</body></html>'),
            ['state' => 'blocked', 'rule' => '/private/', 'line' => 3],
            ['matched' => true, 'reason' => 'title matches']
        );

        self::assertSame(HealthIssueCatalog::ROBOTS_BLOCKED, $result['issue_code']);
    }

    public function testSoft404IsReported(): void
    {
        $result = $this->classifier->classify(
            $this->probe(200, '<html><title>页面不存在</title><body>页面不存在</body></html>'),
            ['state' => 'allowed'],
            ['matched' => true, 'reason' => 'title matches']
        );

        self::assertSame(HealthIssueCatalog::SOFT_404, $result['issue_code']);
    }

    public function testNoindexIsReported(): void
    {
        $body = '<html><head><meta name="robots" content="noindex,follow"><title>Hidden</title></head><body>Hello</body></html>';
        $result = $this->classifier->classify($this->probe(200, $body));

        self::assertSame(HealthIssueCatalog::NOINDEX_IN_SITEMAP, $result['issue_code']);
    }

    public function testCanonicalMismatchIsReported(): void
    {
        $body = '<html><head><link rel="canonical" href="https://example.com/canonical"><title>Page</title></head><body>Hello</body></html>';
        $result = $this->classifier->classify($this->probe(200, $body));

        self::assertSame(HealthIssueCatalog::CANONICAL_MISMATCH, $result['issue_code']);
    }

    public function testHealthyPageIsOk(): void
    {
        $body = '<html><head><title>Product</title></head><body>' . str_repeat('正常内容 ', 100) . '</body></html>';
        $result = $this->classifier->classify($this->probe(200, $body));

        self::assertSame(HealthIssueCatalog::OK, $result['issue_code']);
    }

    public function testTimeout(): void
    {
        $probe = $this->probe(0);
        $probe['error_type'] = 'timeout';
        $probe['error'] = 'Operation timed out';

        self::assertSame(HealthIssueCatalog::TIMEOUT, $this->classifier->classify($probe)['issue_code']);
    }

    public function testDnsError(): void
    {
        $probe = $this->probe(0);
        $probe['error_type'] = 'dns';
        $probe['error'] = 'Could not resolve host';

        self::assertSame(HealthIssueCatalog::DNS_TLS_ERROR, $this->classifier->classify($probe)['issue_code']);
    }

    public function testConnectError(): void
    {
        $probe = $this->probe(0);
        $probe['error_type'] = 'connect';
        $probe['error'] = 'Connection refused';

        self::assertSame(HealthIssueCatalog::CONNECTION_ERROR, $this->classifier->classify($probe)['issue_code']);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function probe(int $status, string $body = '', array $headers = []): array
    {
        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'final_url' => 'https://example.com/page',
            'requested_url' => 'https://example.com/page',
            'redirects' => [],
            'redirect_loop' => false,
            'error' => '',
            'error_type' => '',
            'duration_ms' => 12,
            'content_type' => 'text/html',
        ];
    }
}
