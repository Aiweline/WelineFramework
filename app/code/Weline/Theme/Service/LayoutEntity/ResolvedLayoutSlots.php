<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Runtime\RequestContext;

/** Instance callbacks live only while that component renders; cached templates contain no layout state. */
final class ResolvedLayoutSlots
{
    private static function key(): string
    {
        $fiber = \Fiber::getCurrent();
        return 'theme.resolved_layout_slots.' . ($fiber === null ? 'main' : \spl_object_id($fiber));
    }

    public static function with(array $slots, callable $render): string
    {
        $previous = self::enter($slots);
        try {
            return (string)$render();
        } finally {
            self::restore($previous);
        }
    }

    /** Used around original template calls so their PHP scope and attributes stay intact. */
    public static function enter(array $slots): ?array
    {
        $key = self::key();
        $previous = RequestContext::get($key);
        RequestContext::set($key, $slots);
        return is_array($previous) ? $previous : null;
    }

    public static function restore(?array $previous): void
    {
        if ($previous === null) { RequestContext::remove(self::key()); }
        else { RequestContext::set(self::key(), $previous); }
    }

    public static function has(string $slotId): bool
    {
        $slots = RequestContext::get(self::key(), []);
        return \is_array($slots) && \array_key_exists($slotId, $slots);
    }

    public static function render(string $slotId): string
    {
        $slots = RequestContext::get(self::key(), []);
        $render = \is_array($slots) ? ($slots[$slotId] ?? null) : null;
        return \is_callable($render) ? (string)$render() : '';
    }

    /** Older container files read $children[slot]; callbacks still execute only when accessed. */
    public static function legacyChildren(array $slots): \ArrayAccess
    {
        return new class($slots) implements \ArrayAccess {
            private array $rendered = [];
            public function __construct(private readonly array $slots) {}
            public function offsetExists(mixed $offset): bool { return \array_key_exists((string)$offset, $this->slots); }
            public function offsetGet(mixed $offset): mixed
            {
                $key = (string)$offset;
                if (!isset($this->slots[$key])) { return []; }
                return $this->rendered[$key] ??= [['html' => (string)($this->slots[$key])()]];
            }
            public function offsetSet(mixed $offset, mixed $value): void { throw new \LogicException('Resolved children are read only.'); }
            public function offsetUnset(mixed $offset): void { throw new \LogicException('Resolved children are read only.'); }
        };
    }
}
