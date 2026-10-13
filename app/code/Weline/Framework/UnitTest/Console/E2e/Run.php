<?php

declare(strict_types=1);

namespace Weline\Framework\UnitTest\Console\E2e;

/**
 * Legacy command-discovery alias.
 *
 * Canonical implementation (suite manifests, --list-suites, browser cleanup) lives in
 * {@see \Weline\Framework\Test\Console\E2e\Run}. Both namespaces historically registered
 * `e2e:run`; keeping this thin subclass ensures whichever registration wins still runs
 * the commerce-release gate and other control options.
 */
class Run extends \Weline\Framework\Test\Console\E2e\Run
{
}
