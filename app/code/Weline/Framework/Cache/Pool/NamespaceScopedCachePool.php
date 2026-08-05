<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Pool;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface;
use Weline\Framework\Cache\Contract\RemembererInterface;
use Weline\Framework\Cache\Contract\RememberOptions;
use Weline\Framework\Cache\Contract\TaggableInterface;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
use Weline\Framework\Manager\ObjectManager;

/** Immutable facade that decorates logical keys with one frozen namespace vector. */
class NamespaceScopedCachePool implements NamespaceScopedCachePoolInterface
{
    /** @var list<string> */
    protected readonly array $namespaces;

    /**
     * Select a tag-aware facade only when the underlying pool actually exposes
     * TaggableInterface. Unscoped/non-taggable pools do not gain a fake ability.
     *
     * @param list<string> $namespaces
     */
    public static function create(
        CachePoolInterface $pool,
        array $namespaces,
        ?NamespaceGenerationRepository $repository = null,
        ?NamespaceKeyDecorator $decorator = null,
    ): NamespaceScopedCachePoolInterface {
        if ($pool instanceof self) {
            $namespaces = array_merge($pool->namespaces, $namespaces);
            $repository ??= $pool->repository;
            $decorator ??= $pool->decorator;
            $pool = $pool->pool;
        }
        $decorator ??= new NamespaceKeyDecorator();
        // Empty namespace vector keeps legacy keys compatible and must stay
        // ObjectManager-free so CacheManager can wrap pools during early boot.
        if ($namespaces === []) {
            $repository = null;
        } else {
            $repository ??= ObjectManager::getInstance(NamespaceGenerationRepository::class);
        }

        if ($pool instanceof TaggableInterface) {
            return new NamespaceScopedTaggableCachePool($pool, $namespaces, $repository, $decorator);
        }
        return new self($pool, $namespaces, $repository, $decorator);
    }

    /** @param list<string> $namespaces */
    protected function __construct(
        protected readonly CachePoolInterface $pool,
        array $namespaces,
        protected readonly ?NamespaceGenerationRepository $repository,
        protected readonly NamespaceKeyDecorator $decorator,
    ) {
        $this->namespaces = $this->repository !== null
            ? $this->repository->canonicalizeMany($namespaces)
            : [];
    }

    public function withNamespace(string $namespace): NamespaceScopedCachePoolInterface
    {
        return $this->withNamespaces([$namespace]);
    }

    public function withNamespaces(array $namespaces): NamespaceScopedCachePoolInterface
    {
        return self::create(
            $this->pool,
            array_merge($this->namespaces, $namespaces),
            $this->repository,
            $this->decorator
        );
    }

    /** @return list<string> */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /** @return array{authority_clock:int,generations:array<string,int>} */
    public function getNamespaceVector(): array
    {
        if ($this->namespaces === [] || $this->repository === null) {
            return ['authority_clock' => 0, 'generations' => []];
        }

        return $this->repository->resolveVector($this->namespaces);
    }

    public function getNamespaceFingerprint(): string
    {
        if ($this->namespaces === [] || $this->repository === null) {
            return '';
        }

        return $this->repository->fingerprint($this->namespaces);
    }

    public function getIdentity(): string
    {
        return $this->pool->getIdentity();
    }

    public function getTip(): string
    {
        return $this->pool->getTip();
    }

    public function isPermanent(): bool
    {
        return $this->pool->isPermanent();
    }

    public function get(string $key): mixed
    {
        return $this->pool->get($this->decorateKey($key));
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->pool->set($this->decorateKey($key), $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->pool->delete($this->decorateKey($key));
    }

    public function clear(): bool
    {
        if ($this->namespaces === [] || $this->repository === null) {
            return $this->pool->clear();
        }
        $this->repository->bumpMany($this->namespaces);
        return true;
    }

    public function has(string $key): bool
    {
        return $this->pool->has($this->decorateKey($key));
    }

    public function getMultiple(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $fingerprint = $this->getNamespaceFingerprint();
        $scopedByLogical = [];
        foreach ($keys as $key) {
            $key = self::assertLogicalKey($key);
            $scopedByLogical[$key] = $this->decorator->decorate($key, $fingerprint);
        }
        $scopedValues = $this->pool->getMultiple(array_values($scopedByLogical));
        $values = [];
        foreach ($scopedByLogical as $logical => $scoped) {
            $values[$logical] = $scopedValues[$scoped] ?? null;
        }
        return $values;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        if ($values === []) {
            return true;
        }
        $fingerprint = $this->getNamespaceFingerprint();
        $scoped = [];
        foreach ($values as $key => $value) {
            $key = self::assertLogicalKey($key);
            $scoped[$this->decorator->decorate($key, $fingerprint)] = $value;
        }
        return $this->pool->setMultiple($scoped, $ttl);
    }

    public function deleteMultiple(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }
        $fingerprint = $this->getNamespaceFingerprint();
        $scoped = [];
        foreach ($keys as $key) {
            $scoped[] = $this->decorator->decorate(self::assertLogicalKey($key), $fingerprint);
        }
        return $this->pool->deleteMultiple($scoped);
    }

    public function getStats(): array
    {
        return $this->pool->getStats();
    }

    public function getCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): mixed {
        return $this->pool->getCustom($this->decorateKey($key), $website, $lang, $currency);
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->pool->setCustom(
            $this->decorateKey($key),
            $value,
            $ttl,
            $website,
            $lang,
            $currency
        );
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->pool->deleteCustom($this->decorateKey($key), $website, $lang, $currency);
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->pool->hasCustom($this->decorateKey($key), $website, $lang, $currency);
    }

    public function remember(
        string $key,
        int $ttl,
        callable $callback,
        ?RememberOptions $options = null
    ): mixed {
        return $this->rememberer()->remember($this->decorateKey($key), $ttl, $callback, $options);
    }

    public function rememberCustom(
        string $key,
        int $ttl,
        callable $callback,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
        ?RememberOptions $options = null
    ): mixed {
        return $this->rememberer()->rememberCustom(
            $this->decorateKey($key),
            $ttl,
            $callback,
            $website,
            $lang,
            $currency,
            $options
        );
    }

    protected function decorateKey(string $key): string
    {
        if ($this->namespaces === [] || $this->repository === null) {
            return $key;
        }

        return $this->decorator->decorate($key, $this->getNamespaceFingerprint());
    }

    protected function rememberer(): RemembererInterface
    {
        if (!$this->pool instanceof RemembererInterface) {
            throw new \LogicException(__('底层缓存池不支持 remember 能力'));
        }
        return $this->pool;
    }

    private static function assertLogicalKey(mixed $key): string
    {
        if (!is_string($key)) {
            throw new \InvalidArgumentException(__('缓存键必须是字符串'));
        }
        return $key;
    }
}

/** @internal Created only by NamespaceScopedCachePool::create(). */
final class NamespaceScopedTaggableCachePool extends NamespaceScopedCachePool implements TaggableInterface
{
    public function setWithTags(string $key, mixed $value, array $tags, int $ttl = 0): bool
    {
        $fingerprint = $this->getNamespaceFingerprint();
        $scopedTags = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new \InvalidArgumentException(__('缓存标签必须是字符串'));
            }
            $scopedTags[] = $this->decorator->decorateTag($tag, $fingerprint);
        }
        return $this->taggablePool()->setWithTags(
            $this->decorator->decorate($key, $fingerprint),
            $value,
            $scopedTags,
            $ttl
        );
    }

    public function invalidateTags(array $tags): bool
    {
        if ($tags === []) {
            return true;
        }
        $fingerprint = $this->getNamespaceFingerprint();
        $scopedTags = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new \InvalidArgumentException(__('缓存标签必须是字符串'));
            }
            $scopedTags[] = $this->decorator->decorateTag($tag, $fingerprint);
        }
        return $this->taggablePool()->invalidateTags($scopedTags);
    }

    public function getKeysByTag(string $tag): array
    {
        $fingerprint = $this->getNamespaceFingerprint();
        $keys = $this->taggablePool()->getKeysByTag($this->decorator->decorateTag($tag, $fingerprint));
        $logical = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }
            $unwrapped = $this->decorator->undecorate($key, $fingerprint);
            if ($unwrapped !== null) {
                $logical[] = $unwrapped;
            }
        }
        return $logical;
    }

    /** @return list<string> */
    public function getAllTags(): array
    {
        if (!method_exists($this->pool, 'getAllTags')) {
            return [];
        }
        $fingerprint = $this->getNamespaceFingerprint();
        $tags = [];
        foreach ((array)$this->pool->getAllTags() as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $unwrapped = $this->decorator->undecorateTag($tag, $fingerprint);
            if ($unwrapped !== null) {
                $tags[] = $unwrapped;
            }
        }
        return $tags;
    }

    /** @return array<string,int> */
    public function getTagStats(): array
    {
        if (!method_exists($this->pool, 'getTagStats')) {
            return [];
        }
        $fingerprint = $this->getNamespaceFingerprint();
        $stats = [];
        foreach ((array)$this->pool->getTagStats() as $tag => $count) {
            if (!is_string($tag)) {
                continue;
            }
            $unwrapped = $this->decorator->undecorateTag($tag, $fingerprint);
            if ($unwrapped !== null) {
                $stats[$unwrapped] = (int)$count;
            }
        }
        return $stats;
    }

    private function taggablePool(): TaggableInterface
    {
        if (!$this->pool instanceof TaggableInterface) {
            throw new \LogicException(__('底层缓存池不支持标签能力'));
        }
        return $this->pool;
    }
}
