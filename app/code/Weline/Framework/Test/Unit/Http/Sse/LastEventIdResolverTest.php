<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Sse;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\LastEventIdResolver;

class LastEventIdResolverTest extends TestCase
{
    public function testPrefersNewestPositiveValueAcrossQueryAndHeader(): void
    {
        $request = new class {
            public function getGet(string $key = '', mixed $default = null): mixed
            {
                return $key === 'last_event_id' ? '41' : $default;
            }

            public function getHeader(string $key = ''): mixed
            {
                return $key === 'Last-Event-ID' ? '57' : null;
            }
        };

        $this->assertSame(57, LastEventIdResolver::resolve($request));
    }

    public function testFallsBackToServerHeaderWhenRequestHeaderIsUnavailable(): void
    {
        $request = new class {
            public function getGet(string $key = '', mixed $default = null): mixed
            {
                return $default;
            }

            public function getHeader(string $key = ''): mixed
            {
                return null;
            }
        };

        $previous = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
        $_SERVER['HTTP_LAST_EVENT_ID'] = '19';

        try {
            $this->assertSame(19, LastEventIdResolver::resolve($request));
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_LAST_EVENT_ID']);
            } else {
                $_SERVER['HTTP_LAST_EVENT_ID'] = $previous;
            }
        }
    }

    public function testIgnoresInvalidOrNegativeValues(): void
    {
        $request = new class {
            public function getGet(string $key = '', mixed $default = null): mixed
            {
                return '-12';
            }

            public function getHeader(string $key = ''): mixed
            {
                return 'not-a-number';
            }
        };

        $this->assertSame(0, LastEventIdResolver::resolve($request));
    }
}
