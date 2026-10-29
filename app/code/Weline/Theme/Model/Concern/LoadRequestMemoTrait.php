<?php
declare(strict_types=1);

namespace Weline\Theme\Model\Concern;

use Weline\Framework\Runtime\RequestContext;

/**
 * Request-scoped row memo for hot Theme models.
 *
 * Storefront requests load the same theme row / scope-version row dozens of
 * times per request (design override, published-version resolver, slot renderer,
 * widget renderer, …). The Framework ORM identity map alone did not dedupe that
 * traffic, so the model itself keeps a request-shared row snapshot:
 *
 * - Scope: {@see RequestContext::shared()} — one entry per logical request,
 *   dropped by `RequestContext::cleanup()`. Never crosses requests, so a theme
 *   edit can never be served from a stale worker-level memo.
 * - Invalidation: the memo key embeds a per-class generation counter bumped by
 *   {@see bumpLoadMemoGeneration()} from `save_after()` / `delete_after()`.
 *   A write inside the same request therefore invalidates every memo captured
 *   before it (read-after-write keeps seeing the new row).
 * - Only primary-key / single-field loads are memoised; `$forceReload = true`
 *   always bypasses (callers that require the authoritative row keep working).
 */
trait LoadRequestMemoTrait
{
    /** Request memo sentinel: the load concluded "row not found". */
    private const LOAD_MEMO_MISS = '__theme_model_load_row_miss__';

    /** @var array<string, int> per-using-class generation counter */
    private static array $loadMemoGenerations = [];

    /** Bump the in-request generation so earlier row memos are not reused. */
    public static function bumpLoadMemoGeneration(): void
    {
        $class = static::class;
        self::$loadMemoGenerations[$class] = (self::$loadMemoGenerations[$class] ?? 0) + 1;
    }

    public function load(int|string $field_or_pk_value, $value = null, bool $forceReload = false): static
    {
        if ($forceReload) {
            return parent::load($field_or_pk_value, $value, true);
        }
        // Scalar guard: array/object lookup values cannot be keys safely
        // ((string) cast warns / collides) — those paths always hit the DB.
        if (!(null === $value || \is_scalar($value))) {
            return parent::load($field_or_pk_value, $value, false);
        }

        $generation = self::$loadMemoGenerations[static::class] ?? 0;
        $memoKey = static::class . '::load:' . (null === $value
            ? 'pk:' . $field_or_pk_value
            : 'field:' . $field_or_pk_value . ':' . \md5((string)$value)) . ':g' . $generation;

        if (RequestContext::sharedHas($memoKey)) {
            $cached = RequestContext::sharedGet($memoKey);
            if ($cached === self::LOAD_MEMO_MISS) {
                $this->clearData();

                return $this;
            }
            if (\is_array($cached) && $cached !== []) {
                $this->hydrateFromLoadedRow($cached);

                return $this;
            }
        }

        $result = parent::load($field_or_pk_value, $value, false);

        $data = $this->getData();
        RequestContext::sharedSet($memoKey, \is_array($data) && $data !== [] ? $data : self::LOAD_MEMO_MISS);

        return $result;
    }
}
