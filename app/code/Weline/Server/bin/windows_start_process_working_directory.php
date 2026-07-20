<?php

declare(strict_types=1);

/**
 * Restore the original project cwd for PHP children launched by Windows
 * Start-Process. The launcher itself must use a local cwd because Windows
 * rejects UNC WorkingDirectory values.
 */
if (!\function_exists('wlsRestoreWindowsStartProcessWorkingDirectory')) {
    function wlsRestoreWindowsStartProcessWorkingDirectory(): void
    {
        $workingDirectory = \getenv('WELINE_START_PROCESS_CWD');
        if (\PHP_OS_FAMILY !== 'Windows' || !\is_string($workingDirectory) || \trim($workingDirectory) === '') {
            return;
        }

        $workingDirectory = \trim($workingDirectory);
        if (!\is_dir($workingDirectory) || !@\chdir($workingDirectory)) {
            throw new \RuntimeException(
                'Unable to restore Windows WLS working directory: ' . $workingDirectory
            );
        }

        \putenv('WELINE_START_PROCESS_CWD');
        unset($_ENV['WELINE_START_PROCESS_CWD'], $_SERVER['WELINE_START_PROCESS_CWD']);
    }
}

wlsRestoreWindowsStartProcessWorkingDirectory();
