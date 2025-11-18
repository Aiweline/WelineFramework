<?php
declare(strict_types=1);

/**
 * GuoLaiRen SMTP Module
 * Breakpoint Manager - Handles resume functionality for interrupted email sending
 * 
 * @category  GuoLaiRen
 * @package   GuoLaiRen_Smtp
 * @author    GuoLaiRen Development Team
 */

namespace GuoLaiRen\Smtp\Helper;

class BreakpointManager
{
    private string $emailsDir;
    private string $breakpointFile;
    private string $failedEmailsFile;
    private string $permanentFailedFile;
    private string $sentEmailsFile;
    private string $fileIdentifier;  // Unique identifier for this recipient file
    
    const MAX_RETRY_ATTEMPTS = 3;  // Allow up to 3 failures (initial send + 2 retries)
    
    public function __construct(string $emailsDir, string $recipientFile = '')
    {
        $this->emailsDir = $emailsDir;
        
        // Generate unique file identifier based on recipient file
        if (!empty($recipientFile)) {
            // Extract filename without extension and path
            $basename = basename($recipientFile);
            $filename = pathinfo($basename, PATHINFO_FILENAME);
            
            // Keep original filename but ensure it's safe for filesystem
            // Only replace problematic characters, keep readable name
            // Note: On Windows, Chinese characters may appear garbled due to console encoding,
            // but we keep them as-is for consistency with file system
            $safeFilename = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $filename);
            // Limit length to avoid filesystem issues (max 100 chars)
            if (function_exists('mb_substr')) {
                $this->fileIdentifier = mb_substr($safeFilename, 0, 100, '8bit');
            } else {
                $this->fileIdentifier = substr($safeFilename, 0, 100);
            }
        } else {
            $this->fileIdentifier = 'default';
        }
        
        // Use unique filenames for each recipient file
        $this->breakpointFile = $emailsDir . '/breakpoint_' . $this->fileIdentifier . '.json';
        $this->failedEmailsFile = $emailsDir . '/failed_emails_' . $this->fileIdentifier . '.json';
        $this->permanentFailedFile = $emailsDir . '/permanent_failed_' . $this->fileIdentifier . '.json';
        $this->sentEmailsFile = $emailsDir . '/sent_emails_' . $this->fileIdentifier . '.json';
        
        // Ensure directory exists
        if (!is_dir($this->emailsDir)) {
            mkdir($this->emailsDir, 0755, true);
        }
    }
    
    /**
     * Save breakpoint data with file locking
     * 
     * @param array $data Breakpoint data including progress and failed emails
     */
    public function saveBreakpoint(array $data): void
    {
        $data['timestamp'] = date('Y-m-d H:i:s');
        $data['version'] = '1.0';
        $data['file_identifier'] = $this->fileIdentifier;
        $data['process_id'] = getmypid();
        
        // Use file locking to prevent concurrent writes
        $fp = fopen($this->breakpointFile, 'c');
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        } else {
            // Fallback without lock
            file_put_contents(
                $this->breakpointFile,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }
    }
    
    /**
     * Load breakpoint data
     * 
     * @return array|null Breakpoint data or null if not exists
     */
    public function loadBreakpoint(): ?array
    {
        if (!file_exists($this->breakpointFile)) {
            return null;
        }
        
        $content = file_get_contents($this->breakpointFile);
        $data = json_decode($content, true);
        
        return $data ?: null;
    }
    
    /**
     * Clear breakpoint data
     */
    public function clearBreakpoint(): void
    {
        if (file_exists($this->breakpointFile)) {
            unlink($this->breakpointFile);
        }
    }
    
    /**
     * Check if breakpoint exists
     * 
     * @return bool
     */
    public function hasBreakpoint(): bool
    {
        return file_exists($this->breakpointFile);
    }
    
    /**
     * Add failed email to tracking
     * 
     * @param string $email Failed email address
     * @param string $reason Failure reason
     * @param array $recipientData Full recipient data
     */
    public function addFailedEmail(string $email, string $reason, array $recipientData = []): void
    {
        $failedData = $this->loadFailedEmails();
        
        if (!isset($failedData[$email])) {
            $failedData[$email] = [
                'email' => $email,
                'name' => $recipientData['name'] ?? '',
                'attempts' => 0,
                'reasons' => [],
                'first_failed_at' => date('Y-m-d H:i:s'),
                'last_failed_at' => null,
            ];
        }
        
        $failedData[$email]['attempts']++;
        $failedData[$email]['reasons'][] = [
            'reason' => $reason,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        $failedData[$email]['last_failed_at'] = date('Y-m-d H:i:s');
        
        // If exceeded max attempts (more than 3 failures), move to permanent failed list
        if ($failedData[$email]['attempts'] >= self::MAX_RETRY_ATTEMPTS) {
            $failedData[$email]['permanent_reason'] = 'Exceeded maximum retry attempts (' . self::MAX_RETRY_ATTEMPTS . ')';
            $this->addPermanentFailedEmail($failedData[$email]);
            unset($failedData[$email]);
        }
        
        $this->saveFailedEmails($failedData);
    }
    
    /**
     * Load failed emails
     * 
     * @return array
     */
    public function loadFailedEmails(): array
    {
        if (!file_exists($this->failedEmailsFile)) {
            return [];
        }
        
        $content = file_get_contents($this->failedEmailsFile);
        $data = json_decode($content, true);
        
        return $data ?: [];
    }
    
    /**
     * Save failed emails
     * 
     * @param array $failedData
     */
    private function saveFailedEmails(array $failedData): void
    {
        file_put_contents(
            $this->failedEmailsFile,
            json_encode($failedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
    
    /**
     * Get failed emails list for retry
     * 
     * @return array Array of recipient data
     */
    public function getFailedEmailsForRetry(): array
    {
        $failedData = $this->loadFailedEmails();
        $recipients = [];
        
        foreach ($failedData as $email => $data) {
            $recipients[] = [
                'email' => $data['email'],
                'name' => $data['name'] ?? '',
                'retry_attempt' => $data['attempts'],
            ];
        }
        
        return $recipients;
    }
    
    /**
     * Clear specific failed email (after successful retry)
     * 
     * @param string $email
     */
    public function clearFailedEmail(string $email): void
    {
        $failedData = $this->loadFailedEmails();
        
        if (isset($failedData[$email])) {
            unset($failedData[$email]);
            $this->saveFailedEmails($failedData);
        }
    }
    
    /**
     * Clear all failed emails
     */
    public function clearAllFailedEmails(): void
    {
        if (file_exists($this->failedEmailsFile)) {
            unlink($this->failedEmailsFile);
        }
    }
    
    /**
     * Add email to permanent failed list
     * 
     * @param array $emailData
     */
    private function addPermanentFailedEmail(array $emailData): void
    {
        $permanentFailed = $this->loadPermanentFailedEmails();
        $permanentFailed[$emailData['email']] = $emailData;
        $permanentFailed[$emailData['email']]['moved_to_permanent_at'] = date('Y-m-d H:i:s');
        
        file_put_contents(
            $this->permanentFailedFile,
            json_encode($permanentFailed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
    
    /**
     * Load permanent failed emails
     * 
     * @return array
     */
    public function loadPermanentFailedEmails(): array
    {
        if (!file_exists($this->permanentFailedFile)) {
            return [];
        }
        
        $content = file_get_contents($this->permanentFailedFile);
        $data = json_decode($content, true);
        
        return $data ?: [];
    }
    
    /**
     * Get statistics
     * 
     * @return array
     */
    public function getStatistics(): array
    {
        $failedCount = count($this->loadFailedEmails());
        $permanentFailedCount = count($this->loadPermanentFailedEmails());
        $hasBreakpoint = $this->hasBreakpoint();
        
        return [
            'has_breakpoint' => $hasBreakpoint,
            'failed_emails_count' => $failedCount,
            'permanent_failed_count' => $permanentFailedCount,
            'breakpoint_data' => $hasBreakpoint ? $this->loadBreakpoint() : null,
        ];
    }
    
    /**
     * Get breakpoint file paths for reference
     * 
     * @return array
     */
    public function getFilePaths(): array
    {
        return [
            'breakpoint' => $this->breakpointFile,
            'failed_emails' => $this->failedEmailsFile,
            'permanent_failed' => $this->permanentFailedFile,
            'sent_emails' => $this->sentEmailsFile,
        ];
    }
    
    /**
     * Add email to sent list
     * 
     * @param string $email Email address
     * @param array $recipientData Full recipient data
     */
    public function addSentEmail(string $email, array $recipientData = []): void
    {
        $sentData = $this->loadSentEmails();
        
        $sentData[$email] = [
            'email' => $email,
            'name' => $recipientData['name'] ?? '',
            'sent_at' => date('Y-m-d H:i:s'),
            'subject' => $recipientData['subject'] ?? '',
        ];
        
        $this->saveSentEmails($sentData);
    }
    
    /**
     * Check if email has been sent
     * 
     * @param string $email Email address
     * @return bool
     */
    public function isEmailSent(string $email): bool
    {
        $sentData = $this->loadSentEmails();
        return isset($sentData[$email]);
    }
    
    /**
     * Load sent emails
     * 
     * @return array
     */
    public function loadSentEmails(): array
    {
        if (!file_exists($this->sentEmailsFile)) {
            return [];
        }
        
        $content = file_get_contents($this->sentEmailsFile);
        $data = json_decode($content, true);
        
        return $data ?: [];
    }
    
    /**
     * Save sent emails with file locking
     * 
     * @param array $sentData
     */
    private function saveSentEmails(array $sentData): void
    {
        // Use file locking to prevent concurrent writes
        $fp = fopen($this->sentEmailsFile, 'c');
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($sentData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        } else {
            // Fallback without lock
            file_put_contents(
                $this->sentEmailsFile,
                json_encode($sentData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }
    }
    
    /**
     * Clear all sent emails (for fresh start)
     */
    public function clearAllSentEmails(): void
    {
        if (file_exists($this->sentEmailsFile)) {
            unlink($this->sentEmailsFile);
        }
    }
    
    /**
     * Get count of sent emails
     * 
     * @return int
     */
    public function getSentEmailsCount(): int
    {
        return count($this->loadSentEmails());
    }
    
    /**
     * Filter out already sent emails from recipient list
     * 
     * @param array $recipients Array of recipients
     * @return array Filtered recipients (not yet sent)
     */
    public function filterUnsentRecipients(array $recipients): array
    {
        $sentEmails = $this->loadSentEmails();
        $unsent = [];
        
        foreach ($recipients as $recipient) {
            if (!isset($sentEmails[$recipient['email']])) {
                $unsent[] = $recipient;
            }
        }
        
        return $unsent;
    }
    
    /**
     * Get list of skipped emails (already sent)
     * 
     * @param array $recipients Original recipient list
     * @return array List of skipped recipients
     */
    public function getSkippedRecipients(array $recipients): array
    {
        $sentEmails = $this->loadSentEmails();
        $skipped = [];
        
        foreach ($recipients as $recipient) {
            if (isset($sentEmails[$recipient['email']])) {
                $skipped[] = array_merge($recipient, [
                    'sent_at' => $sentEmails[$recipient['email']]['sent_at'] ?? 'unknown'
                ]);
            }
        }
        
        return $skipped;
    }
}

