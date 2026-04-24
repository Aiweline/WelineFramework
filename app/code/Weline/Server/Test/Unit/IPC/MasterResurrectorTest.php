<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterResurrector;
use Weline\Server\Service\MasterProcess;

/**
 * 覆盖以下场景：
 *   - 默认开关（allow_child_resurrection 默认 true）
 *   - shouldResurrect 对 shutdown / 服务异常 / 优先级 0 的拒绝
 *   - confirmAfterGrace 在 grace 窗内探测到 master 恢复 → 返回 false
 *   - confirmAfterGrace 在 grace 窗外仍不通 → 返回 true
 *   - 通过 env.php 覆盖 resurrect_grace_seconds
 */
final class MasterResurrectorTest extends TestCase
{
    /** 原始 env config 快照 */
    private ?array $originalConfig = null;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Env::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $currentEnv = $instance->getValue();
        if ($currentEnv instanceof Env) {
            $cfg = $ref->getProperty('config');
            $cfg->setAccessible(true);
            $this->originalConfig = $cfg->getValue($currentEnv);
        }
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Env::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $currentEnv = $instance->getValue();
        if ($currentEnv instanceof Env && $this->originalConfig !== null) {
            $cfg = $ref->getProperty('config');
            $cfg->setAccessible(true);
            $cfg->setValue($currentEnv, $this->originalConfig);
        }
    }

    public function testIsChildResurrectionEnabledDefaultsToTrue(): void
    {
        $this->setEnvConfig([]);

        $r = new MasterResurrector(
            ControlMessage::RESURRECTION_WORKER,
            'ut-default-on',
            '127.0.0.1',
            9999
        );

        $this->assertTrue($r->isChildResurrectionEnabled(), 'allow_child_resurrection 默认应为 true');
        $this->assertSame('on', $r->describeSelfHealMode());
    }

    public function testEnvConfigCanDisableSelfHeal(): void
    {
        $this->setEnvConfig([
            'wls' => ['orchestrator' => ['allow_child_resurrection' => false]],
        ]);

        $r = new MasterResurrector(
            ControlMessage::RESURRECTION_WORKER,
            'ut-disabled',
            '127.0.0.1',
            9999
        );

        $this->assertFalse($r->isChildResurrectionEnabled());
        $this->assertSame('off', $r->describeSelfHealMode());
        $this->assertFalse($r->shouldResurrect(false), '开关关闭时 shouldResurrect 必须返回 false');
    }

    public function testShouldResurrectReturnsFalseWhenShutdownReceived(): void
    {
        $this->setEnvConfig([]);

        $r = new MasterResurrector(
            ControlMessage::RESURRECTION_WORKER,
            'ut-shutdown',
            '127.0.0.1',
            9999
        );

        $this->assertFalse($r->shouldResurrect(true));
    }

    public function testShouldResurrectReturnsFalseForZeroPriority(): void
    {
        $this->setEnvConfig([]);

        $r = new MasterResurrector(
            ControlMessage::RESURRECTION_NONE,
            'ut-zero-priority',
            '127.0.0.1',
            9999
        );

        $this->assertFalse($r->shouldResurrect(false));
    }

    public function testShouldResurrectReturnsFalseWhenServiceExceptionPresent(): void
    {
        $this->setEnvConfig([]);
        $instanceName = 'ut-service-exception';
        MasterProcess::setServiceException($instanceName, 'ut', 1);

        try {
            $r = new MasterResurrector(
                ControlMessage::RESURRECTION_WORKER,
                $instanceName,
                '127.0.0.1',
                9999
            );
            $this->assertFalse($r->shouldResurrect(false));
        } finally {
            MasterProcess::clearServiceException($instanceName);
        }
    }

    public function testShouldResurrectReturnsTrueWhenAllConditionsMet(): void
    {
        $this->setEnvConfig([]);

        $r = new MasterResurrector(
            ControlMessage::RESURRECTION_WORKER,
            'ut-should-resurrect',
            '127.0.0.1',
            9999
        );

        $this->assertTrue($r->shouldResurrect(false));
    }

    public function testConfirmAfterGraceReturnsFalseWhenMasterRecovers(): void
    {
        $this->setEnvConfig([]);

        $r = new class (ControlMessage::RESURRECTION_WORKER, 'ut-grace-recover', '127.0.0.1', 9999) extends MasterResurrector {
            public int $aliveCalls = 0;
            public int $sleepCalls = 0;
            public function isMasterAlive(): bool
            {
                $this->aliveCalls++;
                // 第二次探测返回 alive → grace 窗内放弃
                return $this->aliveCalls >= 2;
            }
            protected function pollSleep(int $usec): void
            {
                $this->sleepCalls++;
            }
        };

        $this->assertFalse($r->confirmAfterGrace(), 'grace 窗内 master 恢复时应放弃复活');
        $this->assertGreaterThanOrEqual(1, $r->aliveCalls);
    }

    public function testConfirmAfterGraceReturnsTrueWhenMasterStaysDown(): void
    {
        $this->setEnvConfig([
            'wls' => ['orchestrator' => ['resurrect_grace_seconds' => 1]],
        ]);

        $r = new class (ControlMessage::RESURRECTION_WORKER, 'ut-grace-down', '127.0.0.1', 9999) extends MasterResurrector {
            public int $aliveCalls = 0;
            public int $sleepCalls = 0;
            public function isMasterAlive(): bool
            {
                $this->aliveCalls++;
                return false;
            }
            protected function pollSleep(int $usec): void
            {
                $this->sleepCalls++;
            }
        };

        $this->assertTrue($r->confirmAfterGrace(), 'grace 窗过期 master 仍不通 → 继续复活');
        $this->assertGreaterThan(0, $r->sleepCalls, 'grace 窗内应至少轮询一次');
    }

    public function testConfirmAfterGraceShortCircuitsWhenGraceIsZero(): void
    {
        $this->setEnvConfig([
            'wls' => ['orchestrator' => ['resurrect_grace_seconds' => 0]],
        ]);

        $r = new class (ControlMessage::RESURRECTION_WORKER, 'ut-grace-zero', '127.0.0.1', 9999) extends MasterResurrector {
            public int $aliveCalls = 0;
            public int $sleepCalls = 0;
            public function isMasterAlive(): bool
            {
                $this->aliveCalls++;
                return false;
            }
            protected function pollSleep(int $usec): void
            {
                $this->sleepCalls++;
            }
        };

        $this->assertTrue($r->confirmAfterGrace());
        $this->assertSame(0, $r->aliveCalls, 'grace=0 应完全跳过轮询');
        $this->assertSame(0, $r->sleepCalls);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setEnvConfig(array $config): void
    {
        $ref = new \ReflectionClass(Env::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $currentEnv = $instance->getValue();
        if (!$currentEnv instanceof Env) {
            // 延迟实例化：调用 getInstance 以构建
            $currentEnv = Env::getInstance();
        }
        $cfg = $ref->getProperty('config');
        $cfg->setAccessible(true);
        $cfg->setValue($currentEnv, $config);
    }
}
