<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxEdgeAvailability;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPublicPortProbeInterface;

/**
 * auto 网关占用门禁：看 80/443 外人占用，不看系统 nginx 二进制是否存在。
 */
final class ManagedNginxEdgeAvailabilityPortOccupancyContractTest extends TestCase
{
    public function testHostOccupiedWhenEitherPublicPortIsForeign(): void
    {
        $probe = new FakePublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_FREE,
            443 => ManagedNginxPublicPortProbeInterface::STATE_FOREIGN,
        ]);
        $availability = new ManagedNginxEdgeAvailability(null, null, $probe);

        self::assertTrue($availability->hostNginxOccupied());
        self::assertSame([80, 443], \array_slice($probe->inspectedPorts, 0, 2));
        self::assertStringContainsString('foreign listener', $availability->unavailableReason());
    }

    public function testUnbindablePrivilegedPortsDoNotBlockManagedAutoEdge(): void
    {
        $probe = new FakePublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_UNBINDABLE,
            443 => ManagedNginxPublicPortProbeInterface::STATE_UNBINDABLE,
        ]);
        $availability = new ManagedNginxEdgeAvailability(null, null, $probe);

        self::assertFalse(
            $availability->hostNginxOccupied(),
            'weline 用户探测特权端口 UNBINDABLE 是常态；托管 Nginx 靠 setcap 绑定，不得因此降级纯 WLS',
        );
        self::assertSame([80, 443], $probe->inspectedPorts);
    }

    public function testHostOccupiedWhenPublicPortIsForeignOnly(): void
    {
        $probe = new FakePublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_FOREIGN,
            443 => ManagedNginxPublicPortProbeInterface::STATE_FREE,
        ]);
        $availability = new ManagedNginxEdgeAvailability(null, null, $probe);

        self::assertTrue($availability->hostNginxOccupied());
        self::assertSame([80], $probe->inspectedPorts);
    }

    public function testHostNotOccupiedWhenPortsAreFreeEvenIfSystemNginxBinaryExists(): void
    {
        $probe = new FakePublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_FREE,
            443 => ManagedNginxPublicPortProbeInterface::STATE_FREE,
        ]);
        $availability = new ManagedNginxEdgeAvailability(null, null, $probe);

        self::assertFalse($availability->hostNginxOccupied());
        self::assertSame([80, 443], $probe->inspectedPorts);
    }

    public function testHostNotOccupiedWhenPortsHeldBySelfManagedNginx(): void
    {
        $probe = new FakePublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_SELF,
            443 => ManagedNginxPublicPortProbeInterface::STATE_SELF,
        ]);
        $availability = new ManagedNginxEdgeAvailability(null, null, $probe);

        self::assertFalse(
            $availability->hostNginxOccupied(),
            '本项目托管 Nginx 已听 80/443 时 auto 必须继续认领 legacy 网关，不得当外人占用降级纯 WLS',
        );
    }

    public function testExplicitProbeClosureStillOverridesPortInspect(): void
    {
        $probe = new FakePublicPortProbe([
            80 => ManagedNginxPublicPortProbeInterface::STATE_FREE,
            443 => ManagedNginxPublicPortProbeInterface::STATE_FREE,
        ]);
        $availability = new ManagedNginxEdgeAvailability(
            null,
            static fn (): bool => true,
            $probe,
        );

        self::assertTrue($availability->hostNginxOccupied());
        self::assertSame([], $probe->inspectedPorts);
    }
}

final class FakePublicPortProbe implements ManagedNginxPublicPortProbeInterface
{
    /** @var list<int> */
    public array $inspectedPorts = [];

    /**
     * @param array<int, string> $statesByPort
     */
    public function __construct(private readonly array $statesByPort)
    {
    }

    public function inspect(int $port): array
    {
        $this->inspectedPorts[] = $port;
        $state = $this->statesByPort[$port] ?? self::STATE_FREE;

        return [
            'state' => $state,
            'pid' => $state === self::STATE_FOREIGN ? 4242 : 0,
            'pname' => $state === self::STATE_FOREIGN ? 'nginx' : '',
            'detail' => 'fake:' . $state,
        ];
    }
}
