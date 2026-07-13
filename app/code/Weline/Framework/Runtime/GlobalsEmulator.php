<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;

/**
 * Transitional compatibility bridge for legacy code that still reads PHP
 * superglobals inside WLS workers.
 *
 * The long-term goal is to remove this class entirely once the request path no
 * longer depends on direct superglobal access.
 */
class GlobalsEmulator
{
    private bool $emulated = false;

    public function emulate(Request $request): void
    {
        if ($this->emulated) {
            $this->reset();
        }

        $this->populateFromRequest($request);
        $this->emulated = true;
    }

    private function populateFromRequest(Request $request): void
    {
        $_POST = $request->getPostParams() ?? [];

        if (\method_exists($request, 'getQueryParams')) {
            $_GET = $request->getQueryParams() ?? [];
        } else {
            $_GET = [];
        }

        if (\method_exists($request, 'resetParameterBag')) {
            $request->resetParameterBag();
        }

        $_COOKIE = [];
        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader) {
            foreach (\explode(';', (string)$cookieHeader) as $cookie) {
                $parts = \explode('=', \trim($cookie), 2);
                if (\count($parts) === 2) {
                    $_COOKIE[\trim($parts[0])] = \urldecode(\trim($parts[1]));
                }
            }
        }

        $_FILES = $request->getFiles() ?? [];
        $_SERVER = $this->buildServerArray($request);
        $_REQUEST = \array_merge($_GET, $_POST);
    }

    private function buildServerArray(Request $request): array
    {
        $parsedServer = [];
        if (\method_exists($request, 'getParsedServerSnapshot')) {
            $candidate = $request->getParsedServerSnapshot();
            $parsedServer = \is_array($candidate) ? $candidate : [];
        }

        $keepKeys = [
            'PHP_SELF',
            'SCRIPT_NAME',
            'SCRIPT_FILENAME',
            'PATH_TRANSLATED',
            'DOCUMENT_ROOT',
            'GATEWAY_INTERFACE',
            'SERVER_SOFTWARE',
            'SERVER_PROTOCOL',
            'SERVER_ADMIN',
            'WLS_INSTANCE',
            'WLS_INSTANCE_NAME',
            'WLS_WORKER_ID',
            'WLS_PORT',
            'WLS_REQUEST_COUNT',
            'WLS_PROCESS_TAG',
            'argc',
            'argv',
        ];
        $requestScopedKeys = [
            'WLS_INTERNAL_WARMUP',
            'WLS_INTERNAL_DYNAMIC_WARMUP',
            'WLS_INTERNAL_HOMEPAGE_PRIME',
            'WLS_INTERNAL_BACKEND_WARMUP',
            'WLS_INTERNAL_BACKEND_WARMUP_USER_ID',
            'WLS_FPC_BYPASS',
        ];

        $server = [];
        foreach ($keepKeys as $key) {
            if (\array_key_exists($key, $parsedServer) && $parsedServer[$key] !== '') {
                $server[$key] = $parsedServer[$key];
                continue;
            }
            $requestValue = \method_exists($request, 'getServer') ? $request->getServer($key) : null;
            if ($requestValue !== null && $requestValue !== '') {
                $server[$key] = $requestValue;
            } elseif (isset($_SERVER[$key])) {
                $server[$key] = $_SERVER[$key];
            }
        }
        foreach ($requestScopedKeys as $key) {
            if (\array_key_exists($key, $parsedServer) && $parsedServer[$key] !== '') {
                $server[$key] = $parsedServer[$key];
                continue;
            }
            $requestValue = \method_exists($request, 'getServer') ? $request->getServer($key) : null;
            if ($requestValue !== null && $requestValue !== '') {
                $server[$key] = $requestValue;
            } elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                // WlsRequest::fromRaw() has already replaced $_SERVER with the
                // current request snapshot. Its server-only warmup markers are
                // not HTTP headers and may not yet be visible through a
                // ServerBag still bound to the previous Context.
                $server[$key] = $_SERVER[$key];
            }
        }

        $server['REQUEST_METHOD'] = $request->getMethod() ?? 'GET';
        $server['REQUEST_URI'] = $request->getUri() ?? '/';
        $server['QUERY_STRING'] = $request->getQueryString() ?? '';
        $server['HTTP_HOST'] = $request->getHeader('Host') ?? 'localhost';
        $server['HTTP_USER_AGENT'] = $request->getHeader('User-Agent') ?? '';
        $server['HTTP_ACCEPT'] = $request->getHeader('Accept') ?? '*/*';
        $server['HTTP_ACCEPT_LANGUAGE'] = $request->getHeader('Accept-Language') ?? '';
        $server['HTTP_ACCEPT_ENCODING'] = $request->getHeader('Accept-Encoding') ?? '';
        $server['HTTP_CONNECTION'] = $request->getHeader('Connection') ?? 'keep-alive';
        $server['CONTENT_TYPE'] = $request->getHeader('Content-Type') ?? '';
        $server['CONTENT_LENGTH'] = $request->getHeader('Content-Length') ?? '';

        $uriParts = \parse_url($server['REQUEST_URI']);
        $server['PATH_INFO'] = $uriParts['path'] ?? '/';

        $server['HTTPS'] = $request->isSecure() ? 'on' : '';
        $server['REQUEST_SCHEME'] = $request->isSecure() ? 'https' : 'http';

        $hostParts = \explode(':', (string)$server['HTTP_HOST']);
        $server['SERVER_NAME'] = $hostParts[0];
        $server['SERVER_PORT'] = $hostParts[1] ?? ($request->isSecure() ? '443' : '80');

        $server['REQUEST_TIME'] = \time();
        $server['REQUEST_TIME_FLOAT'] = \microtime(true);
        $server['WELINE_ORIGIN_REQUEST_URI'] = $server['REQUEST_URI'];
        $server['WELINE_FULL_REQUEST_URI'] = $server['REQUEST_SCHEME'] . '://' . $server['HTTP_HOST'] . $server['REQUEST_URI'];
        $server['WELINE_AREA'] = 'frontend';
        $server['WELINE_AREA_ROUTE'] = '';
        $server['WELINE_WEBSITE_URL'] = '';
        $server['WELINE_URL_PARSED'] = false;
        $this->applyCookieRouteVariant($server);

        foreach ($request->getHeaders() as $name => $value) {
            $serverKey = 'HTTP_' . \strtoupper(\str_replace('-', '_', (string)$name));
            if (!isset($server[$serverKey])) {
                $server[$serverKey] = $value;
            }
        }

        WelineEnv::getInstance()->initFromSnapshot(
            \is_array($_GET ?? null) ? $_GET : [],
            \is_array($_POST ?? null) ? $_POST : [],
            \is_array($_COOKIE ?? null) ? $_COOKIE : [],
            \is_array($_FILES ?? null) ? $_FILES : [],
            $server
        );

        return $server;
    }

    private function applyCookieRouteVariant(array &$server): void
    {
        $lang = (string)($_COOKIE['WELINE_USER_LANG'] ?? $_COOKIE['WELINE-WEBSITE-LANG'] ?? '');
        if ($lang !== '') {
            $server['WELINE_USER_LANG'] = \str_replace('-', '_', \trim($lang));
        }

        $currency = \strtoupper(\trim((string)($_COOKIE['WELINE_USER_CURRENCY'] ?? $_COOKIE['WELINE_WEBSITE_CURRENCY'] ?? '')));
        if ($currency !== '' && State::isAllowedCurrencyCode($currency)) {
            $server['WELINE_USER_CURRENCY'] = $currency;
        }
    }

    public function reset(): void
    {
        if (!$this->emulated) {
            return;
        }

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];

        $keepKeys = [
            'PHP_SELF',
            'SCRIPT_NAME',
            'SCRIPT_FILENAME',
            'PATH_TRANSLATED',
            'DOCUMENT_ROOT',
            'GATEWAY_INTERFACE',
            'SERVER_SOFTWARE',
            'SERVER_PROTOCOL',
            'SERVER_ADMIN',
            'WLS_INSTANCE',
            'WLS_INSTANCE_NAME',
            'WLS_WORKER_ID',
            'WLS_PORT',
            'WLS_REQUEST_COUNT',
            'WLS_PROCESS_TAG',
            'argc',
            'argv',
        ];

        $newServer = [];
        foreach ($keepKeys as $key) {
            if (isset($_SERVER[$key])) {
                $newServer[$key] = $_SERVER[$key];
            }
        }
        $_SERVER = $newServer;

        $this->emulated = false;

        try {
            WelineEnv::getInstance()->reset();
        } catch (\Throwable) {
        }
    }

    public function isEmulated(): bool
    {
        return $this->emulated;
    }
}
