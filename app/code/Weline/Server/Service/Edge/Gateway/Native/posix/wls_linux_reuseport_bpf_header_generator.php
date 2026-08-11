<?php

declare(strict_types=1);

const WLS_BPF_MAX_OBJECT_BYTES = 16 * 1024 * 1024;
const WLS_BPF_MAX_SOURCE_BYTES = 1024 * 1024;
const WLS_BPF_CLANG_MAJOR = 18;
const WLS_BPF_CLANG_TIMEOUT_NANOSECONDS = 5_000_000_000;
const WLS_BPF_CLANG_TERM_GRACE_NANOSECONDS = 500_000_000;
const WLS_BPF_CLANG_KILL_REAP_NANOSECONDS = 250_000_000;
const WLS_BPF_CLANG_POLL_NANOSECONDS = 20_000_000;
const WLS_BPF_CLANG_MAX_OUTPUT_BYTES = 16 * 1024;
const WLS_BPF_CLANG_READ_BYTES = 4096;
const WLS_BPF_CLANG_DRAIN_BYTES_PER_PIPE_CYCLE = 20 * 1024;
const WLS_BPF_PROGRAM_SECTION = 'sk_reuseport/wls_h3_route';
const WLS_BPF_RELOCATION_SECTION = '.relsk_reuseport/wls_h3_route';
const WLS_BPF_INSTRUCTION_BYTES = 8;
const WLS_BPF_R_BPF_64_64 = 1;

/** @return never */
function wls_bpf_fail(string $message): void
{
    \fwrite(STDERR, 'wls-bpf-header-generator: ' . $message . PHP_EOL);
    exit(1);
}

function wls_bpf_u16(string $contents, int $offset): int
{
    $value = \unpack('vvalue', \substr($contents, $offset, 2));
    return (int)($value['value'] ?? -1);
}

function wls_bpf_u32(string $contents, int $offset): int
{
    $value = \unpack('Vvalue', \substr($contents, $offset, 4));
    return (int)($value['value'] ?? -1);
}

function wls_bpf_u64(string $contents, int $offset): int
{
    $value = \unpack('Pvalue', \substr($contents, $offset, 8));
    $number = $value['value'] ?? -1;
    if (!\is_int($number) || $number < 0) {
        wls_bpf_fail('ELF contains an unsupported 64-bit value.');
    }
    return $number;
}

function wls_bpf_range_is_valid(int $size, int $offset, int $length): bool
{
    return $offset >= 0 && $length >= 0 && $offset <= $size
        && $length <= $size - $offset;
}

/** @return array<string, string> */
function wls_bpf_arguments(array $arguments): array
{
    if (\count($arguments) !== 5) {
        wls_bpf_fail(
            'usage: generator --object=<ELF> --source=<C> '
            . '--clang=<clang-18> --output=<header>',
        );
    }
    $options = [];
    foreach (\array_slice($arguments, 1) as $argument) {
        if (\preg_match('/\A--(object|source|clang|output)=(.+)\z/D', $argument, $matches) !== 1
            || isset($options[$matches[1]])
            || \str_contains($matches[2], "\0")
        ) {
            wls_bpf_fail('invalid or duplicate argument.');
        }
        $options[$matches[1]] = $matches[2];
    }
    foreach (['object', 'source', 'clang', 'output'] as $required) {
        if (!isset($options[$required]) || $options[$required] === '') {
            wls_bpf_fail('missing --' . $required . ' argument.');
        }
    }
    return $options;
}

function wls_bpf_read_regular_file(
    string $path,
    string $basename,
    int $maximumBytes,
): string {
    if (\basename($path) !== $basename || \is_link($path) || !\is_file($path)) {
        wls_bpf_fail($basename . ' must be a regular, non-linked file.');
    }
    $size = \filesize($path);
    if (!\is_int($size) || $size <= 0 || $size > $maximumBytes) {
        wls_bpf_fail($basename . ' exceeds its bounded size contract.');
    }
    $contents = \file_get_contents($path);
    if (!\is_string($contents) || \strlen($contents) !== $size) {
        wls_bpf_fail('unable to read ' . $basename . ' completely.');
    }
    return $contents;
}

/**
 * @param array<int, resource> $pipes
 */
function wls_bpf_drain_clang_pipes(
    array &$pipes,
    string &$output,
    bool &$outputExceeded,
): bool {
    foreach ($pipes as $descriptor => $pipe) {
        $drained = 0;
        while ($drained < WLS_BPF_CLANG_DRAIN_BYTES_PER_PIPE_CYCLE) {
            $chunk = @\fread(
                $pipe,
                \min(
                    WLS_BPF_CLANG_READ_BYTES,
                    WLS_BPF_CLANG_DRAIN_BYTES_PER_PIPE_CYCLE - $drained,
                ),
            );
            if ($chunk === false) {
                @\fclose($pipe);
                unset($pipes[$descriptor]);
                return false;
            }
            if ($chunk === '') {
                break;
            }
            $length = \strlen($chunk);
            $drained += $length;
            $remaining = WLS_BPF_CLANG_MAX_OUTPUT_BYTES - \strlen($output);
            if ($remaining > 0) {
                $output .= \substr($chunk, 0, $remaining);
            }
            if ($length > $remaining) {
                $outputExceeded = true;
            }
        }
        if (\feof($pipe)) {
            @\fclose($pipe);
            unset($pipes[$descriptor]);
        }
    }
    return true;
}

/**
 * Wait only for pipe readiness, and only for an interval already clipped to
 * the caller's single absolute monotonic deadline.
 *
 * @param array<int, resource> $pipes
 */
function wls_bpf_wait_for_clang_activity(array $pipes, int $nanoseconds): void
{
    if ($nanoseconds <= 0) {
        return;
    }
    $read = \array_values($pipes);
    if ($read === []) {
        @\time_nanosleep(0, $nanoseconds);
        return;
    }
    $write = null;
    $except = null;
    $seconds = \intdiv($nanoseconds, 1_000_000_000);
    $microseconds = \intdiv($nanoseconds % 1_000_000_000, 1000);
    if ($seconds === 0 && $microseconds === 0) {
        return;
    }
    @\stream_select($read, $write, $except, $seconds, $microseconds);
}

function wls_bpf_verify_clang(string $path): void
{
    if (\basename($path) !== 'clang-18' || !\is_executable($path)) {
        wls_bpf_fail('the generator requires an explicit clang-18 executable.');
    }
    $started = \hrtime(true);
    if ($started > PHP_INT_MAX - WLS_BPF_CLANG_TIMEOUT_NANOSECONDS) {
        wls_bpf_fail('unable to establish the clang-18 monotonic deadline.');
    }
    $absoluteDeadline = $started + WLS_BPF_CLANG_TIMEOUT_NANOSECONDS;
    $pipes = [];
    $process = \proc_open(
        [$path, '--version'],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!\is_resource($process)) {
        wls_bpf_fail('unable to inspect clang-18 provenance.');
    }
    $version = '';
    $outputExceeded = false;
    $pipeFailed = false;
    $termSent = false;
    $killSent = false;
    $deadlineTerminationStarted = false;
    $running = true;
    $observedExitBeforeDeadline = false;
    $exitCode = -1;
    foreach ($pipes as $descriptor => $pipe) {
        if (!\is_resource($pipe) || !\stream_set_blocking($pipe, false)) {
            $pipeFailed = true;
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
            unset($pipes[$descriptor]);
        }
    }

    for (;;) {
        $now = \hrtime(true);
        if ($now >= $absoluteDeadline) {
            break;
        }
        $processStatus = \proc_get_status($process);
        if (!\is_array($processStatus)) {
            $pipeFailed = true;
        } else {
            $running = (bool)($processStatus['running'] ?? true);
            if (!$running) {
                $observedExitBeforeDeadline = true;
                $exitCode = (int)($processStatus['exitcode'] ?? -1);
            }
        }
        if (!wls_bpf_drain_clang_pipes(
            $pipes,
            $version,
            $outputExceeded,
        )) {
            $pipeFailed = true;
        }
        if (!$running) {
            if (\hrtime(true) >= $absoluteDeadline) {
                $observedExitBeforeDeadline = false;
            }
            break;
        }

        $now = \hrtime(true);
        if ($now >= $absoluteDeadline) {
            break;
        }
        $remaining = $absoluteDeadline - $now;
        if (!$termSent
            && $remaining <= WLS_BPF_CLANG_TERM_GRACE_NANOSECONDS
                + WLS_BPF_CLANG_KILL_REAP_NANOSECONDS
        ) {
            $deadlineTerminationStarted = true;
        }
        if (!$termSent
            && ($pipeFailed || $outputExceeded || $deadlineTerminationStarted)
        ) {
            @\proc_terminate($process, 15);
            $termSent = true;
        }
        if (!$killSent
            && $remaining <= WLS_BPF_CLANG_KILL_REAP_NANOSECONDS
        ) {
            @\proc_terminate($process, 9);
            $killSent = true;
        }

        $waitNanoseconds = \min(
            WLS_BPF_CLANG_POLL_NANOSECONDS,
            $remaining,
        );
        if (!$termSent) {
            $waitNanoseconds = \min(
                $waitNanoseconds,
                \max(
                    0,
                    $remaining
                        - WLS_BPF_CLANG_TERM_GRACE_NANOSECONDS
                        - WLS_BPF_CLANG_KILL_REAP_NANOSECONDS,
                ),
            );
        } elseif (!$killSent) {
            $waitNanoseconds = \min(
                $waitNanoseconds,
                \max(
                    0,
                    $remaining - WLS_BPF_CLANG_KILL_REAP_NANOSECONDS,
                ),
            );
        }
        wls_bpf_wait_for_clang_activity($pipes, $waitNanoseconds);
    }

    foreach ($pipes as $pipe) {
        @\fclose($pipe);
    }
    $closeStatus = -1;
    if ($observedExitBeforeDeadline && \hrtime(true) < $absoluteDeadline) {
        $closeStatus = \proc_close($process);
    }
    $status = $exitCode >= 0 ? $exitCode : $closeStatus;
    if ($pipeFailed) {
        wls_bpf_fail('clang version 18 provenance pipe handling failed.');
    }
    if ($outputExceeded) {
        wls_bpf_fail('clang version 18 provenance output exceeded its bounded limit.');
    }
    if ($deadlineTerminationStarted || !$observedExitBeforeDeadline) {
        wls_bpf_fail('clang version 18 provenance check exhausted its deadline.');
    }
    if ($status !== 0
        || \preg_match('/\bclang version 18\./', $version) !== 1
    ) {
        wls_bpf_fail('clang version 18 provenance check failed.');
    }
}

/**
 * @param array<string, int> $section
 */
function wls_bpf_section_name(
    string $contents,
    array $section,
    array $stringSection,
): string {
    $nameOffset = $section['name'];
    if ($nameOffset < 0 || $nameOffset >= $stringSection['size']) {
        wls_bpf_fail('ELF section name offset is invalid.');
    }
    $start = $stringSection['offset'] + $nameOffset;
    $end = \strpos($contents, "\0", $start);
    if ($end === false || $end >= $stringSection['offset'] + $stringSection['size']) {
        wls_bpf_fail('ELF section name is unterminated.');
    }
    return \substr($contents, $start, $end - $start);
}

/**
 * @return array{
 *     code: string,
 *     relocations: array<string, list<int>>
 * }
 */
function wls_bpf_parse_elf(string $contents): array
{
    $size = \strlen($contents);
    if ($size < 64 || \substr($contents, 0, 4) !== "\x7fELF"
        || \ord($contents[4]) !== 2
        || \ord($contents[5]) !== 1
        || \ord($contents[6]) !== 1
        || wls_bpf_u16($contents, 16) !== 1
        || wls_bpf_u16($contents, 18) !== 247
        || wls_bpf_u16($contents, 52) !== 64
    ) {
        wls_bpf_fail('object is not a little-endian ELF64 EM_BPF relocatable file.');
    }

    $sectionOffset = wls_bpf_u64($contents, 40);
    $sectionEntrySize = wls_bpf_u16($contents, 58);
    $sectionCount = wls_bpf_u16($contents, 60);
    $sectionNamesIndex = wls_bpf_u16($contents, 62);
    if ($sectionEntrySize !== 64 || $sectionCount !== 9
        || $sectionNamesIndex <= 0 || $sectionNamesIndex >= $sectionCount
        || !wls_bpf_range_is_valid(
            $size,
            $sectionOffset,
            $sectionCount * $sectionEntrySize,
        )
    ) {
        wls_bpf_fail('ELF section table violates the bounded clang-18 contract.');
    }

    $sections = [];
    for ($index = 0; $index < $sectionCount; ++$index) {
        $offset = $sectionOffset + ($index * $sectionEntrySize);
        $section = [
            'index' => $index,
            'name' => wls_bpf_u32($contents, $offset),
            'type' => wls_bpf_u32($contents, $offset + 4),
            'flags' => wls_bpf_u64($contents, $offset + 8),
            'offset' => wls_bpf_u64($contents, $offset + 24),
            'size' => wls_bpf_u64($contents, $offset + 32),
            'link' => wls_bpf_u32($contents, $offset + 40),
            'info' => wls_bpf_u32($contents, $offset + 44),
            'alignment' => wls_bpf_u64($contents, $offset + 48),
            'entry_size' => wls_bpf_u64($contents, $offset + 56),
        ];
        if (!wls_bpf_range_is_valid($size, $section['offset'], $section['size'])) {
            wls_bpf_fail('ELF section exceeds the object boundary.');
        }
        $sections[] = $section;
    }
    $stringSection = $sections[$sectionNamesIndex];
    if ($stringSection['type'] !== 3 || $stringSection['size'] === 0) {
        wls_bpf_fail('ELF section-name table is invalid.');
    }

    $expectedSections = [
        '' => 0,
        '.strtab' => 3,
        '.text' => 1,
        WLS_BPF_PROGRAM_SECTION => 1,
        WLS_BPF_RELOCATION_SECTION => 9,
        'maps' => 1,
        'license' => 1,
        '.llvm_addrsig' => 0x6fff4c03,
        '.symtab' => 2,
    ];
    $sectionsByName = [];
    foreach ($sections as $section) {
        $name = wls_bpf_section_name($contents, $section, $stringSection);
        if (!\array_key_exists($name, $expectedSections)
            || $section['type'] !== $expectedSections[$name]
            || isset($sectionsByName[$name])
        ) {
            wls_bpf_fail('Unexpected ELF section: ' . ($name === '' ? '<null>' : $name));
        }
        $sectionsByName[$name] = $section;
    }
    if (\count($sectionsByName) !== \count($expectedSections)) {
        wls_bpf_fail('ELF is missing a required clang-18 section.');
    }

    $codeSection = $sectionsByName[WLS_BPF_PROGRAM_SECTION];
    $mapSection = $sectionsByName['maps'];
    $licenseSection = $sectionsByName['license'];
    if ($sectionsByName['.text']['size'] !== 0
        || $codeSection['size'] <= 0
        || $codeSection['size'] > 1024 * 1024
        || $codeSection['size'] % WLS_BPF_INSTRUCTION_BYTES !== 0
        || $mapSection['size'] !== 60
        || \substr($contents, $licenseSection['offset'], $licenseSection['size']) !== "BSD\0"
    ) {
        wls_bpf_fail('ELF program, maps, or license section is malformed.');
    }

    $relocationSection = $sectionsByName[WLS_BPF_RELOCATION_SECTION];
    $symbolSection = $sectionsByName['.symtab'];
    if ($relocationSection['info'] !== $codeSection['index']
        || $relocationSection['link'] !== $symbolSection['index']
        || $relocationSection['entry_size'] !== 16
        || $relocationSection['size'] !== 66 * 16
        || $symbolSection['entry_size'] !== 24
        || $symbolSection['size'] % 24 !== 0
        || $symbolSection['link'] !== $sectionsByName['.strtab']['index']
    ) {
        wls_bpf_fail('ELF relocation or symbol table violates the expected contract.');
    }

    $relocations = [
        'wls_h3_worker_map' => [],
        'wls_h3_count_map' => [],
        'wls_h3_listen_map' => [],
    ];
    $symbolCount = intdiv($symbolSection['size'], 24);
    $lastInstruction = -1;
    for ($offset = 0; $offset < $relocationSection['size']; $offset += 16) {
        $record = $relocationSection['offset'] + $offset;
        $relocationOffset = wls_bpf_u64($contents, $record);
        $relocationInfo = wls_bpf_u64($contents, $record + 8);
        $relocationType = $relocationInfo & 0xffffffff;
        $symbolIndex = $relocationInfo >> 32;
        if ($relocationType !== WLS_BPF_R_BPF_64_64
            || $symbolIndex <= 0 || $symbolIndex >= $symbolCount
            || $relocationOffset % WLS_BPF_INSTRUCTION_BYTES !== 0
            || $relocationOffset + (2 * WLS_BPF_INSTRUCTION_BYTES) > $codeSection['size']
        ) {
            wls_bpf_fail('ELF contains an invalid R_BPF_64_64 relocation.');
        }
        $instructionIndex = intdiv($relocationOffset, WLS_BPF_INSTRUCTION_BYTES);
        if ($instructionIndex <= $lastInstruction
            || \ord($contents[$codeSection['offset'] + $relocationOffset]) !== 0x18
        ) {
            wls_bpf_fail('ELF relocation does not target an ordered LD_IMM64 instruction.');
        }
        $lastInstruction = $instructionIndex;

        $symbolOffset = $symbolSection['offset'] + ($symbolIndex * 24);
        $symbolNameOffset = wls_bpf_u32($contents, $symbolOffset);
        $symbolInfo = \ord($contents[$symbolOffset + 4]);
        $symbolSectionIndex = wls_bpf_u16($contents, $symbolOffset + 6);
        $symbolSize = wls_bpf_u64($contents, $symbolOffset + 16);
        $symbolNames = $sectionsByName['.strtab'];
        if ($symbolNameOffset >= $symbolNames['size']) {
            wls_bpf_fail('ELF relocation symbol name is out of range.');
        }
        $nameStart = $symbolNames['offset'] + $symbolNameOffset;
        $nameEnd = \strpos($contents, "\0", $nameStart);
        if ($nameEnd === false || $nameEnd >= $symbolNames['offset'] + $symbolNames['size']) {
            wls_bpf_fail('ELF relocation symbol name is unterminated.');
        }
        $symbolName = \substr($contents, $nameStart, $nameEnd - $nameStart);
        if (!isset($relocations[$symbolName])
            || ($symbolInfo >> 4) !== 1 || ($symbolInfo & 0x0f) !== 1
            || $symbolSectionIndex !== $mapSection['index'] || $symbolSize !== 20
        ) {
            wls_bpf_fail('ELF contains an unexpected relocation symbol.');
        }
        $relocations[$symbolName][] = $instructionIndex;
    }
    if (\count($relocations['wls_h3_worker_map']) !== 1
        || \count($relocations['wls_h3_count_map']) !== 1
        || \count($relocations['wls_h3_listen_map']) !== 64
    ) {
        wls_bpf_fail('ELF map relocation counts are not 1/1/64.');
    }

    return [
        'code' => \substr($contents, $codeSection['offset'], $codeSection['size']),
        'relocations' => $relocations,
    ];
}

/** @param list<int> $indexes */
function wls_bpf_format_indexes(array $indexes): string
{
    $lines = [];
    foreach (\array_chunk($indexes, 8) as $chunk) {
        $lines[] = '  ' . \implode(', ', \array_map(
            static fn(int $index): string => $index . 'u',
            $chunk,
        ));
    }
    return \implode(",\n", $lines) . PHP_EOL;
}

/**
 * @param array<string, list<int>> $relocations
 */
function wls_bpf_render_header(
    string $code,
    array $relocations,
    string $sourceSha256,
): string {
    $wls_linux_reuseport_bpf_code_sha256 = \hash('sha256', $code);
    $byteLines = [];
    $bytes = \array_values(\unpack('C*', $code));
    foreach (\array_chunk($bytes, 12) as $chunk) {
        $byteLines[] = '  ' . \implode(', ', \array_map(
            static fn(int $byte): string => \sprintf('0x%02x', $byte),
            $chunk,
        )) . ',';
    }

    return '#ifndef WLS_LINUX_REUSEPORT_BPF_CODE_H' . PHP_EOL
        . '#define WLS_LINUX_REUSEPORT_BPF_CODE_H' . PHP_EOL . PHP_EOL
        . '/*' . PHP_EOL
        . ' * Generated from wls_linux_reuseport_bpf.c by the bounded ELF generator.' . PHP_EOL
        . ' * Compiler provenance: clang major 18.' . PHP_EOL
        . ' * Source SHA-256: ' . $sourceSha256 . PHP_EOL
        . ' * Program SHA-256: ' . $wls_linux_reuseport_bpf_code_sha256 . PHP_EOL
        . ' */' . PHP_EOL
        . '#define WLS_LINUX_REUSEPORT_BPF_CLANG_MAJOR 18u' . PHP_EOL
        . '#define WLS_LINUX_REUSEPORT_BPF_SOURCE_SHA256 "' . $sourceSha256 . '"' . PHP_EOL
        . '#define WLS_LINUX_REUSEPORT_BPF_CODE_SHA256 "'
        . $wls_linux_reuseport_bpf_code_sha256 . '"' . PHP_EOL . PHP_EOL
        . 'static const unsigned char wls_linux_reuseport_bpf_code[] = {' . PHP_EOL
        . \implode(PHP_EOL, $byteLines) . PHP_EOL
        . '};' . PHP_EOL
        . 'static const unsigned int wls_linux_reuseport_bpf_code_len = '
        . \strlen($code) . 'u;' . PHP_EOL . PHP_EOL
        . '/* ELF R_BPF_64_64 relocation instruction indexes emitted with this image. */' . PHP_EOL
        . 'static const unsigned int wls_linux_reuseport_bpf_worker_map_relocations[] = {' . PHP_EOL
        . wls_bpf_format_indexes($relocations['wls_h3_worker_map'])
        . '};' . PHP_EOL
        . 'static const unsigned int wls_linux_reuseport_bpf_count_map_relocations[] = {' . PHP_EOL
        . wls_bpf_format_indexes($relocations['wls_h3_count_map'])
        . '};' . PHP_EOL
        . 'static const unsigned int wls_linux_reuseport_bpf_listen_map_relocations[] = {' . PHP_EOL
        . wls_bpf_format_indexes($relocations['wls_h3_listen_map'])
        . '};' . PHP_EOL . PHP_EOL
        . '#endif' . PHP_EOL;
}

function wls_bpf_write_atomic(string $path, string $contents): void
{
    $allowedNames = [
        'wls_linux_reuseport_bpf_code.h',
        'wls_linux_reuseport_bpf_generated.h',
    ];
    if (!\in_array(\basename($path), $allowedNames, true)
        || \is_link($path)
        || (\file_exists($path) && !\is_file($path))
    ) {
        wls_bpf_fail('output must be an approved regular header path.');
    }
    $parent = \realpath(\dirname($path));
    if (!\is_string($parent) || !\is_dir($parent) || !\is_writable($parent)) {
        wls_bpf_fail('output parent must be an existing writable directory.');
    }
    $temporary = \tempnam($parent, '.wls-bpf-header-');
    if (!\is_string($temporary)) {
        wls_bpf_fail('unable to allocate an atomic output candidate.');
    }
    $published = false;
    try {
        $written = \file_put_contents($temporary, $contents, LOCK_EX);
        if ($written === \strlen($contents) && \chmod($temporary, 0644)) {
            $published = \rename($temporary, $path);
        }
    } finally {
        if (\file_exists($temporary)) {
            @\unlink($temporary);
        }
    }
    if (!$published) {
        wls_bpf_fail('unable to publish the generated header atomically.');
    }
}

$options = wls_bpf_arguments($argv);
wls_bpf_verify_clang($options['clang']);
$source = wls_bpf_read_regular_file(
    $options['source'],
    'wls_linux_reuseport_bpf.c',
    WLS_BPF_MAX_SOURCE_BYTES,
);
$object = wls_bpf_read_regular_file(
    $options['object'],
    'wls_linux_reuseport_bpf.o',
    WLS_BPF_MAX_OBJECT_BYTES,
);
$parsed = wls_bpf_parse_elf($object);
$header = wls_bpf_render_header(
    $parsed['code'],
    $parsed['relocations'],
    \hash('sha256', $source),
);
wls_bpf_write_atomic($options['output'], $header);
\fwrite(
    STDOUT,
    'generated clang=18 source_sha256=' . \hash('sha256', $source)
        . ' code_sha256=' . \hash('sha256', $parsed['code']) . PHP_EOL,
);
