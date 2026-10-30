<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Theme\Model\Concern\LoadRequestMemoTrait;

/**
 * Behaviour of the request row memo used by WelineTheme / ThemeScopeVersion:
 * one row read per request, invalidated by generation bump and by forceReload.
 */
final class ThemeModelLoadRequestMemoTraitTest extends TestCase
{
    protected function setUp(): void
    {
        MemoProbeParent::$loadCalls = 0;
        MemoProbeParent::$rows = [];
        Runtime::setMode('wls');
        if (Context::hasCurrent()) {
            Context::leave();
        }
        RequestContext::cleanup();
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestContext::setId('theme-model-memo-test');
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }
        Runtime::resetModeCache();
    }

    public function testRepeatedLoadOfSameRowReadsOncePerRequest(): void
    {
        MemoProbeParent::$rows[7] = ['id' => 7, 'name' => 'hanfu'];
        $first = (new MemoProbeModel(null))->clearData();
        $first->load(7);
        self::assertSame(['id' => 7, 'name' => 'hanfu'], $first->getData());
        self::assertSame(1, MemoProbeParent::$loadCalls);

        $second = (new MemoProbeModel(null))->clearData();
        $second->load(7);

        self::assertSame(['id' => 7, 'name' => 'hanfu'], $second->getData());
        self::assertSame(1, MemoProbeParent::$loadCalls, 'second load must come from the request memo');
    }

    public function testSingleFieldLoadIsMemoisedPerValue(): void
    {
        MemoProbeParent::$loadCalls = 0;
        $model = new MemoProbeModel(['id' => 7, 'path' => 'Weline/hanfu']);
        $model->clearData()->load('path', 'Weline/hanfu');
        $model->clearData()->load('path', 'Weline/hanfu');
        self::assertSame(1, MemoProbeParent::$loadCalls);

        $model->clearData()->load('path', 'Weline/other');
        self::assertSame(2, MemoProbeParent::$loadCalls, 'different value must not share the memo');
    }

    public function testMissingRowIsMemoisedAndReturnsEmptyModel(): void
    {
        MemoProbeParent::$loadCalls = 0;
        $model = new MemoProbeModel(null);
        $model->clearData()->load(404);
        self::assertSame([], $model->getData());

        $model->clearData()->load(404);
        self::assertSame([], $model->getData());
        self::assertSame(1, MemoProbeParent::$loadCalls);
    }

    public function testForceReloadBypassesMemo(): void
    {
        MemoProbeParent::$loadCalls = 0;
        $model = new MemoProbeModel(['id' => 7]);
        $model->clearData()->load(7);
        $model->clearData()->load(7, null, true);
        self::assertSame(2, MemoProbeParent::$loadCalls);
    }

    public function testGenerationBumpInvalidatesEarlierRows(): void
    {
        MemoProbeParent::$loadCalls = 0;
        $model = new MemoProbeModel(['id' => 7, 'name' => 'before']);
        $model->clearData()->load(7);
        self::assertSame(1, MemoProbeParent::$loadCalls);

        // A write in the same request bumps the generation; the next load must
        // hit the DB again so read-after-write sees the new row.
        MemoProbeParent::$rows[7] = ['id' => 7, 'name' => 'after'];
        MemoProbeModel::bumpLoadMemoGeneration();

        $model->clearData()->load(7);
        self::assertSame(2, MemoProbeParent::$loadCalls);
        self::assertSame('after', $model->getData()['name'] ?? null);
    }

    public function testNonScalarLookupValueBypassesMemo(): void
    {
        // R2: array/object lookup values must never be string-cast into a memo key.
        MemoProbeParent::$loadCalls = 0;
        MemoProbeParent::$rows[7] = ['id' => 7];

        $model = new MemoProbeModel(null);
        $model->clearData()->load('field', ['a' => 1]);
        $model->clearData()->load('field', ['a' => 1]);

        self::assertSame(2, MemoProbeParent::$loadCalls, 'non-scalar lookup values must bypass the memo');
    }

    public function testNextRequestDoesNotReusePreviousMemo(): void
    {
        MemoProbeParent::$loadCalls = 0;
        $model = new MemoProbeModel(['id' => 7]);
        $model->clearData()->load(7);
        self::assertSame(1, MemoProbeParent::$loadCalls);

        RequestContext::cleanup();
        Context::enter(new Context([
            'runtime' => ['request_context' => ['initialized' => true]],
        ]));
        RequestContext::setId('theme-model-memo-test-next');

        $model->clearData()->load(7);
        self::assertSame(2, MemoProbeParent::$loadCalls);
    }
}

/**
 * Minimal parent model stand-in: counts authoritative loads and supplies rows.
 * The memo trait overrides `load()` and delegates on a memo miss.
 */
class MemoProbeParent
{
    public static int $loadCalls = 0;

    /** @var array<int, array<string, mixed>> */
    public static array $rows = [];

    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param array<string, mixed>|null $row row returned for any load (null = miss)
     */
    public function __construct(?array $row)
    {
        if ($row !== null) {
            self::$rows[(int)($row['id'] ?? 0)] = $row;
        }
    }

    public function load(int|string $field_or_pk_value, $value = null, bool $forceReload = false): static
    {
        self::$loadCalls++;
        $row = is_null($value) ? (self::$rows[(int)$field_or_pk_value] ?? null) : null;
        if (\is_array($row)) {
            $this->hydrateFromLoadedRow($row);
        } else {
            $this->clearData();
        }

        return $this;
    }

    /** @param array<string, mixed> $data */
    protected function hydrateFromLoadedRow(array $data, ?string $identityMapKey = null): void
    {
        $this->data = $data;
    }

    public function clearData(): static
    {
        $this->data = [];

        return $this;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }
}

final class MemoProbeModel extends MemoProbeParent
{
    use LoadRequestMemoTrait;
}
