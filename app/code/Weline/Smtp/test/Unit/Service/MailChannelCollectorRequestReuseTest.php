<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\App\State;
use Weline\Framework\Runtime\RequestContext;
use Weline\Smtp\Api\MailChannelProviderInterface;
use Weline\Smtp\Service\MailChannelCollector;

final class MailChannelCollectorRequestReuseTest extends TestCase
{
    protected function tearDown(): void
    {
        State::setRequestLanguageOverride('');
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
    }

    public function testDeclaredChannelsAreCollectedOncePerRequestAndRefreshedNextRequest(): void
    {
        $provider = new class implements MailChannelProviderInterface {
            public int $calls = 0;
            public string $code = 'first';

            public function getChannels(): array
            {
                $this->calls++;
                return [['code' => $this->code, 'name' => $this->code]];
            }
        };
        $collector = new class($provider) extends MailChannelCollector {
            public function __construct(private readonly MailChannelProviderInterface $fixture) {}

            protected function getProviders(): array
            {
                return [$this->fixture];
            }
        };

        Context::enter(new Context());
        RequestContext::init();
        self::assertSame('first', $collector->collect()[0]['code']);
        self::assertSame('first', $collector->collect()[0]['code']);
        self::assertSame(1, $provider->calls);

        $otherProvider = new class implements MailChannelProviderInterface {
            public function getChannels(): array
            {
                return [['code' => 'other', 'name' => 'other']];
            }
        };
        $otherCollector = new class($otherProvider) extends MailChannelCollector {
            public function __construct(private readonly MailChannelProviderInterface $fixture) {}

            protected function getProviders(): array
            {
                return [$this->fixture];
            }
        };
        self::assertSame('other', $otherCollector->collect()[0]['code']);

        $currentLanguage = State::getLangLocal();
        State::setRequestLanguageOverride($currentLanguage === 'en_US' ? 'zh_Hans_CN' : 'en_US');
        $collector->collect();
        self::assertSame(2, $provider->calls, 'Translated channel declarations must refresh when the active language changes.');

        RequestContext::cleanup();
        RequestContext::init();
        State::setRequestLanguageOverride('');
        $provider->code = 'second';
        self::assertSame('second', $collector->collect()[0]['code']);
        self::assertSame(3, $provider->calls);
    }
}
