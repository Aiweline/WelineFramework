<?php
declare(strict_types=1);

/**
 * GuoLaiRen SMTP Module
 * List Email List Files - Show available recipient files
 * 
 * @category  GuoLaiRen
 * @package   GuoLaiRen_Smtp
 * @author    GuoLaiRen Development Team
 */

namespace GuoLaiRen\Smtp\Console\Mail;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class ListFiles implements CommandInterface
{
    private Printing $printer;
    private string $baseDir;

    public function __construct(Printing $printer)
    {
        $this->printer = $printer;
        $this->baseDir = __DIR__;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $emailsDir = $this->baseDir . '/emails';
        
        if (!is_dir($emailsDir)) {
            $this->printer->error(__('Emails directory not found'));
            return;
        }

        $this->printer->note(__(''));
        $this->printer->note(__('========== Available Email List Files =========='));
        $this->printer->note(__(''));
        $this->printer->note(__('Directory: ') . $emailsDir);
        $this->printer->note(__(''));

        $files = scandir($emailsDir);
        $count = 0;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filepath = $emailsDir . '/' . $file;
            
            if (is_file($filepath)) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $size = filesize($filepath);
                $sizeStr = $this->formatBytes($size);
                
                // Check if supported format
                $supported = in_array($extension, ['txt', 'csv', 'json', 'xlsx', 'xls']);
                $icon = $supported ? '✓' : '✗';
                
                $this->printer->note(__($icon . ' ' . $file . ' (' . $sizeStr . ')'));
                
                if ($supported) {
                    $count++;
                }
            }
        }

        $this->printer->note(__(''));
        $this->printer->success(__('Found ') . $count . __(' supported file(s)'));
        $this->printer->note(__(''));
        $this->printer->note(__('Supported formats: .txt, .csv, .json, .xlsx, .xls'));
        $this->printer->note(__(''));
        $this->printer->note(__('Usage: php bin/w mail:bulk --file="filename.xlsx" --subject="Subject" --body="Content"'));
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'List all available email list files in emails directory';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'mail:list',
            $this->tip(),
            [
                '-h, --help' => 'Display help information',
            ],
            [
                'php bin/w mail:list',
            ],
            []
        );
    }
}

