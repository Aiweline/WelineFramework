<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Test\Business;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Session\Business\AbstractBusinessSession;
use Weline\Framework\Session\SessionInterface;

final class AbstractBusinessSessionTest extends TestCase
{
    public function testEveryOperationUsesTheCurrentRequestSession(): void
    {
        $first = $this->createMock(SessionInterface::class);
        $second = $this->createMock(SessionInterface::class);

        $first->expects(self::once())->method('set')->with('probe_value', 'first');
        $second->expects(self::once())->method('set')->with('probe_value', 'second');

        $businessSession = new class($first) extends AbstractBusinessSession {
            protected const PREFIX = 'probe_';

            private SessionInterface $current;

            public function __construct(SessionInterface $session)
            {
                parent::__construct($session);
                $this->current = $session;
            }

            public function use(SessionInterface $session): void
            {
                $this->current = $session;
            }

            public function write(string $value): void
            {
                $this->set('value', $value);
            }

            public function getSession(): SessionInterface
            {
                return $this->current;
            }
        };

        $businessSession->write('first');
        $businessSession->use($second);
        $businessSession->write('second');
    }
}
