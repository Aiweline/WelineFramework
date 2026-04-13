<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * Lazy request proxy for templates.
 *
 * Keeps legacy array-style access but resolves the current request on demand,
 * avoiding stale snapshots and repeated array copies in persistent workers.
 */
final class TemplateRequestView implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    public function offsetExists(mixed $offset): bool
    {
        if (!\is_string($offset) || $offset === '') {
            return false;
        }

        if (\in_array($offset, ['url', 'query', 'query_string', 'params'], true)) {
            return true;
        }

        return \array_key_exists($offset, $this->params());
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!\is_string($offset) || $offset === '') {
            return null;
        }

        return match ($offset) {
            'url' => $this->request()->getUrlBuilder()->getCurrentUrl(),
            'query' => $this->query(),
            'query_string' => \http_build_query($this->query()),
            'params' => $this->params(),
            default => $this->params()[$offset] ?? $this->request()->getParam($offset),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Template request view is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Template request view is read-only.');
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->toArray());
    }

    public function count(): int
    {
        return \count($this->toArray());
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    private function toArray(): array
    {
        $params = $this->params();

        return \array_merge($params, [
            'url' => $this->request()->getUrlBuilder()->getCurrentUrl(),
            'query' => $this->query(),
            'query_string' => \http_build_query($this->query()),
            'params' => $params,
        ]);
    }

    private function params(): array
    {
        $params = $this->request()->getParams();
        return \is_array($params) ? $params : [];
    }

    private function query(): array
    {
        $query = $this->request()->getQuery();
        return \is_array($query) ? $query : [];
    }

    private function request(): Request
    {
        return ObjectManager::getInstance(Request::class);
    }
}
