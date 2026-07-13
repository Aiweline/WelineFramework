<?php

declare(strict_types=1);

namespace Weline\Server\Protocol;

/** Minimal TCP4/TCP6 PROXY protocol v2 codec used on Dispatcher backends. */
final class ProxyProtocolV2
{
    public const SIGNATURE = "\r\n\r\n\0\r\nQUIT\n";
    private const VERSION_COMMAND_PROXY = "\x21";
    private const AUTH_TLV_TYPE = 0xEA;
    private const AUTH_NONCE_BYTES = 8;
    private const AUTH_MAC_BYTES = 32;
    private const AUTH_CONTEXT = "WLS-PROXY-V2-AUTH\0";
    private const MAX_HEADER_BYTES = 232;

    public static function encode(
        string $sourceIp,
        int $sourcePort = 0,
        string $destinationIp = '127.0.0.1',
        int $destinationPort = 0,
        string $authenticationSecret = ''
    ): string {
        $source = @\inet_pton($sourceIp);
        $destination = @\inet_pton($destinationIp);
        if (\is_string($source) && \strlen($source) === 4) {
            if (!\is_string($destination) || \strlen($destination) !== 4) {
                $destination = \inet_pton('127.0.0.1');
            }
            $familyProtocol = "\x11";
            $addressPayload = $source . $destination . \pack('nn', $sourcePort & 0xffff, $destinationPort & 0xffff);
            return self::encodeHeader($familyProtocol, $addressPayload, $authenticationSecret);
        }
        if (\is_string($source) && \strlen($source) === 16) {
            if (!\is_string($destination) || \strlen($destination) !== 16) {
                $destination = \inet_pton('::1');
            }
            $familyProtocol = "\x21";
            $addressPayload = $source . $destination . \pack('nn', $sourcePort & 0xffff, $destinationPort & 0xffff);
            return self::encodeHeader($familyProtocol, $addressPayload, $authenticationSecret);
        }

        return self::encodeHeader("\x00", '', $authenticationSecret);
    }

    /**
     * Consume one complete preface from a non-blocking stream when present.
     *
     * @return array{present:bool,complete:bool,source_ip:string,source_port:int,bytes:int,authenticated:bool}
     */
    public static function consumeFromStream(
        mixed $stream,
        string $authenticationSecret = '',
        bool $requireAuthentication = false
    ): array
    {
        $none = self::emptyResult(false, true);
        if (!\is_resource($stream) || !\function_exists('socket_import_stream')) {
            return $none;
        }
        $socket = @\socket_import_stream($stream);
        if (!$socket instanceof \Socket) {
            return $none;
        }
        $peek = '';
        $received = @\socket_recv($socket, $peek, 16, MSG_PEEK);
        if ($received === false || $received === 0) {
            return self::emptyResult(false, false);
        }
        if ($received < 12) {
            return \str_starts_with(self::SIGNATURE, $peek)
                ? self::emptyResult(true, false)
                : $none;
        }
        if (!\str_starts_with($peek, self::SIGNATURE)) {
            return $none;
        }
        if ($received < 16) {
            return self::emptyResult(true, false);
        }
        $length = \unpack('nlength', \substr($peek, 14, 2));
        $payloadLength = (int)($length['length'] ?? 0);
        $total = 16 + $payloadLength;
        if ($total > self::MAX_HEADER_BYTES) {
            throw new \UnexpectedValueException('PROXY v2 header exceeds the WLS limit.');
        }
        $all = '';
        $received = @\socket_recv($socket, $all, $total, MSG_PEEK);
        if ($received === false || $received < $total) {
            return self::emptyResult(true, false);
        }
        $decoded = self::decodeHeader($all, $authenticationSecret, $requireAuthentication);
        $consumed = '';
        $read = @\socket_recv($socket, $consumed, $total, 0);
        if ($read !== $total) {
            throw new \RuntimeException('Unable to consume complete PROXY v2 header.');
        }

        return $decoded;
    }

    /**
     * Remove a complete PROXY v2 preface from an application read buffer.
     *
     * @return array{present:bool,complete:bool,source_ip:string,source_port:int,bytes:int,authenticated:bool}
     */
    public static function consumeFromBuffer(
        string &$buffer,
        string $authenticationSecret = '',
        bool $requireAuthentication = false
    ): array
    {
        $none = self::emptyResult(false, true);
        if ($buffer === '') {
            return self::emptyResult(false, false);
        }
        if (\strlen($buffer) < 12) {
            return \str_starts_with(self::SIGNATURE, $buffer)
                ? self::emptyResult(true, false)
                : $none;
        }
        if (!\str_starts_with($buffer, self::SIGNATURE)) {
            return $none;
        }
        if (\strlen($buffer) < 16) {
            return self::emptyResult(true, false);
        }
        $length = \unpack('nlength', \substr($buffer, 14, 2));
        $payloadLength = (int)($length['length'] ?? 0);
        $total = 16 + $payloadLength;
        if ($total > self::MAX_HEADER_BYTES) {
            throw new \UnexpectedValueException('PROXY v2 header exceeds the WLS limit.');
        }
        if (\strlen($buffer) < $total) {
            return self::emptyResult(true, false);
        }
        $header = \substr($buffer, 0, $total);
        $decoded = self::decodeHeader($header, $authenticationSecret, $requireAuthentication);
        $buffer = (string)\substr($buffer, $total);

        return $decoded;
    }

    public static function isLoopbackPeer(string $peer): bool
    {
        $peer = \trim($peer);
        if ($peer === '') {
            return false;
        }

        $ip = $peer;
        if ($peer[0] === '[') {
            $end = \strpos($peer, ']');
            if ($end === false) {
                return false;
            }
            $ip = \substr($peer, 1, $end - 1);
        } elseif (\filter_var($peer, FILTER_VALIDATE_IP) === false) {
            $separator = \strrpos($peer, ':');
            if ($separator === false) {
                return false;
            }
            $ip = \substr($peer, 0, $separator);
        }

        $zone = \strpos($ip, '%');
        if ($zone !== false) {
            $ip = \substr($ip, 0, $zone);
        }
        $packed = @\inet_pton($ip);
        if (!\is_string($packed)) {
            return false;
        }
        if (\strlen($packed) === 4) {
            return \ord($packed[0]) === 127;
        }
        if (\strlen($packed) !== 16) {
            return false;
        }
        if ($packed === \str_repeat("\0", 15) . "\x01") {
            return true;
        }

        return \substr($packed, 0, 12) === \str_repeat("\0", 10) . "\xff\xff"
            && \ord($packed[12]) === 127;
    }

    private static function encodeHeader(string $familyProtocol, string $addressPayload, string $authenticationSecret): string
    {
        $payload = $addressPayload;
        if ($authenticationSecret !== '') {
            $nonce = \random_bytes(self::AUTH_NONCE_BYTES);
            $mac = \hash_hmac(
                'sha256',
                self::AUTH_CONTEXT . self::VERSION_COMMAND_PROXY . $familyProtocol . $addressPayload . $nonce,
                $authenticationSecret,
                true
            );
            $authValue = $nonce . $mac;
            $payload .= \chr(self::AUTH_TLV_TYPE) . \pack('n', \strlen($authValue)) . $authValue;
        }

        return self::SIGNATURE
            . self::VERSION_COMMAND_PROXY
            . $familyProtocol
            . \pack('n', \strlen($payload))
            . $payload;
    }

    /**
     * @return array{present:bool,complete:bool,source_ip:string,source_port:int,bytes:int,authenticated:bool}
     */
    private static function decodeHeader(string $header, string $authenticationSecret, bool $requireAuthentication): array
    {
        if (\strlen($header) < 16 || !\str_starts_with($header, self::SIGNATURE)) {
            throw new \UnexpectedValueException('Invalid PROXY v2 signature.');
        }

        $versionCommand = \ord($header[12]);
        if (($versionCommand & 0xF0) !== 0x20 || ($versionCommand & 0x0F) !== 0x01) {
            throw new \UnexpectedValueException('Unsupported PROXY v2 version or command.');
        }
        $familyProtocol = \ord($header[13]);
        $family = $familyProtocol & 0xF0;
        $protocol = $familyProtocol & 0x0F;
        if ($protocol !== 0x00 && $protocol !== 0x01) {
            throw new \UnexpectedValueException('PROXY v2 backend preface must describe a TCP stream.');
        }

        $payloadLength = (int)(\unpack('nlength', \substr($header, 14, 2))['length'] ?? 0);
        $total = 16 + $payloadLength;
        if ($total > self::MAX_HEADER_BYTES || \strlen($header) !== $total) {
            throw new \UnexpectedValueException('Invalid PROXY v2 header length.');
        }

        $addressLength = match ($family) {
            0x10 => 12,
            0x20 => 36,
            0x00 => 0,
            default => throw new \UnexpectedValueException('Unsupported PROXY v2 address family.'),
        };
        if ($payloadLength < $addressLength) {
            throw new \UnexpectedValueException('Truncated PROXY v2 address payload.');
        }

        $payload = \substr($header, 16, $payloadLength);
        $addressPayload = \substr($payload, 0, $addressLength);
        $authenticated = false;
        $offset = $addressLength;
        while ($offset < $payloadLength) {
            if (($payloadLength - $offset) < 3) {
                throw new \UnexpectedValueException('Truncated PROXY v2 TLV header.');
            }
            $type = \ord($payload[$offset]);
            $tlvLength = (int)(\unpack('nlength', \substr($payload, $offset + 1, 2))['length'] ?? 0);
            $offset += 3;
            if (($payloadLength - $offset) < $tlvLength) {
                throw new \UnexpectedValueException('Truncated PROXY v2 TLV value.');
            }
            $value = \substr($payload, $offset, $tlvLength);
            $offset += $tlvLength;
            if ($type !== self::AUTH_TLV_TYPE) {
                continue;
            }
            if ($authenticationSecret === ''
                || $tlvLength !== (self::AUTH_NONCE_BYTES + self::AUTH_MAC_BYTES)
            ) {
                continue;
            }
            $nonce = \substr($value, 0, self::AUTH_NONCE_BYTES);
            $receivedMac = \substr($value, self::AUTH_NONCE_BYTES);
            $expectedMac = \hash_hmac(
                'sha256',
                self::AUTH_CONTEXT . $header[12] . $header[13] . $addressPayload . $nonce,
                $authenticationSecret,
                true
            );
            $authenticated = \hash_equals($expectedMac, $receivedMac);
        }

        if ($requireAuthentication && !$authenticated) {
            throw new \UnexpectedValueException('PROXY v2 instance authentication failed.');
        }

        $sourceIp = '';
        $sourcePort = 0;
        if ($family === 0x10) {
            $sourceIp = (string)\inet_ntop(\substr($addressPayload, 0, 4));
            $ports = \unpack('nsource/ndestination', \substr($addressPayload, 8, 4));
            $sourcePort = (int)($ports['source'] ?? 0);
        } elseif ($family === 0x20) {
            $sourceIp = (string)\inet_ntop(\substr($addressPayload, 0, 16));
            $ports = \unpack('nsource/ndestination', \substr($addressPayload, 32, 4));
            $sourcePort = (int)($ports['source'] ?? 0);
        }

        return [
            'present' => true,
            'complete' => true,
            'source_ip' => $sourceIp,
            'source_port' => $sourcePort,
            'bytes' => $total,
            'authenticated' => $authenticated,
        ];
    }

    /**
     * @return array{present:bool,complete:bool,source_ip:string,source_port:int,bytes:int,authenticated:bool}
     */
    private static function emptyResult(bool $present, bool $complete): array
    {
        return [
            'present' => $present,
            'complete' => $complete,
            'source_ip' => '',
            'source_port' => 0,
            'bytes' => 0,
            'authenticated' => false,
        ];
    }
}
