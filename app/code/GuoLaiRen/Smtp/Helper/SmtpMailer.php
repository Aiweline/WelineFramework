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
        ?string $attachment = null
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

            // Set email content
            $this->mailer->isHTML($isHtml);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;

            if ($isHtml) {
                // Set plain text alternative for HTML emails
                $this->mailer->AltBody = strip_tags($body);
            }

            // Send the email
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
}

