<?php
declare(strict_types=1);

namespace Weline\Framework\View\Engine;

/**
 * Preferred public API for template runtime (post Taglib compile).
 */
interface ViewEngineInterface
{
    /**
     * Include into the current capture frame (or echo via capture fallback).
     *
     * @param array<string, mixed> $data
     */
    public function include(string $logicalOrCompiled, array $data = []): void;

    /**
     * Capture a template to string (outer begin/end once).
     *
     * @param array<string, mixed> $data
     */
    public function capture(string $logical, array $data = []): string;

    /** Resolve logical path to compiled absolute file (= getFetchFile). */
    public function resolve(string $logical): string;

    /**
     * Thin fetch compat: active capture → stream; otherwise → capture.
     *
     * @param array<string, mixed> $data
     */
    public function fetch(string $logical, array $data = []): string;
}
