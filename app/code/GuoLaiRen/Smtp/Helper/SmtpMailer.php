<?php
declare(strict_types=1);

/**
 * GuoLaiRen SMTP Module
 * Professional Email Service for Business Communications
 * 
 * @category  GuoLaiRen
 * @package   GuoLaiRen_Smtp
 * @author    GuoLaiRen Development Team
 * @copyright Copyright (c) 2025 GuoLaiRen
 */

namespace GuoLaiRen\Smtp\Helper;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Weline\Framework\App\Env;

/**
 * SMTP Mailer Helper
 * Handles all email delivery operations for business communications
 */
class SmtpMailer
{
    private PHPMailer $mailer;
    private bool $debugMode = false; // Debug mode flag - simulates sending without actually sending
    private $accountSwitchCallback = null; // Callback to switch accounts after each send
    
    // SMTP Configuration for Stock Circle Team Email Service
    private string $host = 'mail.privateemail.com'; // Default SMTP server for Stock Circle
    private int $port = 587; // SMTP port with TLS encryption
    private string $username = ''; // SMTP account username
    private string $password = ''; // SMTP account password
    private string $fromEmail = ''; // Sender email address
    private string $fromName = ''; // Sender display name
    private string $encryption = 'tls'; // Encryption type: tls or ssl

    public function __construct(array $customConfig = [])
    {
        $this->mailer = new PHPMailer(true);
        
        if (!empty($customConfig)) {
            // 使用自定义配置
            $this->host = $customConfig['host'] ?? 'mail.privateemail.com';
            $this->port = (int)($customConfig['port'] ?? 587);
            $this->username = $customConfig['username'] ?? '';
            $this->password = $customConfig['password'] ?? '';
            $this->fromEmail = $customConfig['from_email'] ?? $this->username;
            $this->fromName = $customConfig['from_name'] ?? 'GuoLaiRen';
            $this->encryption = $customConfig['encryption'] ?? 'tls';
        } else {
            // 从配置文件加载
            $this->loadConfig();
        }
        
        $this->setupSmtp();
    }

    /**
     * Load SMTP configuration from environment settings
     * Supports both config file and environment variables
     */
    private function loadConfig(): void
    {
        // Load from application configuration
        $config = Env::getInstance()->getConfig('smtp');
        
        if ($config) {
            $this->host = $config['host'] ?? 'mail.privateemail.com';
            $this->port = (int)($config['port'] ?? 587);
            $this->username = $config['username'] ?? '';
            $this->password = $config['password'] ?? '';
            $this->fromEmail = $config['from_email'] ?? $this->username;
            $this->fromName = $config['from_name'] ?? 'GuoLaiRen';
            $this->encryption = $config['encryption'] ?? 'tls';
        } else {
            // Fallback to environment variables if config not found
            $this->host = getenv('SMTP_HOST') ?: 'mail.privateemail.com';
            $this->port = (int)(getenv('SMTP_PORT') ?: 587);
            $this->username = getenv('SMTP_USERNAME') ?: '';
            $this->password = getenv('SMTP_PASSWORD') ?: '';
            $this->fromEmail = getenv('SMTP_FROM_EMAIL') ?: $this->username;
            $this->fromName = getenv('SMTP_FROM_NAME') ?: 'GuoLaiRen';
            $this->encryption = getenv('SMTP_ENCRYPTION') ?: 'tls';
        }
    }

    /**
     * Configure PHPMailer with SMTP settings
     * Sets up secure connection for reliable email delivery
     */
    private function setupSmtp(): void
    {
        try {
            // Configure SMTP server connection
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->host;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->username;
            $this->mailer->Password = $this->password;
            $this->mailer->SMTPSecure = $this->encryption;
            $this->mailer->Port = $this->port;

            // Set character encoding for international support
            $this->mailer->CharSet = 'UTF-8';
            
            // Set connection timeout (15 seconds)
            $this->mailer->Timeout = 15;
            
            // Set SMTP connection options with timeout
            $this->mailer->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Debug mode (should be disabled in production)
            $this->mailer->SMTPDebug = 0; // 0 = off, 1 = client messages, 2 = client and server messages

            // Set sender information
            if ($this->fromEmail) {
                $this->mailer->setFrom($this->fromEmail, $this->fromName);
            }
        } catch (Exception $e) {
            throw new \Exception("SMTP setup failed: {$e->getMessage()}");
        }
    }

    /**
     * Set debug mode - simulates email sending without actually sending
     */
    public function setDebugMode(bool $debug): void
    {
        $this->debugMode = $debug;
    }
    
    /**
     * Check if debug mode is enabled
     */
    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }
    
    /**
     * Set account switch callback for rotation
     */
    public function setAccountSwitchCallback(callable $callback): void
    {
        $this->accountSwitchCallback = $callback;
    }

    /**
     * Clear all recipients from mailer
     */
    public function clearAddresses(): void
    {
        $this->mailer->clearAddresses();
    }

    /**
     * Clear all CC recipients from mailer
     */
    public function clearCCs(): void
    {
        $this->mailer->clearCCs();
    }

    /**
     * Clear all BCC recipients from mailer
     */
    public function clearBCCs(): void
    {
        $this->mailer->clearBCCs();
    }

    /**
     * Clear all attachments from mailer
     */
    public function clearAttachments(): void
    {
        $this->mailer->clearAttachments();
    }

    /**
     * Add recipient address
     */
    public function addAddress(string $email, string $name = ''): void
    {
        $this->mailer->addAddress($email, $name);
    }

    /**
     * Add CC address
     */
    public function addCC(string $email, string $name = ''): void
    {
        $this->mailer->addCC($email, $name);
    }

    /**
     * Add BCC address
     */
    public function addBCC(string $email, string $name = ''): void
    {
        $this->mailer->addBCC($email, $name);
    }

    /**
     * Add attachment
     */
    public function addAttachment(string $path, string $name = ''): void
    {
        $this->mailer->addAttachment($path, $name);
    }

    /**
     * Set email as HTML format
     */
    public function isHTML(bool $isHtml = true): void
    {
        $this->mailer->isHTML($isHtml);
    }

    /**
     * Set email subject (direct property access)
     */
    public function setSubject(string $subject): void
    {
        $this->mailer->Subject = $subject;
    }

    /**
     * Get email subject
     */
    public function getSubject(): string
    {
        return $this->mailer->Subject ?? '';
    }

    /**
     * Set email body (direct property access)
     */
    public function setBody(string $body): void
    {
        $this->mailer->Body = $body;
    }

    /**
     * Get email body
     */
    public function getBody(): string
    {
        return $this->mailer->Body ?? '';
    }

    /**
     * Set alternative body for plain text
     */
    public function setAltBody(string $altBody): void
    {
        $this->mailer->AltBody = $altBody;
    }

    /**
     * Send the email (or simulate if in debug mode)
     */
    public function send(): bool
    {
        if ($this->debugMode) {
            return true;
        }
        return $this->mailer->send();
    }

    /**
     * Magic getter for backward compatibility with direct property access
     */
    public function __get(string $name)
    {
        if ($name === 'Subject') {
            return $this->mailer->Subject;
        }
        if ($name === 'Body') {
            return $this->mailer->Body;
        }
        if ($name === 'AltBody') {
            return $this->mailer->AltBody;
        }
        return null;
    }

    /**
     * Magic setter for backward compatibility with direct property access
     */
    public function __set(string $name, $value): void
    {
        if ($name === 'Subject') {
            $this->mailer->Subject = $value;
        } elseif ($name === 'Body') {
            $this->mailer->Body = $value;
        } elseif ($name === 'AltBody') {
            $this->mailer->AltBody = $value;
        }
    }

    /**
     * Inject preview text for both HTML and plain text emails
     * 
     * For HTML: Uses META tag, Preheader DIV, Mailchimp conditional comment
     * For Plain Text: Prepends preview text with separator
     * 
     * @param string $body Email body (HTML or plain text)
     * @param string $previewText Preview text (35-90 characters recommended)
     * @param bool|null $isHtml Whether this is HTML (null = auto-detect)
     * @return string Modified email body
     */
    public function injectPreviewText(string $body, string $previewText, ?bool $isHtml = null): string
    {
        if ($previewText === null) {
            return $body;
        }

        $previewText = trim($previewText);
        if ($previewText === '') {
            return $body;
        }

        // prevent duplicate injections
        $marker = '<!--preview-text-injected-->';
        if (stripos($body, $marker) !== false) {
            return $body;
        }

        if ($isHtml === null) {
            $isHtml = (stripos($body, '<html') !== false)
                || (stripos($body, '<body') !== false)
                || (stripos($body, '<!DOCTYPE') !== false);
        }

        if (!$isHtml) {
            // plain text email -> prepend preview text
            $divider = str_repeat('=', 60);
            if (str_starts_with($body, $previewText)) {
                return $body;
            }

            return $previewText . "\n" . $divider . "\n\n" . ltrim($body);
        }

        $escaped = htmlspecialchars($previewText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // -- HEAD section ---------------------------------------------------
        $metaTag = '<meta name="description" content="' . $escaped . '">' . "\n";
        if (stripos($body, 'name="description"') !== false) {
            $body = preg_replace(
                '/<meta\s+name="description"\s+content="[^"]*"\s*\/?\s*>/i',
                $metaTag,
                $body,
                1
            );
        } else {
            if (stripos($body, '</head>') !== false) {
                $body = preg_replace('/(<\/head>)/i', $metaTag . '$1', $body, 1);
            } else {
                $body = $metaTag . $body;
            }
        }

        // optional OG / Twitter description for some clients
        if (stripos($body, 'property="og:description"') === false) {
            $og = '<meta property="og:description" content="' . $escaped . '">' . "\n";
            $body = preg_replace('/(<\/head>)/i', $og . '$1', $body, 1);
        }

        if (stripos($body, 'name="twitter:description"') === false) {
            $tw = '<meta name="twitter:description" content="' . $escaped . '">' . "\n";
            $body = preg_replace('/(<\/head>)/i', $tw . '$1', $body, 1);
        }

        // -- BODY section ---------------------------------------------------
        $padding = str_repeat('&nbsp;', 12) . str_repeat('&#8203;', 12); // add zero-width spaces
        $preheader = '<div class="preview-text" style="display:none !important;visibility:hidden;mso-hide:all;font-size:1px;line-height:1px;max-height:0;max-width:0;color:#ffffff;opacity:0;overflow:hidden;">'
            . $escaped . $padding . '</div>' . "\n" . $marker . "\n";

        if (stripos($body, '<body') !== false) {
            $body = preg_replace('/(<body[^>]*>)/i', '$1' . "\n" . $preheader, $body, 1);
        } else {
            $body = $preheader . $body;
        }

        $hiddenSpan = '<span style="display:none !important;visibility:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#ffffff;max-height:0;max-width:0;opacity:0;overflow:hidden;">'
            . $escaped . '</span>' . "\n";

        $body = preg_replace(
            '/(<body[^>]*>\s*<div[^>]*display\s*:\s*none[^>]*>.*?<\/div>)/is',
            '$1' . $hiddenSpan,
            $body,
            1
        );

        if (stripos($body, 'class="mcnPreviewText"') !== false) {
            // update existing Mailchimp span
            $body = preg_replace(
                '/(<span[^>]*class="mcnPreviewText"[^>]*>)(.*?)(<\/span>)/is',
                '$1' . $escaped . '$3',
                $body,
                1
            );

            $body = preg_replace_callback(
                '/(class="mcnPreviewText"[^>]*style=")([^"]*)"/i',
                function ($matches) {
                    $required = 'display:none !important;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;visibility:hidden;color:#ffffff;mso-hide:all;';
                    return $matches[1] . $required . '"';
                },
                $body,
                1
            );
        } else {
            $mailchimpPreview = '<!--[if !gte mso 9]><!----><span class="mcnPreviewText" style="display:none !important;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;visibility:hidden;color:#ffffff;mso-hide:all;">'
                . $escaped
                . '</span><!--<![endif]-->' . "\n";

            $body = preg_replace(
                '/(<body[^>]*>\s*<div[^>]*display\s*:\s*none[^>]*>.*?<\/div>)/is',
                '$1' . $mailchimpPreview,
                $body,
                1
            );
        }

        return $body;
    }

    /**
     * Send email to recipient
     *
     * @param string $to Recipient email address
     * @param string $toName Recipient name
     * @param string $subject Email subject
     * @param string $body Email content
     * @param bool $isHtml Whether to send as HTML
     * @param string|null $cc CC addresses (comma-separated)
     * @param string|null $bcc BCC addresses (comma-separated)
     * @param string|null $attachment File path for attachment
     * @param string|null $previewText Preview text for email clients
     * @return bool
     * @throws Exception
     */
    public function sendMail(
        string $to,
        string $toName = '',
        string $subject = '',
        string $body = '',
        bool $isHtml = false,
        ?string $cc = null,
        ?string $bcc = null,
        ?string $attachment = null,
        ?string $previewText = null
    ): bool {
        try {
            // Clear previous recipients and attachments
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearCCs();
            $this->mailer->clearBCCs();

            // Add primary recipient
            $this->mailer->addAddress($to, $toName);

            // Add CC recipients
            if ($cc) {
                $ccList = explode(',', $cc);
                foreach ($ccList as $ccEmail) {
                    $ccEmail = trim($ccEmail);
                    if ($ccEmail) {
                        $this->mailer->addCC($ccEmail);
                    }
                }
            }

            // Add BCC recipients
            if ($bcc) {
                $bccList = explode(',', $bcc);
                foreach ($bccList as $bccEmail) {
                    $bccEmail = trim($bccEmail);
                    if ($bccEmail) {
                        $this->mailer->addBCC($bccEmail);
                    }
                }
            }

            // Add attachment if provided
            if ($attachment && file_exists($attachment)) {
                $this->mailer->addAttachment($attachment);
            }

            // Inject preview text for HTML emails (UNIFIED location for all sends)
            if ($isHtml && $previewText) {
                $body = $this->injectPreviewText($body, $previewText);
            }

            // Set email content
            $this->mailer->isHTML($isHtml);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;

            if ($isHtml) {
                // Set plain text alternative for HTML emails
                $this->mailer->AltBody = strip_tags($body);
            }

            // Send the email (or simulate if in debug mode)
            if ($this->debugMode) {
                // Debug mode: simulate successful send without actually sending
                return true;
            }
            
            return $this->mailer->send();
        } catch (Exception $e) {
            throw new \Exception("Email delivery failed: {$this->mailer->ErrorInfo}");
        }
    }

    /**
     * Send bulk emails to multiple recipients
     *
     * @param array $recipients Recipient list [['email' => 'xxx', 'name' => 'xxx'], ...]
     * @param string $subject Email subject
     * @param string $body Email content
     * @param bool $isHtml Whether to send as HTML
     * @return array Results ['success' => [], 'failed' => []]
     */
    public function sendBulkMail(
        array $recipients,
        string $subject,
        string $body,
        bool $isHtml = false
    ): array {
        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($recipients as $recipient) {
            $email = $recipient['email'] ?? '';
            $name = $recipient['name'] ?? '';

            if (!$email) {
                continue;
            }

            try {
                $sent = $this->sendMail($email, $name, $subject, $body, $isHtml);
                if ($sent) {
                    $results['success'][] = $email;
                } else {
                    $results['failed'][] = $email;
                }
            } catch (\Exception $e) {
                $results['failed'][] = $email;
            }
        }

        return $results;
    }

    /**
     * Send group email (群组发送) - Send a single email with multiple recipients using BCC
     * Each recipient receives the email individually and cannot see other recipients
     *
     * @param array $recipients Recipient list [['email' => 'xxx', 'name' => 'xxx'], ...]
     * @param string $subject Email subject
     * @param string $body Email content
     * @param bool $isHtml Whether to send as HTML
     * @param string|null $cc CC addresses (comma-separated)
     * @param string|null $attachment File path for attachment
     * @return array Results ['success' => count, 'failed' => count, 'recipients' => count, 'details' => [...]]
     * @throws Exception
     */
    public function sendGroupMail(
        array $recipients,
        string $subject,
        string $body,
        bool $isHtml = false,
        ?string $cc = null,
        ?string $attachment = null
    ): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'recipients' => count($recipients),
            'details' => []
        ];

        if (empty($recipients)) {
            $results['failed'] = 1;
            $results['details'][] = ['status' => 'error', 'message' => 'No recipients provided'];
            return $results;
        }

        try {
            // Clear previous recipients and attachments
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearCCs();
            $this->mailer->clearBCCs();

            // Add a primary recipient (usually the first one, but won't be visible)
            $firstRecipient = $recipients[0];
            $this->mailer->addAddress($firstRecipient['email'], $firstRecipient['name'] ?? '');

            // Add all other recipients as BCC
            for ($i = 1; $i < count($recipients); $i++) {
                $recipient = $recipients[$i];
                if (!empty($recipient['email'])) {
                    $this->mailer->addBCC(trim($recipient['email']));
                }
            }

            // Add CC recipients if provided
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

            // Set email content
            $this->mailer->isHTML($isHtml);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;

            if ($isHtml) {
                $this->mailer->AltBody = strip_tags($body);
            }

            // Send the email (or simulate if in debug mode)
            if ($this->debugMode || $this->mailer->send()) {
                $results['success'] = count($recipients);
                $results['details'][] = [
                    'status' => 'success',
                    'message' => 'Email sent to ' . count($recipients) . ' recipients',
                    'recipients' => count($recipients),
                    'to' => $firstRecipient['email'],
                    'bcc_count' => count($recipients) - 1
                ];
            } else {
                $results['failed'] = count($recipients);
                $results['details'][] = [
                    'status' => 'error',
                    'message' => $this->mailer->ErrorInfo
                ];
            }
        } catch (Exception $e) {
            $results['failed'] = count($recipients);
            $results['details'][] = [
                'status' => 'error',
                'message' => "Email delivery failed: {$e->getMessage()}"
            ];
        }

        return $results;
    }

    /**
     * Send batch group emails - Split recipients into batches and send each batch as a group
     * Each batch is sent with one SMTP connection using BCC for all recipients in that batch
     *
     * @param array $recipients Recipient list [['email' => 'xxx', 'name' => 'xxx'], ...]
     * @param int $batchSize Number of recipients per batch
     * @param string $subject Email subject
     * @param string $body Email content (supports template variables: {{name}}, {{email}}, {{index}}, {{total}})
     * @param bool $isHtml Whether to send as HTML
     * @param bool $personalize Whether to replace template variables for each recipient (sends individual emails)
     * @param string|null $cc CC addresses (comma-separated)
     * @param string|null $attachment File path for attachment
     * @param bool $replaceVariables Whether to replace variables in BCC mode (primary recipient only)
     * @return array Results with batch details
     * @throws Exception
     */
    public function sendBatchGroupMail(
        array $recipients,
        int $batchSize,
        string $subject,
        string $body,
        bool $isHtml = false,
        bool $personalize = false,
        ?string $cc = null,
        ?string $attachment = null,
        bool $replaceVariables = false,
        ?string $previewText = null
    ): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'recipients' => count($recipients),
            'batches' => 0,
            'details' => [],
            'succeeded_emails' => [],  // Track which emails actually succeeded
            'failed_emails' => []      // Track which emails actually failed
        ];

        if (empty($recipients) || $batchSize < 1) {
            $results['failed'] = count($recipients);
            $results['details'][] = ['status' => 'error', 'message' => 'Invalid recipients or batch size'];
            return $results;
        }

        // Split recipients into batches
        $batches = array_chunk($recipients, $batchSize);
        $totalBatches = count($batches);
        $batchNumber = 0;

        foreach ($batches as $batch) {
            $batchNumber++;
            try {
                // When personalization is enabled, send individual emails to each recipient
                // so each one gets their own personalized content
                if ($personalize) {
                    foreach ($batch as $recipientIdx => $recipient) {
                        $personalizedSubject = $this->replaceTemplateVariables(
                            $subject,
                            $recipient,
                            ($batchNumber - 1) * $batchSize + $recipientIdx + 1,
                            count($recipients)
                        );
                        $personalizedBody = $this->replaceTemplateVariables(
                            $body,
                            $recipient,
                            ($batchNumber - 1) * $batchSize + $recipientIdx + 1,
                            count($recipients)
                        );

                        // Send individual email with personalized content
                        $this->mailer->clearAddresses();
                        $this->mailer->clearAttachments();
                        $this->mailer->clearCCs();
                        $this->mailer->clearBCCs();

                        // Privacy protection: Each recipient only sees themselves in TO field
                        // No BCC needed - direct individual send
                        $this->mailer->addAddress($recipient['email'], $recipient['name'] ?? '');

                        // Add attachment if provided
                        if ($attachment && file_exists($attachment)) {
                            $this->mailer->addAttachment($attachment);
                        }

                        // Inject preview text for HTML emails
                        if ($isHtml && $previewText) {
                            $personalizedBody = $this->injectPreviewText($personalizedBody, $previewText);
                        }
                        
                        // Set email content
                        $this->mailer->isHTML($isHtml);
                        $this->mailer->Subject = $personalizedSubject;
                        $this->mailer->Body = $personalizedBody;

                        if ($isHtml) {
                            $this->mailer->AltBody = strip_tags($personalizedBody);
                        }

                        // Send individual email (or simulate if in debug mode)
                        if ($this->debugMode || $this->mailer->send()) {
                            $results['success']++;
                            $results['succeeded_emails'][] = $recipient['email'];
                            
                            // Switch account after successful send (for load balancing across multiple accounts)
                            if ($this->accountSwitchCallback) {
                                call_user_func($this->accountSwitchCallback);
                            }
                        } else {
                            $results['failed']++;
                            $results['failed_emails'][] = $recipient['email'];
                        }
                    }

                    $results['details'][] = [
                        'status' => 'success',
                        'batch' => $batchNumber,
                        'message' => "Batch {$batchNumber}: Sent " . count($batch) . ' personalized emails',
                        'recipients_count' => count($batch)
                    ];

                } else {
                    // BCC group sending mode
                    // Use sendGroupMailWithVariables if variable replacement is enabled
                    if ($replaceVariables) {
                        $batchResult = $this->sendGroupMailWithVariables(
                            $batch,
                            $subject,
                            $body,
                            $isHtml,
                            true,  // replaceVariables = true
                            $cc,
                            $attachment
                        );
                        $results['success'] += $batchResult['success'];
                        $results['failed'] += $batchResult['failed'];
                        $results['details'][] = $batchResult['details'][0] ?? [];
                        
                        // Merge succeeded/failed email lists
                        if (!empty($batchResult['succeeded_emails'])) {
                            $results['succeeded_emails'] = array_merge(
                                $results['succeeded_emails'], 
                                $batchResult['succeeded_emails']
                            );
                        }
                        if (!empty($batchResult['failed_emails'])) {
                            $results['failed_emails'] = array_merge(
                                $results['failed_emails'], 
                                $batchResult['failed_emails']
                            );
                        }
                    } else {
                        // Original BCC group sending mode without variable replacement
                        // Note: We send individual emails to each recipient to simulate BCC functionality
                        // This ensures each recipient gets their copy without seeing others
                        
                        $emailsSent = 0;
                        $emailsFailed = 0;
                        
                        foreach ($batch as $recipient) {
                            try {
                                // Clear previous recipients and attachments
                                $this->mailer->clearAddresses();
                                $this->mailer->clearAttachments();
                                $this->mailer->clearCCs();
                                $this->mailer->clearBCCs();

                                // Privacy protection: Each recipient only sees themselves in TO field
                                // No BCC needed - direct individual send
                                $this->mailer->addAddress($recipient['email'], $recipient['name'] ?? '');

                                // Add attachment if provided
                                if ($attachment && file_exists($attachment)) {
                                    $this->mailer->addAttachment($attachment);
                                }

                                // Inject preview text for HTML emails
                                $emailBody = $body;
                                if ($isHtml && $previewText) {
                                    $emailBody = $this->injectPreviewText($body, $previewText);
                                }
                                
                                // Set email content
                                $this->mailer->isHTML($isHtml);
                                $this->mailer->Subject = $subject;
                                $this->mailer->Body = $emailBody;

                                if ($isHtml) {
                                    $this->mailer->AltBody = strip_tags($emailBody);
                                }

                                // Send to individual recipient (or simulate if in debug mode)
                                if ($this->debugMode || $this->mailer->send()) {
                                    $emailsSent++;
                                    $results['succeeded_emails'][] = $recipient['email'];
                                    
                                    // Switch account after successful send (for load balancing across multiple accounts)
                                    if ($this->accountSwitchCallback) {
                                        call_user_func($this->accountSwitchCallback);
                                    }
                                } else {
                                    $emailsFailed++;
                                    $results['failed_emails'][] = $recipient['email'];
                                }
                            } catch (Exception $e) {
                                $emailsFailed++;
                                $results['failed_emails'][] = $recipient['email'];
                            }
                        }
                        
                        // Update results
                        $results['success'] += $emailsSent;
                        $results['failed'] += $emailsFailed;
                        
                        if ($emailsFailed === 0) {
                            $results['details'][] = [
                                'status' => 'success',
                                'batch' => $batchNumber,
                                'message' => "Batch {$batchNumber}: Sent to " . count($batch) . ' recipients via BCC (individual sends)',
                                'recipients_count' => count($batch)
                            ];
                        } else {
                            $results['details'][] = [
                                'status' => 'partial',
                                'batch' => $batchNumber,
                                'message' => "Batch {$batchNumber}: Sent to {$emailsSent}, Failed: {$emailsFailed}",
                                'recipients_count' => count($batch)
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                $results['failed'] += count($batch);
                $results['details'][] = [
                    'status' => 'error',
                    'batch' => $batchNumber,
                    'message' => "Batch {$batchNumber}: Error - {$e->getMessage()}"
                ];
            }
        }

        $results['batches'] = $totalBatches;
        return $results;
    }

    /**
     * Send group email with optional template variable replacement
     * Variables are replaced based on the primary recipient (first in list)
     * All BCC recipients see the personalized content for the primary recipient
     *
     * @param array $recipients Recipient list [['email' => 'xxx', 'name' => 'xxx'], ...]
     * @param string $subject Email subject
     * @param string $body Email content (can contain template variables)
     * @param bool $isHtml Whether to send as HTML
     * @param bool $replaceVariables Whether to replace template variables (for primary recipient)
     * @param string|null $cc CC addresses (comma-separated)
     * @param string|null $attachment File path for attachment
     * @return array Results ['success' => count, 'failed' => count, 'recipients' => count, 'details' => [...]]
     * @throws Exception
     */
    public function sendGroupMailWithVariables(
        array $recipients,
        string $subject,
        string $body,
        bool $isHtml = false,
        bool $replaceVariables = false,
        ?string $cc = null,
        ?string $attachment = null
    ): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'recipients' => count($recipients),
            'details' => [],
            'succeeded_emails' => [],  // Track which emails actually succeeded
            'failed_emails' => []      // Track which emails actually failed
        ];

        if (empty($recipients)) {
            $results['failed'] = 1;
            $results['details'][] = ['status' => 'error', 'message' => 'No recipients provided'];
            return $results;
        }

        // Loop through each recipient and send individually (no BCC)
        $emailsSent = 0;
        $emailsFailed = 0;
        
        foreach ($recipients as $index => $recipient) {
            try {
                // Clear previous recipients and attachments
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                $this->mailer->clearCCs();
                $this->mailer->clearBCCs();

                // Privacy protection: Each recipient only sees themselves in TO field
                $this->mailer->addAddress($recipient['email'], $recipient['name'] ?? '');

                // Replace variables if enabled
                $personalizedSubject = $subject;
                $personalizedBody = $body;
                
                if ($replaceVariables) {
                    $personalizedSubject = $this->replaceTemplateVariables(
                        $subject,
                        $recipient,
                        $index + 1,
                        count($recipients)
                    );
                    $personalizedBody = $this->replaceTemplateVariables(
                        $body,
                        $recipient,
                        $index + 1,
                        count($recipients)
                    );
                }

                // Add attachment if provided
                if ($attachment && file_exists($attachment)) {
                    $this->mailer->addAttachment($attachment);
                }

                // Set email content
                $this->mailer->isHTML($isHtml);
                $this->mailer->Subject = $personalizedSubject;
                $this->mailer->Body = $personalizedBody;

                if ($isHtml) {
                    $this->mailer->AltBody = strip_tags($personalizedBody);
                }

                // Send the email (or simulate if in debug mode)
                if ($this->debugMode || $this->mailer->send()) {
                    $emailsSent++;
                    $results['succeeded_emails'][] = $recipient['email'];
                    
                    // Switch account after successful send (for load balancing across multiple accounts)
                    if ($this->accountSwitchCallback) {
                        call_user_func($this->accountSwitchCallback);
                    }
                } else {
                    $emailsFailed++;
                    $results['failed_emails'][] = $recipient['email'];
                }
            } catch (Exception $e) {
                $emailsFailed++;
                $results['failed_emails'][] = $recipient['email'];
            }
        }

        $results['success'] = $emailsSent;
        $results['failed'] = $emailsFailed;
        $results['details'][] = [
            'status' => 'success',
            'message' => "Sent {$emailsSent} individual emails" . ($replaceVariables ? ' (with variable replacement)' : ''),
            'recipients' => count($recipients),
            'sent' => $emailsSent,
            'failed' => $emailsFailed
        ];

        return $results;
    }

    /**
     * Replace template variables in text
     * Supports both custom format {{name}}, {{email}}, {{index}}, {{total}}
     * AND Mailchimp format *|MC:VARIABLE|*
     * 
     * Unmatched variables are REMOVED from the output
     */
    public function replaceTemplateVariables(string $text, array $recipient, int $index, int $total): string
    {
        // Custom template variables (double curly braces)
        $text = str_replace('{{name}}', $recipient['name'] ?? '', $text);
        $text = str_replace('{{email}}', $recipient['email'] ?? '', $text);
        $text = str_replace('{{index}}', (string)$index, $text);
        $text = str_replace('{{total}}', (string)$total, $text);

        // Mailchimp-style variables (*|MC:VARIABLE|*)
        // Get the subject from body if available, or use a default
        $subject = $recipient['subject'] ?? '';
        $text = str_replace('*|MC:SUBJECT|*', $subject, $text);
        
        // First name extraction from full name
        $firstName = $recipient['name'] ?? '';
        if (strpos($firstName, ' ') !== false) {
            $nameParts = explode(' ', $firstName, 2);
            $firstName = $nameParts[0];
        }
        $text = str_replace('*|MC:FNAME|*', $firstName, $text);
        
        // Last name extraction from full name
        $lastName = '';
        if (isset($recipient['name']) && strpos($recipient['name'], ' ') !== false) {
            $nameParts = explode(' ', $recipient['name'], 2);
            $lastName = $nameParts[1] ?? '';
        }
        $text = str_replace('*|MC:LNAME|*', $lastName, $text);
        
        // Email address
        $text = str_replace('*|MC:EMAIL|*', $recipient['email'] ?? '', $text);
        
        // Full name
        $text = str_replace('*|MC:FNAME_LNAME|*', $recipient['name'] ?? '', $text);
        $text = str_replace('*|MC:FULL_NAME|*', $recipient['name'] ?? '', $text);
        
        // For any other Mailchimp variables, try to get from recipient array
        // This regex finds all *|MC:VARIABLE|* patterns
        $text = preg_replace_callback(
            '/\*\|MC:([A-Z_]+)\|\*/',
            function($matches) use ($recipient) {
                $varName = strtolower($matches[1]);
                // Return value if found, otherwise remove the variable (empty string)
                return $recipient[$varName] ?? '';
            },
            $text
        );
        
        // Clean up extra spaces caused by variable removal
        // Remove leading/trailing spaces from lines
        $lines = explode("\n", $text);
        $lines = array_map(function($line) {
            return rtrim($line);
        }, $lines);
        $text = implode("\n", $lines);
        
        // Remove consecutive blank lines (more than 2)
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);
        
        return $text;
    }
}

