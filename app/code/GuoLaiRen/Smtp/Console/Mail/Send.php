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
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Send implements CommandInterface
{
    private Printing $printer;
    private SmtpMailer $mailer;

    public function __construct(
        Printing $printer,
        SmtpMailer $mailer
    ) {
        $this->printer = $printer;
        $this->mailer = $mailer;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // Validate required parameters
        if (empty($args['to'])) {
            $this->printer->error(__('Recipient address is required'));
            return;
        }

        if (empty($args['subject'])) {
            $this->printer->error(__('Email subject is required'));
            return;
        }

        if (empty($args['body'])) {
            $this->printer->error(__('Email body is required'));
            return;
        }

        try {
            $this->printer->note(__('Sending email...'));

            // Set recipient details
            $to = $args['to'];
            $toName = $args['to-name'] ?? '';

            // Set subject and content
            $subject = $args['subject'];
            $body = $args['body'];
            $isHtml = isset($args['html']) ? (bool)$args['html'] : false;

            // Set CC and BCC
            $cc = $args['cc'] ?? null;
            $bcc = $args['bcc'] ?? null;

            // Set attachment
            $attachment = $args['attachment'] ?? null;

            // Send email
            $result = $this->mailer->sendMail(
                $to,
                $toName,
                $subject,
                $body,
                $isHtml,
                $cc,
                $bcc,
                $attachment
            );

            if ($result) {
                $this->printer->success(__('Email sent successfully!'));
                $this->printer->note(__('Recipient: ') . $to);
                $this->printer->note(__('Subject: ') . $subject);
            } else {
                $this->printer->error(__('Email delivery failed!'));
            }
        } catch (\Exception $exception) {
            $this->printer->error(__('Email sending error: ') . $exception->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'Send professional business emails via SMTP. Example: php bin/w mail:send --to=user@example.com --subject="Subject" --body="Message" [--html=1] [--cc=cc@example.com] [--bcc=bcc@example.com] [--attachment=/path/to/file]';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'mail:send',
            $this->tip(),
            [
                '-h, --help' => 'Display help information',
                '--to' => 'Recipient email address (required)',
                '--to-name' => 'Recipient name (optional)',
                '--subject' => 'Email subject line (required)',
                '--body' => 'Email message body (required)',
                '--html' => 'Send as HTML format (optional, default: 0 for plain text)',
                '--cc' => 'CC addresses (optional, comma-separated)',
                '--bcc' => 'BCC addresses (optional, comma-separated)',
                '--attachment' => 'File path for attachment (optional)',
            ],
            [
                'php bin/w mail:send --to=client@example.com --subject="Business Proposal" --body="Please review the attached proposal"',
                'php bin/w mail:send --to=team@example.com --subject="Newsletter" --body="<h1>Welcome</h1><p>Latest updates...</p>" --html=1',
                'php bin/w mail:send --to=partner@example.com --subject="Contract" --body="Please find the contract attached" --attachment=/path/to/contract.pdf',
            ],
            []
        );
    }
}

