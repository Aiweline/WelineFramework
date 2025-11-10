<?php
declare(strict_types=1);

/**
 * GuoLaiRen SMTP Module
 * Email Testing Command - Validates SMTP configuration
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

class Test implements CommandInterface
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
            $this->printer->error(__('Test email address is required'));
            return;
        }

        try {
            $this->printer->note(__('Testing SMTP configuration...'));
            
            // Check if using configuration file
            $useConfig = isset($args['use-config']) && $args['use-config'];
            $accountId = $args['account'] ?? 1;
            
            if ($useConfig) {
                $this->testWithConfig($args['to'], (int)$accountId);
            } else {
                $this->testWithDefault($args['to']);
            }
            
        } catch (\Exception $exception) {
            $this->printer->error(__('Test failed: ') . $exception->getMessage());
            $this->printer->note(__('Please verify the following:'));
            $this->printer->note(__('1. SMTP server address and port'));
            $this->printer->note(__('2. Account credentials'));
            $this->printer->note(__('3. Encryption method (TLS/SSL)'));
        }
    }

    /**
     * Test with configuration file account
     */
    private function testWithConfig(string $to, int $accountId): void
    {
        $configFile = __DIR__ . '/smtps.json';
        
        if (!file_exists($configFile)) {
            throw new \Exception('SMTP configuration file not found: smtps.json');
        }

        $json = file_get_contents($configFile);
        $config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid SMTP configuration file: ' . json_last_error_msg());
        }

        $account = null;
        foreach ($config['accounts'] ?? [] as $acc) {
            if ($acc['id'] == $accountId) {
                $account = $acc;
                break;
            }
        }

        if (!$account) {
            throw new \Exception("Account with ID {$accountId} not found");
        }

        if (!($account['enabled'] ?? true)) {
            throw new \Exception("Account {$accountId} is disabled");
        }

        $this->printer->note('Using account: ' . $account['name'] . ' (' . $account['username'] . ')');

        // Create mailer instance
        $mailer = $this->createMailerFromConfig($account);
        
        $subject = '[TEST] Important Business Update from Stock Circle';
        $body = $this->getTestEmailBody($account);

        $result = $mailer->sendMail($to, '', $subject, $body, true);

        if ($result) {
            $this->printer->success(__('Test email sent successfully!'));
            $this->printer->note('Sending account: ' . $account['username']);
            $this->printer->note('Check inbox: ' . $to);
            $this->printer->note(__('If not received, please check spam folder.'));
        } else {
            $this->printer->error(__('Test email failed!'));
            $this->printer->note('Please verify account ' . $account['username'] . ' SMTP settings.');
        }
    }

    /**
     * Test with default configuration
     */
    private function testWithDefault(string $to): void
    {
        $subject = '[TEST] Important Business Update from Stock Circle';
        $body = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
        $body .= '<div style="background: #f0f0f0; padding: 10px; margin-bottom: 20px; border-left: 4px solid #ff6600;">';
        $body .= '<p style="margin: 0; color: #ff6600; font-weight: bold;">⚠️ This is a test email</p>';
        $body .= '</div>';
        $body .= '<h2 style="color: #333;">Market Analysis Update</h2>';
        $body .= '<p>Dear Valued Client,</p>';
        $body .= '<p>We are pleased to share our latest market insights and investment opportunities.</p>';
        $body .= '<h3 style="color: #555;">Key Highlights:</h3>';
        $body .= '<ul>';
        $body .= '<li>Q4 2024 market performance review</li>';
        $body .= '<li>Emerging sector opportunities</li>';
        $body .= '<li>Portfolio optimization strategies</li>';
        $body .= '</ul>';
        $body .= '<p>For detailed analysis and personalized recommendations, please contact your account manager.</p>';
        $body .= '<p style="margin-top: 30px;">Best regards,<br><strong>Stock Circle Team</strong></p>';
        $body .= '<hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">';
        $body .= '<p style="font-size: 12px; color: #999;">Sent at: ' . date('Y-m-d H:i:s') . '</p>';
        $body .= '</div>';

        $result = $this->mailer->sendMail($to, '', $subject, $body, true);

        if ($result) {
            $this->printer->success(__('Test email sent successfully!'));
            $this->printer->note('Check inbox: ' . $to);
            $this->printer->note(__('If not received, please check spam folder.'));
        } else {
            $this->printer->error(__('Test email failed!'));
            $this->printer->note(__('Please verify your SMTP configuration.'));
        }
    }

    /**
     * Create mailer from account configuration
     */
    private function createMailerFromConfig(array $account): SmtpMailer
    {
        return new SmtpMailer($account);
    }

    /**
     * Get test email body content
     */
    private function getTestEmailBody(array $account): string
    {
        $body = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
        
        // Test notification banner
        $body .= '<div style="background: #f0f0f0; padding: 10px; margin-bottom: 20px; border-left: 4px solid #ff6600;">';
        $body .= '<p style="margin: 0; color: #ff6600; font-weight: bold;">⚠️ This is a test email from ' . htmlspecialchars($account['name']) . '</p>';
        $body .= '</div>';
        
        // Professional business email content
        $body .= '<h2 style="color: #333;">Stock Market Insights & Investment Opportunities</h2>';
        $body .= '<p>Dear Valued Investor,</p>';
        $body .= '<p>We hope this message finds you well. We are writing to share important updates about your investment portfolio and market opportunities.</p>';
        
        $body .= '<h3 style="color: #555;">Market Overview:</h3>';
        $body .= '<ul style="line-height: 1.8;">';
        $body .= '<li><strong>Technology Sector:</strong> Strong growth momentum continues with AI and cloud computing leading the way</li>';
        $body .= '<li><strong>Healthcare:</strong> Biotech innovations showing promising returns for long-term investors</li>';
        $body .= '<li><strong>Green Energy:</strong> Sustainable investment opportunities with government support</li>';
        $body .= '</ul>';
        
        $body .= '<h3 style="color: #555;">Recommended Actions:</h3>';
        $body .= '<p>Based on current market conditions, we suggest:</p>';
        $body .= '<ol style="line-height: 1.8;">';
        $body .= '<li>Review your portfolio allocation across different sectors</li>';
        $body .= '<li>Consider diversification opportunities in emerging markets</li>';
        $body .= '<li>Schedule a consultation with your account manager</li>';
        $body .= '</ol>';
        
        $body .= '<div style="background: #e8f5e9; padding: 15px; margin: 20px 0; border-radius: 5px;">';
        $body .= '<p style="margin: 0;"><strong>📞 Need Assistance?</strong></p>';
        $body .= '<p style="margin: 5px 0 0 0;">Contact your dedicated account manager for personalized investment advice.</p>';
        $body .= '</div>';
        
        $body .= '<p style="margin-top: 30px;">We look forward to helping you achieve your financial goals.</p>';
        $body .= '<p>Best regards,<br><strong>Stock Circle Investment Team</strong><br>';
        $body .= htmlspecialchars($account['from_name']) . '</p>';
        
        // Footer with technical details (subtle)
        $body .= '<hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">';
        $body .= '<p style="font-size: 11px; color: #999;">';
        $body .= 'Sent via: ' . htmlspecialchars($account['username']) . ' | ';
        $body .= 'Server: ' . htmlspecialchars($account['host']) . ' | ';
        $body .= 'Date: ' . date('Y-m-d H:i:s');
        $body .= '</p>';
        
        $body .= '</div>';
        
        return $body;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'Test SMTP configuration. Example: php bin/w mail:test --to=your-email@example.com';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'mail:test',
            $this->tip(),
            [
                '-h, --help' => 'Display help information',
                '--to' => 'Test email address (required)',
                '--use-config' => 'Use smtps.json configuration file (optional)',
                '--account' => 'Specify account ID (default: 1 when using config)',
            ],
            [
                'php bin/w mail:test --to=your-email@example.com',
                'php bin/w mail:test --to=your-email@example.com --use-config=1',
                'php bin/w mail:test --to=your-email@example.com --use-config=1 --account=2',
            ],
            []
        );
    }
}

