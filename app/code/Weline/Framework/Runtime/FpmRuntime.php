<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Sse\SseContext;

/**
 * FPM runtime now enters through the single Context and delegates execution to
 * the instance App bridge.
 */
class FpmRuntime implements RuntimeInterface
{
    private bool $bootstrapped = false;

    public function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        App::init();
        $this->bootstrapped = true;
    }

    public function handle(?Request $request = null): string
    {
        if (!$this->bootstrapped) {
            $this->bootstrap();
        }

        $isCliSapi = \in_array(\PHP_SAPI, ['cli', 'phpdbg'], true);
        $context = Context::fromGlobals([
            'mode' => self::MODE_FPM,
            'type' => $isCliSapi ? 'system' : 'request',
            'process_tag' => $isCliSapi ? 'CLI' : 'FPM',
        ]);
        Context::enter($context);
        $this->syncCurrentContextFromGlobals();

        try {
            $response = (new App(Context::current(), false))->runResponse();

            if (SseContext::isSseEnabled()) {
                return '';
            }

            return $response->getBody();
        } finally {
            Context::leave();
        }
    }

    public function reset(): void
    {
        Context::leave();
        RequestContext::cleanup();
    }

    public function terminate(): void
    {
        Context::leave();
        RequestContext::cleanup();
        $this->bootstrapped = false;
    }

    public function getMode(): string
    {
        return self::MODE_FPM;
    }

    public function isPersistent(): bool
    {
        return false;
    }

    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    private function syncCurrentContextFromGlobals(): void
    {
        WelineEnv::getInstance()->initFromSnapshot(
            \is_array($_GET ?? null) ? $_GET : [],
            \is_array($_POST ?? null) ? $_POST : [],
            \is_array($_COOKIE ?? null) ? $_COOKIE : [],
            \is_array($_FILES ?? null) ? $_FILES : [],
            \is_array($_SERVER ?? null) ? $_SERVER : [],
        );
    }
}
