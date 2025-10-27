<?php
declare(strict_types=1);

/**
 * GuoLaiRen SMTP Module
 * Email Sending Command - For single email dispatch
 * 
 * @category  GuoLaiRen
 * @package   GuoLaiRen_Smtp
 * @author    GuoLaiRen Development Team
 */

namespace GuoLaiRen\Smtp\Console\Mail;

use GuoLaiRen\Smtp\Helper\SmtpMailer;
use GuoLaiRen\Smtp\Helper\BreakpointManager;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Send implements CommandInterface
{
    private Printing $printer;
    private SmtpMailer $mailer;
    private string $baseDir;
    private BreakpointManager $breakpointManager;
    private array $smtpAccounts = [];  // All available SMTP accounts
    private int $currentAccountIndex = 0;  // Current account index for rotation
    private $savedCallback = null;  // Saved callback for account switching
    private array $accountSendCounts = [];  // Track emails sent per account
    private int $maxEmailsPerAccount = 100;  // Max emails per account per session
    private array $lastFailedBatch = [];  // Store last failed batch for retry on account switch
    private int $consecutiveRateLimitErrors = 0;  // Track consecutive rate limit errors across account switches
    private const MAX_ACCOUNT_SWITCHES_BEFORE_WAIT = 3;  // Try 3 different accounts before waiting

    public function __construct(
        Printing $printer,
        SmtpMailer $mailer
    ) {
        $this->printer = $printer;
        $this->mailer = $mailer;
        $this->baseDir = dirname(__DIR__) . '/Mail';
        
        // Initialize breakpoint manager
        $emailsDir = $this->baseDir . '/emails';
        $this->breakpointManager = new BreakpointManager($emailsDir);
        
        // Load SMTP configuration from smtps.json
        $this->initializeSmtpMailer();
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // Validate required parameters
        if (empty($args['to'])) {
            $this->printer->error(__('Recipient address (--to) is required'));
            return;
        }

        if (empty($args['subject'])) {
            $this->printer->error(__('Email subject (--subject) is required'));
            return;
        }

        // Check for debug mode - set flag for mailer
        if (isset($args['debug'])) {
            $this->mailer->setDebugMode(true);
            $this->printer->note(__('🔍 DEBUG MODE - Simulating send (no emails will be sent)'));
            $this->printer->note('');
        }

        // --body and --file are now optional, will auto-load from templates if not provided
        // Check if bulk mode is enabled
        $isBulk = isset($args['bulk']);
        
        try {
            if ($isBulk) {
                $this->executeBulkSend($args);
            } else {
                $this->executeSingleSend($args);
            }
        } catch (\Exception $exception) {
            $this->printer->error(__('Email sending error: ') . $exception->getMessage());
        }
    }


    /**
     * Send single email to one recipient (or multiple recipients with breakpoint support)
     */
    private function executeSingleSend(array $args): void
    {
            // Re-initialize BreakpointManager with recipient file identifier if needed
            $recipientFile = $args['to'] ?? '';
            
            // Parse recipients (supports single email or file with recipients)
            $recipients = $this->parseRecipients($args['to']);
            
            if (empty($recipients)) {
                $this->printer->error(__('No valid recipient found'));
                $this->printer->note(__('Please provide a valid email address or a file with recipients'));
                return;
            }
            
            // If multiple recipients detected, use batch sending with breakpoint support
            if (count($recipients) > 1) {
                $this->printer->note(__('Multiple recipients detected (' . count($recipients) . ' recipients)'));
                $this->printer->note(__('💡 Auto-enabling batch mode with breakpoint support'));
                $this->printer->note(__(''));
                
                // Set default bulk size to 1 if not specified
                if (!isset($args['bulk'])) {
                    $args['bulk'] = 1;
                }
                
                // Set default preview text if not specified (required for bulk)
                if (!isset($args['preview']) || $args['preview'] === '') {
                    $args['preview'] = substr($args['subject'], 0, 100); // Use subject as preview
                }
                
                // Call bulk send which has breakpoint support
                $this->executeBulkSend($args);
                return;
            }
            
            $this->printer->note(__('Sending email...'));
            
            // Get single recipient
            $recipient = $recipients[0];
            $to = $recipient['email'];
            $toName = $recipient['name'] ?? $args['to-name'] ?? '';

            // Set subject and content
            $subject = $args['subject'];
        $body = $args['body'] ?? '';
        
        // Load body from file if specified
        if (!empty($args['file']) && empty($body)) {
            if (file_exists($args['file'])) {
                $body = file_get_contents($args['file']);
                $this->printer->note(__('Loaded email body from: ') . $args['file']);
            } else {
                $this->printer->error(__('Body file not found: ') . $args['file']);
                return;
            }
        }
        
            $isHtml = !isset($args['html']) || $args['html']; // Default: true (HTML format)
            
            // Get preview text
            $previewText = $args['preview'] ?? '';
            
            // Step 1: Replace *|MC_PREVIEW_TEXT|* variable FIRST (before removing conditionals)
            if (!empty($previewText)) {
                $body = str_replace('*|MC_PREVIEW_TEXT|*', $previewText, $body);
                $this->printer->note(__('✓ Preview text: ') . substr($previewText, 0, 50) . (strlen($previewText) > 50 ? '...' : ''));
            } else {
                $body = str_replace('*|MC_PREVIEW_TEXT|*', '', $body);
            }
            
            // Step 2: Remove Mailchimp conditional comments AFTER variable replacement
            // This allows the template's <!--*|IF:MC_PREVIEW_TEXT|*--> to work correctly
            $body = preg_replace('/<!--\*\|IF:.*?\|\*-->/', '', $body);
            $body = preg_replace('/<!--\*\|END:IF\|\*-->/', '', $body);
            $body = preg_replace('/<!--\*\|ELSE:\|\*-->/', '', $body);

            // Set CC and BCC
            $cc = $args['cc'] ?? null;
            $bcc = $args['bcc'] ?? null;

            // Set attachment
            $attachment = $args['attachment'] ?? null;

            // Send email with preview text
            $result = $this->mailer->sendMail(
                $to,
                $toName,
                $subject,
                $body,
                $isHtml,
                $cc,
                $bcc,
                $attachment,
                $previewText  // Pass preview text to unified send method
            );

            if ($result) {
                $this->printer->success(__('Email sent successfully!'));
                $this->printer->note(__('Recipient: ') . $to);
                $this->printer->note(__('Subject: ') . $subject);
            } else {
                $this->printer->error(__('Email delivery failed!'));
            }
    }

    /**
     * Send bulk email using BCC (group send)
     */
    private function executeBulkSend(array $args): void
    {
        $this->printer->note(__('Preparing group email (BCC mode)...'));
        
        // Set default preview text if not specified
        if (!isset($args['preview']) || $args['preview'] === '') {
            $args['preview'] = substr($args['subject'], 0, 100); // Use subject as default preview
            $this->printer->note(__('💡 No preview text specified, using subject as preview'));
        }
        
        // Re-initialize BreakpointManager with recipient file identifier
        $recipientFile = $args['to'] ?? '';
        $emailsDir = $this->baseDir . '/emails';
        $this->breakpointManager = new BreakpointManager($emailsDir, $recipientFile);
        
        // No breakpoint system - rely entirely on sent_emails_*.json for progress tracking
        $this->printer->note(__('📁 Using sent emails file: sent_emails_') . $this->getFileIdentifier($recipientFile) . '.json');

        // Parse recipients
        $recipients = $this->parseRecipients($args['to']);
        
        if (empty($recipients)) {
            $this->printer->error(__('No valid recipients found'));
            return;
        }

        $totalRecipients = count($recipients);  // Original file total
        $originalTotalFromFile = $totalRecipients;  // Save original count before any modifications
        
        // Check for --fresh flag to ignore sent history
        $ignoreSentHistory = isset($args['fresh']) && $args['fresh'];
        
        // Check already sent emails (for display only, don't filter yet)
        $alreadySentCount = 0;
        if (!$ignoreSentHistory) {
            $alreadySentCount = count($this->breakpointManager->getSkippedRecipients($recipients));
        }
        
        if ($ignoreSentHistory) {
            $this->printer->warning(__('⚠️  Fresh mode enabled (--fresh) - will send to all recipients including previously sent'));
            $this->printer->note(__('💡 Sent email history will be ignored for this run'));
            $this->printer->note(__(''));
        } else if ($alreadySentCount > 0) {
            $this->printer->warning(__('📝 Found ') . $alreadySentCount . __(' already sent email(s), will skip but count toward progress'));
            $this->printer->note(__('💡 Progress = Already Sent + Currently Processing'));
            $this->printer->note(__(''));
        }

        // Add original index to each recipient before limiting
        foreach ($recipients as $idx => &$recipient) {
            $recipient['_original_index'] = $idx + 1;  // Store 1-based index
        }
        unset($recipient);  // Break reference
        
        // Apply limit if specified (on original list)
        $limit = isset($args['limit']) ? (int)$args['limit'] : 0;
        if ($limit > 0 && count($recipients) > $limit) {
            $recipients = array_slice($recipients, 0, $limit);
            $this->printer->note(__('Limited to first ') . $limit . __(' recipient(s) from file'));
            $totalRecipients = count($recipients);  // Update total to limited count for this run
        }

        // Handle failed emails retry (always start from beginning, skip sent emails via sent_emails_*.json)
        $failedEmailsForRetry = $this->breakpointManager->getFailedEmailsForRetry();
        
        if (!empty($failedEmailsForRetry)) {
            $this->printer->note(__(''));
            $this->printer->warning(__('⚡ Found ') . count($failedEmailsForRetry) . __(' failed emails from previous attempt'));
            $this->printer->note(__('📬 Will retry these emails first before continuing...'));
            $this->printer->note(__('💡 Maximum 3 failures allowed, then moved to permanent failed list'));
            
            foreach ($failedEmailsForRetry as $failed) {
                $display = !empty($failed['name']) ? "{$failed['name']} <{$failed['email']}>" : $failed['email'];
                $attemptNum = ($failed['retry_attempt'] ?? 0) + 1;
                $this->printer->note(__('  - ') . $display . __(' (attempt ') . $attemptNum . '/3)');
            }
            $this->printer->note(__(''));
        }
        
        $this->printer->note(__('Total in file: ') . $totalRecipients . __(' recipients'));
        if ($alreadySentCount > 0) {
            $this->printer->note(__('Already sent: ') . $alreadySentCount . __(' recipients'));
            $this->printer->note(__('Remaining: ') . ($totalRecipients - $alreadySentCount) . __(' recipients'));
        }
        
        // Limit display to first 10 recipients if too many
        $displayLimit = 10;
        $displayRecipients = array_slice($recipients, 0, $displayLimit);
        
        foreach ($displayRecipients as $recipient) {
            $name = !empty($recipient['name']) ? $recipient['name'] : '';
            $display = $name ? "{$name} <{$recipient['email']}>" : $recipient['email'];
            $this->printer->note(__('  - ') . $display);
        }
        
        if (count($recipients) > $displayLimit) {
            $this->printer->note(__('  ... and ') . (count($recipients) - $displayLimit) . __(' more recipients'));
        }

        // Get batch size from --bulk parameter
        $batchSize = (int)($args['bulk'] ?? 1);
        if ($batchSize < 1) {
            $batchSize = 1;
        }
        $this->printer->note(__('Batch size: ') . $batchSize . __(' recipient(s) per send'));

        // Display current SMTP account information
        $this->displayCurrentAccount();

        // Get email content
        $subject = $args['subject'];
        $body = $args['body'] ?? '';
        
        // Load body from file if specified
        if (!empty($args['file']) && empty($body)) {
            if (file_exists($args['file'])) {
                $body = file_get_contents($args['file']);
                $this->printer->note(__('Loaded email body from: ') . $args['file']);
            } else {
                $this->printer->error(__('Body file not found: ') . $args['file']);
                return;
            }
        }

        // If body is still empty, try to load from a random template
        if (empty($body)) {
            $templates = $this->loadTemplatesFromDirectory();
            if (!empty($templates)) {
                $randomTemplate = $templates[array_rand($templates)];
                $body = $randomTemplate['content'];
                $this->printer->note(__('Using template: ') . $randomTemplate['name']);
            } else {
                $this->printer->error(__('Email body is required (via --body, --file, or templates directory)'));
                return;
            }
        }

        $isHtml = !isset($args['html']) || $args['html']; // Default: true (HTML format)
        $cc = $args['cc'] ?? null;
        $attachment = $args['attachment'] ?? null;
        
        // Personalize: default disabled (--bulk is BCC group mode, --personalize is individual mode)
        // Replace-variables: default enabled (allows variable replacement in BCC group mode)
        $personalize = isset($args['personalize']) && $args['personalize'];
        $replaceVariables = !isset($args['replace-variables']) || $args['replace-variables'];
        $previewText = $args['preview'] ?? ''; // Preview text for email clients

        // Rate limiting parameters (RFC 5321 compliance - natural sending pattern to avoid spam filters)
        // Default: Random delay 30s-3min between batches, max 1000/hour
        // This creates a natural, human-like sending pattern and avoids triggering rate limits
        $maxDelayMs = isset($args['delay']) ? (int)$args['delay'] : 180000; // Default max 3 minutes (180000ms) between batches
        $minDelayMs = isset($args['min-delay']) ? (int)$args['min-delay'] : 30000; // Default min 30 seconds (30000ms) between batches
        $maxPerMinute = isset($args['max-per-minute']) ? (int)$args['max-per-minute'] : null; // No per-minute limit (controlled by delay)
        $maxPerHour = isset($args['max-per-hour']) ? (int)$args['max-per-hour'] : 1000; // Default 1000 emails/hour

        // Set up account rotation callback for load balancing
        if (count($this->smtpAccounts) > 1) {
            $this->savedCallback = function() {
                $this->switchToNextAccount();
            };
            $this->mailer->setAccountSwitchCallback($this->savedCallback);
        }
        
        // Send batch group email with progress tracking
        $this->printer->note(__(''));
        $this->printer->note(__('========== Starting Email Sending =========='));
        $result = $this->sendBatchGroupMailWithProgress(
            $recipients,
            $batchSize,
            $subject,
            $body,
            $isHtml,
            $personalize,
            $cc,
            $attachment,
            $minDelayMs,
            $maxDelayMs,
            $maxPerHour,
            $maxPerMinute,
            $replaceVariables,
            $failedEmailsForRetry,  // Add failed emails for retry
            $previewText,           // Add preview text
            $totalRecipients,       // Use limited total - limit means STOP at this number
            $ignoreSentHistory      // Add ignore sent history flag
        );

        // Display detailed results
        $this->displayDetailedResults($result);
        
        // No breakpoint system - progress is tracked via sent_emails_*.json
        // Use actual sent emails count as progress
        $totalSentEmails = $this->breakpointManager->getSentEmailsCount();
        
        // CRITICAL: Use limited total for display when --limit is set
        // This ensures progress shows correctly (e.g., 200/200 not 200/564)
        $displayTotal = $totalRecipients;  // Respects --limit parameter
        
        if ($totalSentEmails >= $displayTotal && $result['failed_count'] === 0) {
            $this->breakpointManager->clearAllFailedEmails();
            $this->printer->success(__('✓ All emails processed successfully!'));
            if ($limit > 0) {
                // If limit was used, show info about remaining emails in file
                $remaining = $originalTotalFromFile - $totalSentEmails;
                if ($remaining > 0) {
                    $this->printer->note(__('💡 Limit of ') . $displayTotal . __(' emails reached.'));
                    $this->printer->note(__('💡 File contains ') . $originalTotalFromFile . __(' total emails. ') . $remaining . __(' remaining.'));
                    $this->printer->note(__('💡 Increase --limit or remove it to send all emails.'));
                }
            } else {
                $this->printer->note(__('💡 Sent emails record preserved for future reference.'));
            }
        } else if ($totalSentEmails < $displayTotal) {
            $remaining = $displayTotal - $totalSentEmails;
            $this->printer->note(__(''));
            $this->printer->note(__('📍 Progress: ') . $totalSentEmails . '/' . $displayTotal . __(' emails sent'));
            $this->printer->note(__('💡 ') . $remaining . __(' email(s) remaining.'));
            $this->printer->note(__('💡 Run the same command again to continue - already sent emails will be automatically skipped.'));
        }
    }

    /**
     * Send batch group email with detailed progress tracking
     */
    private function sendBatchGroupMailWithProgress(
        array $recipients,
        int $batchSize,
        string $subject,
        string $body,
        bool $isHtml = false,
        bool $personalize = false,
        ?string $cc = null,
        ?string $attachment = null,
        int $minDelayMs = 30000,
        int $maxDelayMs = 180000,
        ?int $maxPerHour = null,
        ?int $maxPerMinute = null,
        bool $replaceVariables = false,
        array $failedEmailsForRetry = [],
        string $previewText = '',
        int $totalRecipientsOriginal = 0,
        bool $ignoreSentHistory = false
    ): array {
        // Use original total if provided, otherwise use current recipients count
        $originalTotal = $totalRecipientsOriginal > 0 ? $totalRecipientsOriginal : count($recipients);
        
        $results = [
            'success' => [],
            'failed' => [],
            'total_recipients' => count($recipients),
            'total_recipients_original' => $originalTotal,
            'total_batches' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'current_batch' => 0,
            'failed_emails' => [],
            'details' => [],
            'start_time' => time(),
            'rate_limited' => false,
            'processed_count' => 0  // Track processed in this session
        ];

        if (empty($recipients) || $batchSize < 1) {
            $results['failed_count'] = count($recipients);
            $results['details'][] = ['status' => 'error', 'message' => 'Invalid recipients or batch size'];
            return $results;
        }
        
        // First, retry failed emails from previous attempt
        if (!empty($failedEmailsForRetry)) {
            $this->printer->note(__(''));
            $this->printer->note(__('========== Retrying Failed Emails =========='));
            
            foreach ($failedEmailsForRetry as $failedRecipient) {
                // Display current sender
                $currentAccount = $this->smtpAccounts[$this->currentAccountIndex] ?? null;
                if ($currentAccount) {
                    $fromDisplay = $currentAccount['from_name'] 
                        ? "{$currentAccount['from_name']} <{$currentAccount['from_email']}>" 
                        : $currentAccount['from_email'];
                    $this->printer->note(__('📤 From: ') . $fromDisplay);
                }
                
                $toDisplay = !empty($failedRecipient['name']) 
                    ? "{$failedRecipient['name']} <{$failedRecipient['email']}>" 
                    : $failedRecipient['email'];
                $this->printer->note(__('📧 To: ') . $toDisplay . __(' (Retry attempt ') . ($failedRecipient['retry_attempt'] ?? 0) + 1 . ')');
                
                try {
                    // Send individual email
                    $sent = $this->sendSingleEmail(
                        $failedRecipient,
                        $subject,
                        $body,
                        $isHtml,
                        $personalize,
                        $cc,
                        $attachment,
                        $replaceVariables,
                        0,
                        count($recipients)
                    );
                    
                    if ($sent) {
                        $results['sent_count']++;
                        $results['success'][] = $failedRecipient['email'];
                        $this->breakpointManager->clearFailedEmail($failedRecipient['email']);
                        
                        // Record sent email to prevent duplicate sending
                        $this->breakpointManager->addSentEmail(
                            $failedRecipient['email'],
                            array_merge($failedRecipient, ['subject' => $subject])
                        );
                        
                        $this->printer->success(__('  ✓ Success'));
                    } else {
                        $results['failed_count']++;
                        $results['failed_emails'][] = $failedRecipient['email'];
                        $this->breakpointManager->addFailedEmail(
                            $failedRecipient['email'],
                            'Retry failed',
                            $failedRecipient
                        );
                        $this->printer->error(__('  ✗ Failed'));
                    }
                } catch (\Exception $e) {
                    $results['failed_count']++;
                    $results['failed_emails'][] = $failedRecipient['email'];
                    $this->breakpointManager->addFailedEmail(
                        $failedRecipient['email'],
                        $e->getMessage(),
                        $failedRecipient
                    );
                    $this->printer->error(__('  ✗ Error: ') . $e->getMessage());
                }
                
                // Small delay between retries (0.5 second)
                usleep(500000);
            }
            
            $this->printer->note(__(''));
        }

        // Add subject to each recipient for personalization
        if ($personalize) {
            foreach ($recipients as &$recipient) {
                if (!isset($recipient['subject'])) {
                    $recipient['subject'] = $subject;
                }
            }
        }
        
        // Remove Mailchimp conditional comments (these won't work in our system)
        $body = preg_replace('/<!--\*\|IF:.*?\|\*-->/', '', $body);
        $body = preg_replace('/<!--\*\|END:IF\|\*-->/', '', $body);
        $body = preg_replace('/<!--\*\|ELSE:\|\*-->/', '', $body);
        
        // Replace *|MC_PREVIEW_TEXT|* variable in template for compatibility
        // Actual preview text injection will be handled by SmtpMailer unified method
        if (!empty($previewText)) {
            $body = str_replace('*|MC_PREVIEW_TEXT|*', $previewText, $body);
            $this->printer->note(__('✓ Preview text will be injected: ') . substr($previewText, 0, 50) . (strlen($previewText) > 50 ? '...' : ''));
        } else {
            $body = str_replace('*|MC_PREVIEW_TEXT|*', '', $body);
        }
        
        // ALWAYS replace common variables (subject, etc.)
        $subject = str_replace('*|MC:SUBJECT|*', $subject, $subject);
        $body = str_replace('*|MC:SUBJECT|*', $subject, $body);
        
        // Clean up Mailchimp-style variables that we don't use
        // Note: We now use individual sending (loop), so {{name}} and {{email}} will be replaced per recipient
        $mailchimpVariables = [
            '*|FNAME|*',
            '*|LNAME|*',
            '*|MC:FNAME|*',
            '*|MC:LNAME|*',
            '*|MC:EMAIL|*',
            '*|MC:FNAME_LNAME|*',
            '*|MC:FULL_NAME|*',
            '*|EMAIL|*',
        ];
        
        foreach ($mailchimpVariables as $var) {
            $subject = str_replace($var, '', $subject);
            $body = str_replace($var, '', $body);
        }
        
        // Remove any remaining *|MC:VARIABLE|* patterns (catch-all)
        $body = preg_replace('/\*\|MC:[A-Z_]+\|\*/', '', $body);
        
        $this->printer->note(__('📝 Individual sending mode - each recipient sees personalized content'));
        $this->printer->note(__('✓ Variables {{name}}, {{email}} will be replaced for each recipient'));

        // Split recipients into batches
        $batches = array_chunk($recipients, $batchSize);
        $totalBatches = count($batches);
        $results['total_batches'] = $totalBatches;

        // Rate limiting tracking
        $batchStartTime = time();
        $emailsThisMinute = 0;
        $emailsThisHour = 0;

        $this->printer->note(__(''));
        $this->printer->note(__('========== Rate Limiting Configuration =========='));
        
        // Convert delay to human readable format (show range)
        $minSeconds = round($minDelayMs / 1000);
        $maxSeconds = round($maxDelayMs / 1000);
        
        // Format time display
        $minTime = $minSeconds < 60 ? $minSeconds . 's' : round($minSeconds / 60, 1) . 'min';
        $maxTime = $maxSeconds < 60 ? $maxSeconds . 's' : round($maxSeconds / 60, 1) . 'min';
        
        $this->printer->note(__('Random delay between batches: ') . $minTime . '-' . $maxTime);
        $this->printer->note(__('💡 Natural sending pattern (30s-3min) to avoid spam filters'));
        
        if ($maxPerMinute) {
            $this->printer->note(__('Max emails per minute: ') . $maxPerMinute);
        }
        if ($maxPerHour) {
            $this->printer->note(__('Max emails per hour: ') . $maxPerHour . ' (across all accounts)');
        }
        $this->printer->note(__('Max emails per account (session): ') . $this->maxEmailsPerAccount);
        $this->printer->note(__('Total accounts available: ') . count($this->smtpAccounts));
        $this->printer->note(__(''));

        // Track the current processing position for accurate numbering
        // This counter increments for each email attempted (sent or skipped)
        $initialSentCount = $this->breakpointManager->getSentEmailsCount();
        $processingPosition = $initialSentCount;
        
        // attemptPosition tracks which email we're attempting to process
        // This increments even when emails are skipped, ensuring unique batch numbers
        $attemptPosition = $initialSentCount;

        foreach ($batches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            $results['current_batch'] = $batchNumber;
            
            // CRITICAL: Check if we've reached the limit (--limit parameter)
            // Stop sending when actual sent count reaches originalTotal
            $currentSentCount = $this->breakpointManager->getSentEmailsCount();
            if ($currentSentCount >= $originalTotal) {
                $this->printer->note(__(''));
                $this->printer->success(__('✓ Limit reached: ') . $originalTotal . __(' emails sent'));
                $this->printer->note(__('💡 Use --limit parameter to send more, or remove it to send all'));
                break;  // Stop processing more batches
            }
            
            // CRITICAL FIX: Batch number based on attempt position (not actual sent)
            // This ensures each batch gets a unique sequential number
            // attemptPosition will be incremented for each email in the batch (sent or skipped)
            $globalBatchNumber = (int)floor($attemptPosition / $batchSize) + 1;

            // Rate limiting check: emails per minute
            if ($maxPerMinute) {
                $currentTime = time();
                if ($currentTime - $batchStartTime >= 60) {
                    $batchStartTime = $currentTime;
                    $emailsThisMinute = 0;
                }
                
                if ($emailsThisMinute + count($batch) > $maxPerMinute) {
                    $waitSeconds = 60 - ($currentTime - $batchStartTime);
                    $this->printer->warning(__('Rate limit: max ') . $maxPerMinute . __(' emails/min. Waiting ') . $waitSeconds . __(' seconds...'));
                    sleep($waitSeconds);
                    $batchStartTime = time();
                    $emailsThisMinute = 0;
                    $results['rate_limited'] = true;
                }
            }

            // Rate limiting check: emails per hour
            if ($maxPerHour) {
                $currentTime = time();
                
                // If we've been running for more than an hour, reset the counter
                // This allows continuous sending across multiple hours
                if ($currentTime - $results['start_time'] >= 3600) {
                    $this->printer->note(__('⏰ Hourly window reset - continuing to send...'));
                    $results['start_time'] = time();
                    $emailsThisHour = 0;
                }
                
                // Check if sending this batch would exceed hourly limit
                if ($emailsThisHour + count($batch) > $maxPerHour) {
                    $waitSeconds = 3600 - ($currentTime - $results['start_time']);
                    if ($waitSeconds > 0) {
                        $this->printer->warning(__('Rate limit: max ') . $maxPerHour . __(' emails/hour. Waiting ') . $waitSeconds . __(' seconds...'));
                        sleep($waitSeconds);
                    }
                    $results['start_time'] = time();
                    $emailsThisHour = 0;
                    $results['rate_limited'] = true;
                }
            }

            $this->printer->note(__(''));
            // FIX: Display global batch number to match progress counter
            // Calculate total batches based on original total (accounting for batch size)
            $totalGlobalBatches = (int)ceil($originalTotal / $batchSize);
            $this->printer->note(__('--- Batch ') . $globalBatchNumber . '/' . $totalGlobalBatches . ' ---');
            $this->printer->note(__('Processing: ') . count($batch) . __(' email(s)'));
            
            // Display current sender
            $currentAccount = $this->smtpAccounts[$this->currentAccountIndex] ?? null;
            if ($currentAccount) {
                $fromDisplay = $currentAccount['from_name'] 
                    ? "{$currentAccount['from_name']} <{$currentAccount['from_email']}>" 
                    : $currentAccount['from_email'];
                $this->printer->note(__('📤 From: ') . $fromDisplay);
            }

            // Check if batch contains already sent emails (skip but count toward progress)
            $batchToSend = [];
            $batchSkipped = 0;
            
            foreach ($batch as $idx => $recipient) {
                // Check if already sent first
                $isAlreadySent = !$ignoreSentHistory && $this->breakpointManager->isEmailSent($recipient['email']);
                
                $name = !empty($recipient['name']) ? $recipient['name'] : '';
                $display = $name ? "{$name} <{$recipient['email']}>" : $recipient['email'];
                
                if ($isAlreadySent) {
                    // Skipped email - don't show number since it was sent in a previous run
                    $this->printer->note(__('  ⏭️  Skipped (already sent): ') . $display);
                    $batchSkipped++;
                    $results['processed_count']++;  // Count toward progress
                } else {
                    // Email to be sent - show current processing position
                    $processingPosition++;
                    $this->printer->note(__('  [') . $processingPosition . '/' . $originalTotal . '] 📧 To: ' . $display);
                    $batchToSend[] = $recipient;
                }
            }
            
            // If all emails in batch were already sent, skip the batch
            if (empty($batchToSend)) {
                $this->printer->note(__('⏭️  All emails in this batch already sent, skipping...'));
                continue;
            }

            try {
                // Call the actual sending method with unsent emails only
                $batchResult = $this->mailer->sendBatchGroupMail(
                    $batchToSend,
                    count($batchToSend),  // Use actual count of emails to send
                    $subject,
                    $body,
                    $isHtml,
                    $personalize,
                    $cc,
                    $attachment,
                    $replaceVariables,
                    $previewText  // Pass preview text to unified method
                );

                // Collect results
                if (!empty($batchResult['success'])) {
                    $results['sent_count'] += $batchResult['success'];
                    $emailsThisMinute += $batchResult['success'];
                    $emailsThisHour += $batchResult['success'];
                    
                    // CRITICAL FIX: Only mark emails as sent if they actually succeeded
                    // Use the succeeded_emails list from SmtpMailer to track individual successes
                    $succeededEmails = $batchResult['succeeded_emails'] ?? [];
                    
                    if (!empty($succeededEmails)) {
                        foreach ($succeededEmails as $succeededEmail) {
                            // Find the recipient data for this email
                            $recipientData = null;
                            foreach ($batchToSend as $recipient) {
                                if ($recipient['email'] === $succeededEmail) {
                                    $recipientData = $recipient;
                                    break;
                                }
                            }
                            
                            if ($recipientData) {
                                $results['success'][] = $recipientData['email'];
                                
                                // Record sent email to prevent duplicate sending
                                $this->breakpointManager->addSentEmail(
                                    $recipientData['email'],
                                    array_merge($recipientData, ['subject' => $subject])
                                );
                                $results['processed_count']++;  // Count toward progress
                            }
                        }
                    }
                    
                    // Display appropriate success message
                    $batchFullySucceeded = ($batchResult['success'] === count($batchToSend));
                    if ($batchFullySucceeded) {
                        $this->printer->success(__('✓ Batch sent successfully'));
                    } else {
                        $this->printer->warning(__('⚠️  Partial success: ') . $batchResult['success'] . '/' . count($batchToSend) . __(' emails sent'));
                        $this->printer->note(__('💡 Failed emails will be retried automatically'));
                    }
                    
                    // Reset rate limit error counter on success
                    $this->consecutiveRateLimitErrors = 0;
                    
                    // Switch to next account after each batch for load balancing
                    if (count($this->smtpAccounts) > 1) {
                        $this->switchToNextAccount();
                    }
                }

                if (!empty($batchResult['failed'])) {
                    $results['failed_count'] += $batchResult['failed'];
                    $failureReason = $batchResult['details'][0]['message'] ?? 'Unknown error';
                    
                    foreach ($batchToSend as $recipient) {
                        $results['failed_emails'][] = $recipient['email'];
                        $results['failed'][] = $recipient['email'];
                        
                        // Track failed email in breakpoint manager
                        $this->breakpointManager->addFailedEmail(
                            $recipient['email'],
                            $failureReason,
                            $recipient
                        );
                        $results['processed_count']++;  // Count toward progress even if failed
                    }
                    $this->printer->error(__('✗ Batch failed: ') . $failureReason);
                    
                    // Check if it's a rate limit error
                    $isRateLimitError = stripos($failureReason, 'Please try again later') !== false || 
                                       stripos($failureReason, 'try again later') !== false ||
                                       stripos($failureReason, 'rate limit') !== false;
                    
                    if ($isRateLimitError) {
                        $this->consecutiveRateLimitErrors++;
                        
                        // If we've tried multiple accounts and still rate limited, wait and retry with same account
                        if ($this->consecutiveRateLimitErrors >= self::MAX_ACCOUNT_SWITCHES_BEFORE_WAIT) {
                            $this->printer->warning(__('⚠️  Rate limit error on ') . $this->consecutiveRateLimitErrors . __(' consecutive account(s)'));
                            $this->printer->warning(__('💡 All accounts appear to be rate limited, will wait and retry'));
                            $this->handleRateLimitError($batchToSend, $subject, $body, $isHtml);
                            // Reset counter after handling
                            $this->consecutiveRateLimitErrors = 0;
                        } else {
                            // Try switching to next account
                            $this->printer->note(__('🔄 Rate limit error (') . $this->consecutiveRateLimitErrors . '/' . self::MAX_ACCOUNT_SWITCHES_BEFORE_WAIT . __('), trying next account...'));
                            $this->switchAccountOnError($subject, $body, $isHtml, $failureReason);
                        }
                    } else {
                        // Other errors - reset rate limit counter and switch account
                        $this->consecutiveRateLimitErrors = 0;
                        $this->switchAccountOnError($subject, $body, $isHtml, $failureReason);
                    }
                }

                $results['details'][] = $batchResult['details'][0] ?? [];
                
                // No breakpoint saved - progress tracked via sent_emails_*.json

            } catch (\Exception $e) {
                $results['failed_count'] += count($batchToSend);
                $errorMessage = $e->getMessage();
                
                foreach ($batchToSend as $recipient) {
                    $results['failed_emails'][] = $recipient['email'];
                    $results['failed'][] = $recipient['email'];
                    
                    // Track failed email in breakpoint manager
                    $this->breakpointManager->addFailedEmail(
                        $recipient['email'],
                        'Exception: ' . $errorMessage,
                        $recipient
                    );
                    $results['processed_count']++;  // Count toward progress even if exception
                }
                
                $this->printer->error(__('✗ Batch error: ') . $errorMessage);
                $results['details'][] = [
                    'status' => 'error',
                    'batch' => $batchNumber,
                    'message' => 'Batch error: ' . $errorMessage
                ];
                
                // No breakpoint saved - progress tracked via sent_emails_*.json
            }

            // Show progress (using actual sent emails count)
            $totalSentEmails = $this->breakpointManager->getSentEmailsCount();
            $progressPercent = $originalTotal > 0 ? ($totalSentEmails / $originalTotal) * 100 : 0;
            $this->printer->note(__('Progress: ') . $totalSentEmails . '/' . $originalTotal . __(' emails sent (') . round($progressPercent, 2) . '%)');
            
            // Increment attempt position for all emails in this batch (sent or skipped)
            // This ensures next batch gets a unique sequential number
            $attemptPosition += count($batch);

            // Delay between batches (RFC 5321 compliance - avoid overwhelming SMTP server)
            if ($batchNumber < $totalBatches) {
                // Generate random delay between min and max for natural sending pattern
                $actualDelayMs = rand($minDelayMs, $maxDelayMs);
                
                // Display delay in human readable format
                if ($actualDelayMs >= 60000) {
                    $delayMinutes = round($actualDelayMs / 60000, 1);
                    $this->printer->note(__('⏳ Waiting ') . $delayMinutes . __(' minute(s) before next batch...'));
                } else if ($actualDelayMs >= 1000) {
                    $delaySeconds = round($actualDelayMs / 1000, 1);
                    $this->printer->note(__('⏳ Waiting ') . $delaySeconds . __(' second(s) before next batch...'));
                } else {
                    $this->printer->note(__('⏳ Waiting ') . $actualDelayMs . __('ms before next batch...'));
                }
                usleep($actualDelayMs * 1000); // Convert milliseconds to microseconds
            }
        }

        $results['end_time'] = time();
        return $results;
    }

    /**
     * Display current SMTP account information
     */
    private function displayCurrentAccount(): void
    {
        try {
            if (empty($this->smtpAccounts)) {
                return;
            }
            
            $this->printer->note(__(''));
            $this->printer->note(__('========== SMTP Accounts Info =========='));
            $this->printer->note(__('Total Accounts: ') . count($this->smtpAccounts));
            $this->printer->note(__('Rotation Mode: Round-Robin (load balancing)'));
            $this->printer->note(__(''));
            
            foreach ($this->smtpAccounts as $index => $account) {
                $sentCount = $this->accountSendCounts[$index] ?? 0;
                $this->printer->note(__('Account #') . ($index + 1) . ': ' . ($account['name'] ?? 'N/A'));
                $this->printer->note(__('  From: ') . ($account['from_name'] ?? 'N/A') . ' <' . $account['from_email'] . '>');
                $this->printer->note(__('  Usage: ') . $sentCount . '/' . $this->maxEmailsPerAccount . ' (Session Limit)');
                $this->printer->note(__('  Daily Limit: ') . ($account['daily_limit'] ?? 'Unlimited'));
            }
            
            $this->printer->note(__(''));
        } catch (\Exception $e) {
            $this->printer->warning(__('Could not display account info: ') . $e->getMessage());
        }
    }

    /**
     * Display detailed sending results with statistics
     */
    private function displayDetailedResults(array $result): void
    {
        $this->printer->note(__(''));
        $this->printer->note(__('========== Sending Statistics =========='));
        
        $totalRecipients = $result['total_recipients'];
        $sentCount = $result['sent_count'] ?? 0;
        $failedCount = $result['failed_count'] ?? 0;
        $successRate = $totalRecipients > 0 ? ($sentCount / $totalRecipients) * 100 : 0;

        $this->printer->note(__('Total Recipients: ') . $totalRecipients);
        $this->printer->note(__('Total Batches: ') . ($result['total_batches'] ?? 0));
        $this->printer->note(__('Successfully Sent: ') . $sentCount);
        $this->printer->note(__('Failed: ') . $failedCount);
        $this->printer->note(__('Success Rate: ') . round($successRate, 2) . '%');

        // Display timing and rate limiting info
        if (isset($result['start_time']) && isset($result['end_time'])) {
            $totalTime = $result['end_time'] - $result['start_time'];
            $this->printer->note(__('Total Time: ') . $totalTime . __(' seconds'));
            
            if ($sentCount > 0) {
                $emailsPerSecond = $sentCount / max(1, $totalTime);
                $this->printer->note(__('Average Speed: ') . round($emailsPerSecond, 2) . __(' emails/second'));
            }
        }

        if ($result['rate_limited'] ?? false) {
            $this->printer->warning(__(''));
            $this->printer->warning(__('⚠ Rate limiting was applied during sending'));
        }

        if ($sentCount > 0) {
            $this->printer->success(__(''));
            $this->printer->success(__('✓ ' . $sentCount . ' email(s) sent successfully!'));
        }

        if ($failedCount > 0) {
            $this->printer->error(__(''));
            $this->printer->error(__('✗ ' . $failedCount . ' email(s) failed to send'));
            $this->printer->error(__(''));
            $this->printer->error(__('Failed Email Addresses:'));
            foreach ($result['failed_emails'] ?? [] as $email) {
                $this->printer->error(__('  • ') . $email);
            }

            // Generate retry command
            $failedEmails = implode(',', $result['failed_emails'] ?? []);
            if (!empty($failedEmails)) {
                $this->printer->note(__(''));
                $this->printer->note(__('Retry Command:'));
                $this->printer->note(__('php bin/w mail:send --to="' . $failedEmails . '" --subject="[RETRY] Your Subject" --body="Your body text"'));
            }
        }

        $this->printer->note(__(''));
        $this->printer->note(__('========== End of Report =========='));
    }

    /**
     * Load email templates from templates directory
     */
    private function loadTemplatesFromDirectory(): array
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
     * Parse recipients from various formats
     */
    private function parseRecipients(string $recipientString): array
    {
        $recipients = [];

        // Check if it's a file path (try absolute, relative, and relative to baseDir)
        $filePaths = [
            $recipientString,  // Try as-is
            getcwd() . DIRECTORY_SEPARATOR . $recipientString,  // Relative to cwd
            $this->baseDir . DIRECTORY_SEPARATOR . $recipientString,  // Relative to baseDir
            $this->baseDir . DIRECTORY_SEPARATOR . 'emails' . DIRECTORY_SEPARATOR . $recipientString,  // Relative to emails subdirectory
            dirname($this->baseDir) . DIRECTORY_SEPARATOR . $recipientString,  // Relative to parent
        ];

        foreach ($filePaths as $filePath) {
            if (file_exists($filePath)) {
                return $this->loadRecipientsFromFile($filePath);
            }
        }

        // Parse as comma-separated list
        $parts = explode(',', $recipientString);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Parse "Name <email@example.com>" format
            if (preg_match('/^(.+?)\s*<(.+?)>$/', $part, $matches)) {
                $recipients[] = [
                    'email' => trim($matches[2]),
                    'name' => trim($matches[1])
                ];
            } else {
                // Just email address
                $recipients[] = [
                    'email' => $part,
                    'name' => ''
                ];
            }
        }

        return $recipients;
    }

    /**
     * Load recipients from file (TXT, CSV, or XLSX)
     */
    private function loadRecipientsFromFile(string $filepath): array
    {
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'txt':
                return $this->loadFromTxt($filepath);
            case 'csv':
                return $this->loadFromCsv($filepath);
            case 'xlsx':
            case 'xls':
                return $this->loadFromExcel($filepath);
            default:
                $this->printer->error(__('Unsupported file format: ') . $extension);
                return [];
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
            $this->printer->error(__('Unable to open CSV file'));
            return [];
        }

        // Read headers
        $headers = fgetcsv($file);
        
        while (($row = fgetcsv($file)) !== false) {
            $data = array_combine($headers, $row);
            
            // Skip inactive entries
            if (isset($data['status']) && $data['status'] === 'inactive') {
                continue;
            }

            $email = $data['email'] ?? '';
            if (!empty($email)) {
                $recipients[] = [
                    'email' => $email,
                    'name' => $data['name'] ?? ''
                ];
            }
        }

        fclose($file);
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
            
            $highestRow = $worksheet->getHighestRow();
            
            // Find column indices
            $emailColumn = null;
            $nameColumn = null;
            $firstNameColumn = null;
            $lastNameColumn = null;
            
            foreach ($worksheet->getRowIterator(1, 1) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                
                $colIndex = 0;
                foreach ($cellIterator as $cell) {
                    $value = strtolower(trim($cell->getValue() ?? ''));
                    
                    if (in_array($value, ['email', 'e-mail', 'emailaddress', 'email address', '邮箱', '电子邮件'])) {
                        $emailColumn = $colIndex;
                    } elseif (in_array($value, ['name', 'fullname', 'full name', 'username', '姓名', '名字'])) {
                        $nameColumn = $colIndex;
                    } elseif (in_array($value, ['first name', 'firstname', '名', 'first_name'])) {
                        $firstNameColumn = $colIndex;
                    } elseif (in_array($value, ['last name', 'lastname', '姓', 'last_name'])) {
                        $lastNameColumn = $colIndex;
                    }
                    
                    $colIndex++;
                }
            }
            
            if ($emailColumn === null) {
                $emailColumn = 0;
            }
            
            // Read data rows
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = $worksheet->rangeToArray('A' . $row . ':' . $worksheet->getHighestColumn() . $row, null, true, false)[0];
                
                $email = isset($rowData[$emailColumn]) ? trim($rowData[$emailColumn]) : '';
                
                if (empty($email)) {
                    continue;
                }
                
                $name = '';
                
                // Try to get full name from single "name" column first
                if ($nameColumn !== null && isset($rowData[$nameColumn])) {
                    $name = trim($rowData[$nameColumn]);
                }
                
                // If no full name, combine first and last names
                if (empty($name)) {
                    $firstName = '';
                    $lastName = '';
                    
                    if ($firstNameColumn !== null && isset($rowData[$firstNameColumn])) {
                        $firstName = trim($rowData[$firstNameColumn]);
                    }
                    
                    if ($lastNameColumn !== null && isset($rowData[$lastNameColumn])) {
                        $lastName = trim($rowData[$lastNameColumn]);
                    }
                    
                    $name = trim($firstName . ' ' . $lastName);
                }
                
                $recipients[] = [
                    'email' => $email,
                    'name' => $name
                ];
            }
            
        } catch (\Exception $e) {
            $this->printer->error(__('Failed to read Excel file: ') . $e->getMessage());
        }
        
        return $recipients;
    }

    /**
     * Send single email (helper for retry and individual sends)
     * 
     * @return bool Success status
     */
    private function sendSingleEmail(
        array $recipient,
        string $subject,
        string $body,
        bool $isHtml,
        bool $personalize,
        ?string $cc,
        ?string $attachment,
        bool $replaceVariables,
        int $index,
        int $total
    ): bool {
        // Clear mailer state
        $this->mailer->clearAddresses();
        $this->mailer->clearAttachments();
        $this->mailer->clearCCs();
        $this->mailer->clearBCCs();
        
        // Prepare content
        $emailSubject = $subject;
        $emailBody = $body;
        
        if ($personalize || $replaceVariables) {
            $emailSubject = $this->mailer->replaceTemplateVariables(
                $subject,
                $recipient,
                $index,
                $total
            );
            $emailBody = $this->mailer->replaceTemplateVariables(
                $body,
                $recipient,
                $index,
                $total
            );
        }
        
        // Set recipient
        $this->mailer->addAddress($recipient['email'], $recipient['name'] ?? '');
        
        // Add CC if provided
        if ($cc) {
            $ccList = explode(',', $cc);
            foreach ($ccList as $ccEmail) {
                $ccEmail = trim($ccEmail);
                if ($ccEmail) {
                    $this->mailer->addCC($ccEmail);
                }
            }
        }
        
        // Add attachment if provided
        if ($attachment && file_exists($attachment)) {
            $this->mailer->addAttachment($attachment);
        }
        
        // Set content
        $this->mailer->isHTML($isHtml);
        $this->mailer->Subject = $emailSubject;
        $this->mailer->Body = $emailBody;
        
        if ($isHtml) {
            $this->mailer->AltBody = strip_tags($emailBody);
        }
        
        // Send
        return $this->mailer->send();
    }
    
    /**
     * Display bulk sending results
     */
    private function displayResults(array $result): void
    {
        $this->printer->note(__(''));
        $this->printer->note(__('========== Group Email Results =========='));
        $this->printer->note(__('Total Recipients: ') . $result['recipients']);
        
        if (isset($result['batches'])) {
            $this->printer->note(__('Total Batches: ') . $result['batches']);
        }
        
        if ($result['success'] > 0) {
            $this->printer->success(__('Status: SUCCESS'));
            $this->printer->note(__('Recipients Reached: ') . $result['success']);
        } else {
            $this->printer->error(__('Status: FAILED'));
        }

        if ($result['failed'] > 0) {
            $this->printer->error(__('Failed: ') . $result['failed']);
        }

        foreach ($result['details'] as $detail) {
            if ($detail['status'] === 'success') {
                $this->printer->success(__('✓ ') . $detail['message']);
            } else {
                $this->printer->error(__('✗ ') . $detail['message']);
            }
        }
        $this->printer->note(__(''));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'Send professional business emails via SMTP. Features: AUTO resume via sent_emails tracking, smart deduplication (skips already sent emails), variable replacement (enabled by default). Safe rate limits: random 30s-3min delay between batches, 1000/hour (RFC 5321 compliant). Modes: --bulk (BCC group sending), --personalize (individual sends with custom content per recipient). Required: --to, --subject. Options: --limit (stop at N emails), --fresh (resend all). Override limits: --delay, --max-per-minute, --max-per-hour.';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'mail:send',
            $this->tip(),
            [
                '-h, --help' => 'Display help information',
                '--to' => 'Recipient address(es) (required). Can be: single email, comma-separated emails, or file path (TXT/CSV/XLSX)',
                '--subject' => 'Email subject line (required)',
                '--body' => 'Email message body (optional). If not provided with --file, auto-loads random template. Supports template variables with --personalize',
                '--file' => 'Load email body from file (optional, alternative to --body)',
                '--limit' => 'Max number of recipients to process when --to is a file (optional). E.g., --limit=100 sends only first 100',
                '--html' => 'Send as HTML format (default: ENABLED). Set --html=0 to send as plain text',
                '--cc' => 'CC addresses (optional, comma-separated)',
                '--bcc' => 'BCC addresses (optional, comma-separated, single recipient only)',
                '--attachment' => 'File path for attachment (optional)',
                '--bulk' => 'Enable batch group sending. Value is batch size (number of recipients per SMTP send). E.g., --bulk=10 sends 10 recipients per connection',
                '--personalize' => 'Personalization mode (default: DISABLED). When enabled, sends INDIVIDUAL emails to each recipient with custom content. Use with --bulk for personalized mass mailing. Supports: Custom {{name}}, {{email}}, {{index}}, {{total}} OR Mailchimp *|MC:SUBJECT|*, *|MC:FNAME|*, *|MC:LNAME|*, *|MC:EMAIL|*, *|MC:FNAME_LNAME|*',
                '--replace-variables' => 'Template variable replacement (default: ENABLED). In BCC group mode (--bulk without --personalize), replaces variables for primary recipient. All BCC recipients see same content. Set --replace-variables=0 to disable. E.g., "Hi *|MC:FNAME|*" → "Hi John"',
                '--preview' => 'Email preview text for email clients (REQUIRED for bulk sending). Replaces *|MC_PREVIEW_TEXT|* in templates. E.g., --preview="Check out our latest offers"',
                '--fresh' => 'Fresh start mode (default: DISABLED). When enabled with --fresh=1, ignores sent email history and sends to all recipients including previously sent. Use to force resend',
                '--delay' => 'Maximum delay between batches in milliseconds (default: 180000ms = 3 minutes). Random delay 30s-3min prevents spam filters. E.g., --delay=60000 for faster sending',
                '--max-per-hour' => 'Maximum emails per hour (default: 100 for safety). Prevents exceeding ISP limits and spam filters. E.g., --max-per-hour=200',
                '--max-per-minute' => 'Maximum emails per minute (default: 20 for safety). Fine-grained rate control to avoid throttling. E.g., --max-per-minute=30',
                '--debug' => 'Debug mode - show email content without sending. Displays processed HTML, preview text locations, and saves to debug file',
            ],
            [
                '# Single recipient',
                'php bin/w mail:send --to=client@example.com --subject="Business Proposal" --body="Please review the attached proposal"',
                '',
                '# HTML email',
                'php bin/w mail:send --to=team@example.com --subject="Newsletter" --body="<h1>Welcome</h1><p>Latest updates...</p>" --html=1',
                '',
                '# Group send (BCC)',
                'php bin/w mail:send --to="user1@example.com,user2@example.com" --subject="Group Announcement" --bulk=1',
                '',
                '# Bulk send from Excel file',
                'php bin/w mail:send --to="recipients.xlsx" --subject="Newsletter" --bulk=10 --delay=1000',
                '',
                '# Bulk send with preview text',
                'php bin/w mail:send --to="recipients.xlsx" --subject="Newsletter" --bulk=10 --preview="Don\'t miss our special offers this month!"',
                '',
                '# Limited bulk send with rate limiting',
                'php bin/w mail:send --to="recipients.xlsx" --subject="Newsletter" --bulk=10 --limit=50 --delay=1000 --max-per-minute=5',
                '',
                '# Personalized bulk send',
                'php bin/w mail:send --to="recipients.xlsx" --subject="Personalized Message" --file="template.html" --bulk=5 --personalize=1 --html=1 --delay=2000 --max-per-hour=100',
                '',
                '# Auto-skip already sent emails (default behavior)',
                'php bin/w mail:send --to="recipients.xlsx" --subject="Newsletter" --bulk=10',
                '',
                '# Force resend all (ignore sent history)',
                'php bin/w mail:send --to="recipients.xlsx" --subject="Newsletter" --bulk=10 --fresh=1',
                '',
                '# Debug mode - preview email content without sending',
                'php bin/w mail:send --to="test@example.com" --subject="Test Email" --preview="This is preview text" --debug',
            ],
            []
        );
    }

    /**
     * Initialize SMTP mailer with configuration from smtps.json
     * Loads all enabled accounts for rotation
     */
    private function initializeSmtpMailer(): void
    {
        try {
            $config = $this->loadSmtpConfig();
            
            if (!empty($config['accounts'])) {
                // Load all enabled accounts
                foreach ($config['accounts'] as $account) {
                    if ($account['enabled'] ?? true) {
                        $this->smtpAccounts[] = [
                            'host' => $account['host'] ?? 'mail.privateemail.com',
                            'port' => $account['port'] ?? 587,
                            'username' => $account['username'] ?? '',
                            'password' => $account['password'] ?? '',
                            'from_email' => $account['from_email'] ?? $account['username'] ?? '',
                            'from_name' => $account['from_name'] ?? 'GuoLaiRen',
                            'encryption' => $account['encryption'] ?? 'tls',
                            'name' => $account['name'] ?? 'N/A',
                            'daily_limit' => $account['daily_limit'] ?? 500,
                        ];
                    }
                }
                
                // Initialize with first account
                if (!empty($this->smtpAccounts)) {
                    $this->mailer = new SmtpMailer($this->smtpAccounts[0]);
                    $this->printer->note(__('✓ Loaded ' . count($this->smtpAccounts) . ' SMTP account(s) for rotation'));
                }
            }
        } catch (\Exception $e) {
            $this->printer->warning(__('Could not load SMTP config from smtps.json: ') . $e->getMessage());
        }
    }
    
    /**
     * Get next SMTP account for rotation (round-robin)
     * Automatically skips accounts that have reached their limit
     */
    private function getNextAccount(): ?array
    {
        if (empty($this->smtpAccounts)) {
            return null;
        }
        
        $maxAttempts = count($this->smtpAccounts);
        $attempts = 0;
        
        // Try to find an account that hasn't reached its limit
        while ($attempts < $maxAttempts) {
            $account = $this->smtpAccounts[$this->currentAccountIndex];
            $currentCount = $this->accountSendCounts[$this->currentAccountIndex] ?? 0;
            
            // Check if this account has reached its limit
            if ($currentCount < $this->maxEmailsPerAccount) {
                return $account;
            }
            
            // This account is at limit, try next one
            $this->currentAccountIndex = ($this->currentAccountIndex + 1) % count($this->smtpAccounts);
            $attempts++;
        }
        
        // All accounts at limit - reset counts and use first account
        $this->printer->warning(__('⚠️  All accounts have reached their limits, resetting counters...'));
        $this->accountSendCounts = [];
        $this->currentAccountIndex = 0;
        
        return $this->smtpAccounts[0];
    }
    
    /**
     * Increment send count for current account
     */
    private function incrementAccountSendCount(): void
    {
        if (!isset($this->accountSendCounts[$this->currentAccountIndex])) {
            $this->accountSendCounts[$this->currentAccountIndex] = 0;
        }
        $this->accountSendCounts[$this->currentAccountIndex]++;
    }
    
    /**
     * Switch to next SMTP account
     */
    private function switchToNextAccount(bool $incrementCount = true): void
    {
        // Increment current account send count
        if ($incrementCount) {
            $this->incrementAccountSendCount();
        }
        
        // Move to next account
        $this->currentAccountIndex = ($this->currentAccountIndex + 1) % count($this->smtpAccounts);
        
        $account = $this->getNextAccount();
        if ($account) {
            $isDebugMode = $this->mailer->isDebugMode();
            
            $this->mailer = new SmtpMailer($account);
            
            // Preserve debug mode and callback
            if ($isDebugMode) {
                $this->mailer->setDebugMode(true);
            }
            if ($this->savedCallback) {
                $this->mailer->setAccountSwitchCallback($this->savedCallback);
            }
        }
    }
    
    /**
     * Handle rate limit error (wait and retry with same account)
     */
    private function handleRateLimitError(array $failedBatch, string $subject, string $body, bool $isHtml): void
    {
        $waitMinutes = 10;
        $waitSeconds = $waitMinutes * 60;
        
        $currentAccount = $this->smtpAccounts[$this->currentAccountIndex] ?? null;
        if ($currentAccount) {
            $fromDisplay = $currentAccount['from_name'] 
                ? "{$currentAccount['from_name']} <{$currentAccount['from_email']}>" 
                : $currentAccount['from_email'];
            $this->printer->warning(__('⏰ Rate limit detected on: ') . $fromDisplay);
        }
        
        $this->printer->warning(__('⏳ Waiting ') . $waitMinutes . __(' minutes before retry...'));
        $this->printer->note(__('💡 Will retry with same account until successful'));
        
        // Wait 10 minutes
        sleep($waitSeconds);
        
        // Retry the failed batch with same account
        $this->printer->note(__(''));
        $this->printer->note(__('🔄 Retrying after wait period...'));
        
        foreach ($failedBatch as $recipient) {
            $toDisplay = !empty($recipient['name']) 
                ? "{$recipient['name']} <{$recipient['email']}>" 
                : $recipient['email'];
            
            $this->printer->note(__('  📧 Retrying: ') . $toDisplay);
            
            $retrySuccess = false;
            $maxRetries = 100; // Prevent infinite loop
            $retryCount = 0;
            
            while (!$retrySuccess && $retryCount < $maxRetries) {
                try {
                    $sent = $this->sendSingleEmail(
                        $recipient,
                        $subject,
                        $body,
                        $isHtml,
                        false,  // personalize
                        null,   // cc
                        null,   // attachment
                        false,  // replaceVariables
                        0,
                        1
                    );
                    
                    if ($sent) {
                        $this->printer->success(__('    ✓ Success after wait!'));
                        $retrySuccess = true;
                        
                        // Remove from failed list
                        $this->breakpointManager->clearFailedEmail($recipient['email']);
                        
                        // Add to sent list
                        $this->breakpointManager->addSentEmail(
                            $recipient['email'],
                            $recipient['name'] ?? '',
                            $subject
                        );
                    }
                } catch (\Exception $e) {
                    $errorMsg = $e->getMessage();
                    
                    // Check if still rate limited
                    if (stripos($errorMsg, 'Please try again later') !== false || 
                        stripos($errorMsg, 'try again later') !== false ||
                        stripos($errorMsg, 'rate limit') !== false) {
                        $retryCount++;
                        $this->printer->warning(__('    ⏰ Still rate limited, waiting another ') . $waitMinutes . __(' minutes... (attempt ') . $retryCount . ')');
                        sleep($waitSeconds);
                    } else {
                        // Different error, break and let normal error handling take over
                        $this->printer->error(__('    ✗ Error: ') . $errorMsg);
                        break;
                    }
                }
            }
        }
        
        $this->printer->note(__(''));
    }
    
    /**
     * Switch account on error (for retry with different account)
     */
    private function switchAccountOnError(string $subject = '', string $body = '', bool $isHtml = true, string $errorMessage = ''): void
    {
        if (count($this->smtpAccounts) <= 1) {
            return;
        }
        
        $this->printer->note(__('⚠️  Switching to next account due to error...'));
        $this->switchToNextAccount(false); // Don't increment count on error
        
        // Display new account
        $currentAccount = $this->smtpAccounts[$this->currentAccountIndex] ?? null;
        if ($currentAccount) {
            $fromDisplay = $currentAccount['from_name'] 
                ? "{$currentAccount['from_name']} <{$currentAccount['from_email']}>" 
                : $currentAccount['from_email'];
            $this->printer->note(__('🔄 Switched to: ') . $fromDisplay);
        }
        
        // Check for failed emails and retry with new account
        $failedEmails = $this->breakpointManager->getFailedEmailsForRetry();
        
        if (!empty($failedEmails) && !empty($subject)) {
            $this->printer->note(__(''));
            $this->printer->note(__('📧 Found ') . count($failedEmails) . __(' failed email(s), retrying with new account...'));
            $this->printer->note(__('💡 Maximum 3 failures allowed per email'));
            
            foreach ($failedEmails as $failedRecipient) {
                $toDisplay = !empty($failedRecipient['name']) 
                    ? "{$failedRecipient['name']} <{$failedRecipient['email']}>" 
                    : $failedRecipient['email'];
                
                $attemptNum = ($failedRecipient['retry_attempt'] ?? 0) + 1;
                $this->printer->note(__('  📧 Retrying: ') . $toDisplay . __(' (attempt ') . $attemptNum . '/3)');
                
                try {
                    // Send with new account
                    $sent = $this->sendSingleEmail(
                        $failedRecipient,
                        $subject,
                        $body,
                        $isHtml,
                        false,  // personalize
                        null,   // cc
                        null,   // attachment
                        false,  // replaceVariables
                        0,
                        1
                    );
                    
                    if ($sent) {
                        $this->printer->success(__('    ✓ Success with new account on attempt ') . $attemptNum . '!');
                        
                        // Remove from failed list
                        $this->breakpointManager->clearFailedEmail($failedRecipient['email']);
                        
                        // Add to sent list
                        $this->breakpointManager->addSentEmail(
                            $failedRecipient['email'],
                            array_merge($failedRecipient, ['subject' => $subject])
                        );
                    } else {
                        $nextAttempt = $attemptNum + 1;
                        if ($nextAttempt > 3) {
                            $this->printer->error(__('    ✗ Failed (will be moved to permanent failed list)'));
                        } else {
                            $this->printer->error(__('    ✗ Still failed, will retry again (next attempt: ') . $nextAttempt . '/3)');
                        }
                        
                        // Update failed count
                        $this->breakpointManager->addFailedEmail(
                            $failedRecipient['email'],
                            'Failed with account: ' . ($currentAccount['name'] ?? 'Unknown'),
                            $failedRecipient
                        );
                    }
                } catch (\Exception $e) {
                    $this->printer->error(__('    ✗ Error: ') . $e->getMessage());
                    
                    $this->breakpointManager->addFailedEmail(
                        $failedRecipient['email'],
                        'Exception with new account: ' . $e->getMessage(),
                        $failedRecipient
                    );
                }
                
                // Small delay between retries
                usleep(500000); // 0.5 second
            }
            
            $this->printer->note(__(''));
        }
    }

    /**
     * Load SMTP account configuration from smtps.json
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
     * Get file identifier from recipient file path
     */
    private function getFileIdentifier(string $recipientFile): string
    {
        if (empty($recipientFile)) {
            return 'default';
        }
        
        $basename = basename($recipientFile);
        $filename = pathinfo($basename, PATHINFO_FILENAME);
        // Keep original filename but ensure it's safe for filesystem
        $safeFilename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $filename);
        // Limit length to avoid filesystem issues (max 100 chars)
        return mb_substr($safeFilename, 0, 100);
    }
}

