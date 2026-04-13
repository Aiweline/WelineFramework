<?php

declare(strict_types=1);

namespace Weline\Framework\View;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use Weline\Framework\App\Env;

/**
 * Lazy env proxy for templates.
 *
 * It preserves array-style access without copying the full env config into
 * every template instance on every request.
 */
final class TemplateEnvView implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    public function offsetExists(mixed $offset): bool
    {
        if (!\is_string($offset) || $offset === '') {
            return false;
        }

        $marker = new \stdClass();
        return Env::getInstance()->getConfig($offset, $marker) !== $marker;
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!\is_string($offset) || $offset === '') {
            return null;
        }

        return Env::getInstance()->getConfig($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Template env view is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Template env view is read-only.');
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator((array)Env::getInstance()->getConfig());
    }

    public function count(): int
    {
        return \count((array)Env::getInstance()->getConfig());
    }

    public function jsonSerialize(): mixed
    {
        return Env::getInstance()->getConfig();
    }
}
