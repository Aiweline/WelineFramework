<?php
declare(strict_types=1);

/**
 * GuoLaiRen SMTP Module
 * Bulk Email Campaign Command - Multi-account load balancing
 * 
 * @category  GuoLaiRen
 * @package   GuoLaiRen_Smtp
 * @author    GuoLaiRen Development Team
 */

namespace GuoLaiRen\Smtp\Console\Mail;

use GuoLaiRen\Smtp\Helper\SmtpMailer;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Bulk implements CommandInterface
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
        try {
            // Load SMTP account configuration
            $smtpConfig = $this->loadSmtpConfig();
            if (empty($smtpConfig['accounts'])) {
                $this->printer->error(__('No SMTP accounts available'));
                return;
            }

            // Filter enabled accounts
            $enabledAccounts = array_filter($smtpConfig['accounts'], function($account) {
                return $account['enabled'] ?? true;
            });

            if (empty($enabledAccounts)) {
                $this->printer->error(__('No enabled SMTP accounts found'));
                return;
            }

            $this->printer->note(__('Found ') . count($enabledAccounts) . __(' active SMTP account(s)'));

            // Load recipient list
            $emailFile = $args['file'] ?? null;
            
            // Auto-detect Excel file if not specified
            if (!$emailFile) {
                $this->printer->note(__('No file specified, auto-detecting Excel files...'));
                $emailFile = $this->autoDetectExcelFile();
                if (!$emailFile) {
                    $this->printer->error(__('No Excel files found in emails directory'));
                    return;
                }
                $this->printer->note(__('Using detected file: ') . $emailFile);
            } else {
                // Check if specified file exists
                $filepath = $this->baseDir . '/emails/' . $emailFile;
                if (!file_exists($filepath)) {
                    $this->printer->error(__('File not found: ') . $emailFile);
                    $this->printer->note(__('Available files in emails directory:'));
                    $files = scandir($this->baseDir . '/emails');
                    foreach ($files as $file) {
                        if ($file !== '.' && $file !== '..' && is_file($this->baseDir . '/emails/' . $file)) {
                            $this->printer->note(__('  - ') . $file);
                        }
                    }
                    return;
                }
                $this->printer->note(__('Using specified file: ') . $emailFile);
            }
            
            $recipients = $this->loadRecipients($emailFile);

            if (empty($recipients)) {
                $this->printer->error(__('Recipient list is empty'));
                return;
            }

            $totalLoaded = count($recipients);
            $this->printer->note(__('Loaded ') . $totalLoaded . __(' recipient(s)'));
            
            // Apply limit if specified
            $limit = isset($args['limit']) ? (int)$args['limit'] : 0;
            if ($limit > 0 && $limit < $totalLoaded) {
                $recipients = array_slice($recipients, 0, $limit);
                $this->printer->note(__('Limiting to first ') . $limit . __(' recipient(s)'));
            }

            // Prepare email content
            $subject = $args['subject'] ?? 'Business Communication';
            
            // Load available templates
            $templates = $this->loadTemplates();
            if (empty($templates)) {
                $this->printer->error(__('No email templates found in templates directory'));
                return;
            }
            $this->printer->note(__('Found ') . count($templates) . __(' email template(s)'));
            
            $isHtml = true; // Always use HTML for templates

            // Distribute and send
            $this->printer->note(__('Initiating bulk email campaign...'));
            $results = $this->distributeSend($enabledAccounts, $recipients, $subject, $templates, $isHtml);

            // Display campaign results
            $this->displayResults($results);

        } catch (\Exception $exception) {
            $this->printer->error(__('Bulk sending failed: ') . $exception->getMessage());
        }
    }

    /**
     * Auto-detect Excel file in emails directory
     */
    private function autoDetectExcelFile(): ?string
    {
        $emailsDir = $this->baseDir . '/emails';
        $files = scandir($emailsDir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, ['xlsx', 'xls'])) {
                return $file;
            }
        }
        
        return null;
    }

    /**
     * Load SMTP account configuration
     */
    private function loadSmtpConfig(): array
    {
        $configFile = $this->baseDir . '/smtps.json';
        
        if (!file_exists($configFile)) {
            throw new \Exception('SMTP configuration file not found: ' . $configFile);
        }

        $json = file_get_contents($configFile);
        $config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid SMTP configuration file: ' . json_last_error_msg());
        }

        return $config;
    }

    /**
     * Load recipient list from file
     */
    private function loadRecipients(string $filename): array
    {
        $filepath = $this->baseDir . '/emails/' . $filename;

        if (!file_exists($filepath)) {
            throw new \Exception('Recipient list file not found: ' . $filepath);
        }

        $extension = pathinfo($filepath, PATHINFO_EXTENSION);

        switch (strtolower($extension)) {
            case 'txt':
                return $this->loadFromTxt($filepath);
            case 'csv':
                return $this->loadFromCsv($filepath);
            case 'json':
                return $this->loadFromJson($filepath);
            case 'xlsx':
            case 'xls':
                return $this->loadFromExcel($filepath);
            default:
                throw new \Exception('Unsupported file format: ' . $extension);
        }
    }

    /**
     * Load recipients from TXT file
     */
    private function loadFromTxt(string $filepath): array
    {
        $recipients = [];
        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comment lines
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Parse format: Name <email@example.com> or email@example.com
            if (preg_match('/^(.+?)\s*<(.+?)>$/', $line, $matches)) {
                $recipients[] = [
                    'email' => trim($matches[2]),
                    'name' => trim($matches[1])
                ];
            } else {
                $recipients[] = [
                    'email' => $line,
                    'name' => ''
                ];
            }
        }

        return $recipients;
    }

    /**
     * Load recipients from CSV file
     */
    private function loadFromCsv(string $filepath): array
    {
        $recipients = [];
        $file = fopen($filepath, 'r');
        
        if ($file === false) {
            throw new \Exception('Unable to open CSV file');
        }

        // Read headers
        $headers = fgetcsv($file);
        
        while (($row = fgetcsv($file)) !== false) {
            $data = array_combine($headers, $row);
            
            // Skip inactive accounts
            if (isset($data['status']) && $data['status'] === 'inactive') {
                continue;
            }

            $recipients[] = [
                'email' => $data['email'] ?? '',
                'name' => $data['name'] ?? ''
            ];
        }

        fclose($file);
        return $recipients;
    }

    /**
     * Load recipients from JSON file
     */
    private function loadFromJson(string $filepath): array
    {
        $json = file_get_contents($filepath);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON file format: ' . json_last_error_msg());
        }

        $recipients = [];
        foreach ($data['recipients'] ?? [] as $recipient) {
            if (isset($recipient['active']) && !$recipient['active']) {
                continue;
            }

            $recipients[] = [
                'email' => $recipient['email'] ?? '',
                'name' => $recipient['name'] ?? ''
            ];
        }

        return $recipients;
    }

    /**
     * Load recipients from Excel file (.xlsx, .xls)
     */
    private function loadFromExcel(string $filepath): array
    {
        $recipients = [];
        
        try {
            $spreadsheet = IOFactory::load($filepath);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Get the highest row number
            $highestRow = $worksheet->getHighestRow();
            
            // Read headers from first row
            $headers = [];
            $emailColumn = null;
            $nameColumn = null;
            $statusColumn = null;
            
            // Find column indices
            foreach ($worksheet->getRowIterator(1, 1) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                
                $colIndex = 0;
                foreach ($cellIterator as $cell) {
                    $value = strtolower(trim($cell->getValue() ?? ''));
                    $headers[$colIndex] = $value;
                    
                    // Flexible column matching
                    if (in_array($value, ['email', 'e-mail', 'emailaddress', 'email address', '邮箱', '电子邮件'])) {
                        $emailColumn = $colIndex;
                    } elseif (in_array($value, ['name', 'fullname', 'full name', 'username', '姓名', '名字'])) {
                        $nameColumn = $colIndex;
                    } elseif (in_array($value, ['status', 'active', 'enabled', '状态'])) {
                        $statusColumn = $colIndex;
                    }
                    
                    $colIndex++;
                }
            }
            
            // If email column not found, try first column
            if ($emailColumn === null) {
                $this->printer->note(__('Email column not detected, using first column as email'));
                $emailColumn = 0;
            }
            
            // Read data rows (starting from row 2)
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = $worksheet->rangeToArray('A' . $row . ':' . $worksheet->getHighestColumn() . $row, null, true, false)[0];
                
                // Get email
                $email = isset($rowData[$emailColumn]) ? trim($rowData[$emailColumn]) : '';
                
                // Skip empty emails
                if (empty($email)) {
                    continue;
                }
                
                // Get name
                $name = '';
                if ($nameColumn !== null && isset($rowData[$nameColumn])) {
                    $name = trim($rowData[$nameColumn]);
                }
                
                // Check status
                if ($statusColumn !== null && isset($rowData[$statusColumn])) {
                    $status = strtolower(trim($rowData[$statusColumn]));
                    if (in_array($status, ['inactive', 'disabled', 'false', '0', 'no', '否', '禁用'])) {
                        continue;
                    }
                }
                
                $recipients[] = [
                    'email' => $email,
                    'name' => $name
                ];
            }
            
        } catch (\Exception $e) {
            throw new \Exception('Failed to read Excel file: ' . $e->getMessage());
        }
        
        return $recipients;
    }

    /**
     * Load email template
     */
    private function loadEmailTemplate(array $args): string
    {
        $templateFile = $this->baseDir . '/mail.html';
        
        if (!file_exists($templateFile)) {
            throw new \Exception('Email template file not found');
        }

        $template = file_get_contents($templateFile);

        // Replace template variables
        $replacements = [
            '{{TITLE}}' => $args['title'] ?? 'Important Announcement',
            '{{RECIPIENT_NAME}}' => '{{RECIPIENT_NAME}}', // Replaced at send time
            '{{CONTENT}}' => $args['content'] ?? 'This is the email content.',
            '{{BUTTON}}' => $args['button'] ?? '',
            '{{SENDER_NAME}}' => $args['sender'] ?? 'Stock Circle',
            '{{YEAR}}' => date('Y'),
            '{{COMPANY_NAME}}' => $args['company'] ?? 'Stock Circle',
            '{{UNSUBSCRIBE_LINK}}' => $args['unsubscribe'] ?? '#'
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Get default email body
     */
    private function getDefaultEmailBody(): string
    {
        return '<h1>Business Communication</h1><p>You are receiving this email because you are on our mailing list.</p>';
    }

    /**
     * Load email templates from templates directory
     */
    private function loadTemplates(): array
    {
        $templatesDir = $this->baseDir . '/templates';
        $templates = [];
        
        if (!is_dir($templatesDir)) {
            return $templates;
        }
        
        $files = scandir($templatesDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extension === 'html') {
                $filepath = $templatesDir . '/' . $file;
                $templates[] = [
                    'name' => $file,
                    'path' => $filepath,
                    'content' => file_get_contents($filepath)
                ];
            }
        }
        
        return $templates;
    }

    /**
     * Distribute emails across multiple SMTP accounts
     */
    private function distributeSend(array $accounts, array $recipients, string $subject, array $templates, bool $isHtml): array
    {
        $accountCount = count($accounts);
        $recipientCount = count($recipients);
        $perAccount = (int)ceil($recipientCount / $accountCount);

        $results = [
            'total' => $recipientCount,
            'success' => 0,
            'failed' => 0,
            'details' => []
        ];

        $accountIndex = 0;
        foreach ($accounts as $account) {
            $start = (int)($accountIndex * $perAccount);
            $end = (int)min($start + $perAccount, $recipientCount);
            $batch = array_slice($recipients, $start, $end - $start);

            if (empty($batch)) {
                break;
            }

            $this->printer->note(__('Account [') . $account['username'] . __('] sending ') . count($batch) . __(' emails (') . ($start + 1) . '-' . $end . ')');

            $accountResults = $this->sendBatch($account, $batch, $subject, $templates, $isHtml);
            
            $results['success'] += $accountResults['success'];
            $results['failed'] += $accountResults['failed'];
            $results['details'][$account['username']] = $accountResults;

            $accountIndex++;
        }

        return $results;
    }

    /**
     * Send batch of emails using single account
     */
    private function sendBatch(array $account, array $recipients, string $subject, array $templates, bool $isHtml): array
    {
        $results = ['success' => 0, 'failed' => 0, 'emails' => []];

        // Create SMTP mailer instance
        $mailer = $this->createMailer($account);
        
        $templateCount = count($templates);

        foreach ($recipients as $recipient) {
            try {
                // Randomly select a template
                $randomTemplate = $templates[array_rand($templates)];
                
                // Personalize email content - support multiple variable formats
                $recipientName = $recipient['name'] ?: 'Valued Investor';
                $recipientEmail = $recipient['email'];
                $personalizedBody = $randomTemplate['content'];
                
                // Replace Mailchimp variables
                $personalizedBody = str_replace('*|MC:SUBJECT|*', $subject, $personalizedBody);
                $personalizedBody = str_replace('*|MC_PREVIEW_TEXT|*', substr(strip_tags($subject), 0, 100), $personalizedBody);
                $personalizedBody = str_replace('*|ARCHIVE|*', '#', $personalizedBody); // Archive link placeholder
                
                // Replace name variable formats
                $personalizedBody = str_replace('{{RECIPIENT_NAME}}', $recipientName, $personalizedBody);
                $personalizedBody = str_replace('*|FNAME|*', $recipientName, $personalizedBody);
                $personalizedBody = str_replace('*|NAME|*', $recipientName, $personalizedBody);
                $personalizedBody = str_replace('[RECIPIENT_NAME]', $recipientName, $personalizedBody);
                $personalizedBody = str_replace('*|EMAIL|*', $recipientEmail, $personalizedBody);
                
                // Remove Mailchimp conditional statements
                $personalizedBody = preg_replace('/<!--\*\|IF:.*?\|\*-->/', '', $personalizedBody);
                $personalizedBody = preg_replace('/<!--\*\|END:IF\|\*-->/', '', $personalizedBody);

                $sent = $mailer->sendMail(
                    $recipient['email'],
                    $recipient['name'],
                    $subject,
                    $personalizedBody,
                    $isHtml
                );

                if ($sent) {
                    $results['success']++;
                    $this->printer->success(__('✓ Sent: ') . $recipient['email'] . ' [Template: ' . $randomTemplate['name'] . ']');
                } else {
                    $results['failed']++;
                    $this->printer->error(__('✗ Failed: ') . $recipient['email']);
                }

                $results['emails'][] = [
                    'email' => $recipient['email'],
                    'status' => $sent ? 'success' : 'failed'
                ];

                // Rate limiting delay
                usleep(100000); // 100ms

            } catch (\Exception $e) {
                $results['failed']++;
                $this->printer->error(__('✗ Error: ') . $recipient['email'] . ' - ' . $e->getMessage());
                
                $results['emails'][] = [
                    'email' => $recipient['email'],
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Create mailer instance from account configuration
     */
    private function createMailer(array $account): SmtpMailer
    {
        return new SmtpMailer($account);
    }

    /**
     * Display campaign results summary
     */
    private function displayResults(array $results): void
    {
        $this->printer->note(__(''));
        $this->printer->note(__('========== Campaign Results =========='));
        $this->printer->note(__('Total: ') . $results['total']);
        $this->printer->success(__('Successful: ') . $results['success']);
        $this->printer->error(__('Failed: ') . $results['failed']);
        $successRate = $results['total'] > 0 ? ($results['success'] / $results['total']) * 100 : 0;
        $this->printer->note(__('Success Rate: ') . number_format($successRate, 2) . '%');
        $this->printer->note(__(''));

        foreach ($results['details'] as $account => $detail) {
            $this->printer->note(__('Account [') . $account . __(']: Success ') . $detail['success'] . __(', Failed ') . $detail['failed']);
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'Bulk email campaign with multi-account load balancing. Example: php bin/w mail:bulk --file=recipients.xlsx --subject="Subject" --limit=10';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'mail:bulk',
            $this->tip(),
            [
                '-h, --help' => 'Display help information',
                '--file' => 'Recipient list filename (optional, auto-detect .xlsx if not specified)',
                '--subject' => 'Email subject line (required)',
                '--limit' => 'Limit number of recipients to send (optional, sends first N recipients)',
                '--body' => 'Email content (optional, uses random template from templates/ by default)',
                '--html' => 'Send as HTML format (optional, default: 1)',
            ],
            [
                'php bin/w mail:bulk --subject="Newsletter"',
                'php bin/w mail:bulk --file="meta个人邮箱版本-1023.xlsx" --subject="Market Update"',
                'php bin/w mail:bulk --file="recipients.txt" --subject="Announcement" --limit=50',
                'php bin/w mail:bulk --subject="Investment Report" --limit=10',
            ],
            []
        );
    }
}

