<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\Http\Fpc\FpcPierceStamp;

/**
 * Read-only snapshot of managed Nginx conf files for the WLS panel.
 *
 * Never writes. Pierce-map secrets are masked before leaving this service.
 */
final class ManagedNginxConfigViewService
{
    public const MAX_BYTES_PER_FILE = 524288;

    public function __construct(
        private readonly ManagedNginxPaths $paths = new ManagedNginxPaths(),
    ) {
    }

    /**
     * @return array{
     *   conf_dir:string,
     *   files:list<array{
     *     id:string,
     *     label:string,
     *     basename:string,
     *     path:string,
     *     exists:bool,
     *     bytes:int,
     *     mtime:int,
     *     truncated:bool,
     *     redacted:bool,
     *     content:string,
     *     error:string
     *   }>
     * }
     */
    public function snapshot(): array
    {
        $files = [];
        $files[] = $this->readFile(
            'nginx.conf',
            (string)__('主配置 nginx.conf'),
            $this->paths->confFile(),
            false,
        );
        $files[] = $this->readFile(
            'fpc-pierce.map.conf',
            (string)__('FPC 打穿章 map'),
            $this->paths->confDir() . \DIRECTORY_SEPARATOR . FpcPierceNginxMapWriter::MAP_BASENAME,
            true,
        );
        $files[] = $this->readFile(
            'fpc-query-allowlist.map.conf',
            (string)__('FPC query allowlist bypass map（β）'),
            $this->paths->confDir() . \DIRECTORY_SEPARATOR . FpcQueryAllowlistNginxMapWriter::MAP_BASENAME,
            false,
        );

        $confD = $this->paths->confDir() . \DIRECTORY_SEPARATOR . 'conf.d';
        if (\is_dir($confD)) {
            $extras = \glob($confD . \DIRECTORY_SEPARATOR . '*.conf') ?: [];
            \sort($extras);
            foreach (\array_slice($extras, 0, 12) as $extra) {
                $base = \basename((string)$extra);
                $files[] = $this->readFile(
                    'conf.d/' . $base,
                    (string)__('附加 %{1}', [$base]),
                    (string)$extra,
                    false,
                );
            }
        }

        return [
            'conf_dir' => $this->paths->confDir(),
            'files' => $files,
        ];
    }

    /**
     * @return array{
     *   id:string,
     *   label:string,
     *   basename:string,
     *   path:string,
     *   exists:bool,
     *   bytes:int,
     *   mtime:int,
     *   truncated:bool,
     *   redacted:bool,
     *   content:string,
     *   error:string
     * }
     */
    private function readFile(string $id, string $label, string $path, bool $maskPierceSecret): array
    {
        $out = [
            'id' => $id,
            'label' => $label,
            'basename' => \basename($path),
            'path' => $path,
            'exists' => false,
            'bytes' => 0,
            'mtime' => 0,
            'truncated' => false,
            'redacted' => false,
            'content' => '',
            'error' => '',
        ];
        if (!\is_file($path)) {
            $out['error'] = 'missing';

            return $out;
        }
        $out['exists'] = true;
        $out['bytes'] = (int)@\filesize($path);
        $out['mtime'] = (int)@\filemtime($path);
        $raw = @\file_get_contents($path);
        if (!\is_string($raw)) {
            $out['error'] = 'unreadable';

            return $out;
        }
        if (\strlen($raw) > self::MAX_BYTES_PER_FILE) {
            $raw = \substr($raw, 0, self::MAX_BYTES_PER_FILE);
            $out['truncated'] = true;
        }
        if ($maskPierceSecret) {
            $masked = $this->maskPierceSecrets($raw);
            $out['redacted'] = $masked !== $raw;
            $raw = $masked;
        }
        $out['content'] = $raw;

        return $out;
    }

    private function maskPierceSecrets(string $body): string
    {
        $secret = '';
        try {
            $secret = FpcPierceStamp::readSecret();
        } catch (\Throwable) {
            $secret = '';
        }
        if ($secret !== '' && \str_contains($body, $secret)) {
            $body = \str_replace($secret, $this->maskHex($secret), $body);
        }
        // Defense in depth: quoted hex map values that look like pierce secrets.
        return (string)\preg_replace_callback(
            '/"([0-9a-f]{32,128})"/i',
            static function (array $m): string {
                $hex = \strtolower($m[1]);

                return '"' . self::maskHexStatic($hex) . '"';
            },
            $body,
        );
    }

    private function maskHex(string $secret): string
    {
        return self::maskHexStatic($secret);
    }

    private static function maskHexStatic(string $secret): string
    {
        $len = \strlen($secret);
        if ($len <= 12) {
            return \str_repeat('*', $len);
        }

        return \substr($secret, 0, 6) . \str_repeat('*', $len - 10) . \substr($secret, -4);
    }
}
