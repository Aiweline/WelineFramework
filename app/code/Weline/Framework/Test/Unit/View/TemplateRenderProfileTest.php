<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\View\Template;

/** 直接验证实际采集器，不渲染页面或访问存储。 */
final class TemplateRenderProfileTest extends TestCase
{
    private Template $template;
    private \ReflectionMethod $recordMethod;
    private bool $contextEntered = false;

    protected function setUp(): void
    {
        $this->enterRequest('first');
        $this->template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $this->recordMethod = new \ReflectionMethod(Template::class, 'recordTemplateRenderProfile');
    }

    protected function tearDown(): void
    {
        $this->leaveRequest();
    }

    public function testFastRendersAggregateByNormalizedFileAndReachTraceSummary(): void
    {
        $relative = 'app/code/Weline/Theme/view/product-card.phtml';
        $absolute = rtrim((string) BP, '/\\') . '/' . $relative;
        $this->record($absolute, 0.25, 4.0, 0.5, 5.0, 100);
        $this->record(str_replace('/', '\\', $absolute), 0.5, 6.0, 0.25, 7.0, 200);

        $aggregate = RequestContext::get('view.template.aggregate');
        self::assertIsArray($aggregate, 'Fast renders must be visible even below the existing 20 ms profile threshold.');
        self::assertSame(0, $aggregate['overflow_calls']);
        self::assertSame([$relative], array_keys($aggregate['files']));
        self::assertSame(2, $aggregate['files'][$relative]['calls']);
        self::assertEquals([
            'calls' => 2,
            'init_ms' => 0.75,
            'include_ms' => 10.0,
            'capture_ms' => 0.75,
            'total_ms' => 12.0,
            'max_ms' => 7.0,
            'bytes' => 300,
        ], $aggregate['files'][$relative]);
        self::assertEmpty(RequestContext::get('view.template.profile'));
        self::assertSame($aggregate, RequestLifecycleTrace::getAggregateSummary()['template_render_files'] ?? null);
    }

    public function testExistingSlowProfileThresholdFieldsAndLastEightyRowsRemain(): void
    {
        $this->record('slow.phtml', 0.125, 19.5, 0.125, 20.0, 41);
        self::assertSame([
            'file' => 'slow.phtml',
            'init_ms' => 0.13,
            'include_ms' => 19.5,
            'capture_ms' => 0.13,
            'total_ms' => 20.0,
            'bytes' => 41,
        ], RequestContext::get('view.template.profile')[0]);

        // 保留原有的独立 include_ms 阈值判断。
        $this->record('slow.phtml', 0.0, 20.0, 0.0, 19.0, 42);
        for ($index = 0; $index < 79; ++$index) {
            $this->record('slow.phtml', 0.25, 20.0, 0.25, 21.0, 100 + $index);
        }
        $profile = RequestContext::get('view.template.profile');
        self::assertCount(80, $profile);
        self::assertSame(42, $profile[0]['bytes']);
        self::assertSame(178, $profile[79]['bytes']);
        self::assertSame(['file', 'init_ms', 'include_ms', 'capture_ms', 'total_ms', 'bytes'], array_keys($profile[79]));

        $aggregate = RequestContext::get('view.template.aggregate');
        self::assertIsArray($aggregate);
        self::assertSame(81, $aggregate['files']['slow.phtml']['calls']);
        self::assertSame(0, $aggregate['overflow_calls']);
    }

    public function testDisabledTraceDoesNotAllocateAggregateAndKeepsSlowProfileBehavior(): void
    {
        $this->setTracing(false);
        self::assertFalse(RequestLifecycleTrace::isEnabled());
        $this->record('fast.phtml', 0.25, 1.0, 0.25, 2.0, 40);
        $this->record('slow.phtml', 0.25, 21.0, 0.25, 22.0, 80);
        self::assertNull(RequestContext::get('view.template.aggregate'));
        $profile = RequestContext::get('view.template.profile');
        self::assertCount(1, $profile);
        self::assertSame('slow.phtml', $profile[0]['file']);
        self::assertSame(80, $profile[0]['bytes']);
    }

    public function testNextRequestHasIndependentAggregate(): void
    {
        $this->record('shared-file.phtml', 0.25, 1.0, 0.25, 2.0, 40);
        $first = RequestContext::get('view.template.aggregate');
        self::assertIsArray($first);
        self::assertSame(1, $first['files']['shared-file.phtml']['calls']);

        $this->leaveRequest();
        $this->enterRequest('second');
        self::assertNull(RequestContext::get('view.template.aggregate'));
        $this->record('shared-file.phtml', 0.5, 3.0, 0.5, 4.0, 70);
        $second = RequestContext::get('view.template.aggregate');
        self::assertSame(1, $second['files']['shared-file.phtml']['calls']);
        self::assertEquals(4.0, $second['files']['shared-file.phtml']['total_ms']);
        self::assertSame(70, $second['files']['shared-file.phtml']['bytes']);
        self::assertSame(40, $first['files']['shared-file.phtml']['bytes']);
    }

    public function testDistinctFileCapKeepsAccumulatingExistingFilesAndCountsOverflow(): void
    {
        for ($index = 0; $index < 128; ++$index) {
            $this->record('bounded-' . $index . '.phtml', 0.25, 1.0, 0.25, 2.0, 40);
        }
        $this->record('bounded-0.phtml', 0.5, 3.0, 0.5, 4.0, 70);
        $this->record('overflow-a.phtml', 0.25, 1.0, 0.25, 2.0, 90);
        $this->record('overflow-b.phtml', 0.25, 1.0, 0.25, 2.0, 100);

        $aggregate = RequestContext::get('view.template.aggregate');
        self::assertIsArray($aggregate);
        self::assertCount(128, $aggregate['files']);
        self::assertSame(2, $aggregate['overflow_calls']);
        self::assertSame(2, $aggregate['files']['bounded-0.phtml']['calls']);
        self::assertEquals(6.0, $aggregate['files']['bounded-0.phtml']['total_ms']);
        self::assertSame(110, $aggregate['files']['bounded-0.phtml']['bytes']);
        self::assertArrayHasKey('bounded-127.phtml', $aggregate['files']);
        self::assertArrayNotHasKey('overflow-a.phtml', $aggregate['files']);
        self::assertArrayNotHasKey('overflow-b.phtml', $aggregate['files']);
    }

    private function record(string $file, float $init, float $include, float $capture, float $total, int $bytes): void
    {
        $this->recordMethod->invoke($this->template, $file, $init, $include, $capture, $total, $bytes);
    }

    private function enterRequest(string $suffix): void
    {
        Context::enter(new Context());
        $this->contextEntered = true;
        RequestContext::setId('template-render-profile-' . getmypid() . '-' . $this->name() . '-' . $suffix);
        RequestLifecycleTrace::reset();
        $this->setTracing(true);
    }

    private function leaveRequest(): void
    {
        if ($this->contextEntered) {
            RequestContext::cleanup();
            Context::leave();
            $this->contextEntered = false;
        }
    }

    private function setTracing(bool $enabled): void
    {
        $state = (new \ReflectionMethod(RequestLifecycleTrace::class, 'state'))->invoke(null);
        $state->enabledCache = $enabled;
    }
}
