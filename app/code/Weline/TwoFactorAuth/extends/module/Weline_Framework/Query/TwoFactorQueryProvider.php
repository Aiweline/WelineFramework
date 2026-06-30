<?php
declare(strict_types=1);

namespace Weline\TwoFactorAuth\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\TwoFactorAuth\Helper\TwoFactorAuthHelper;
use Weline\TwoFactorAuth\Model\TotpAccount;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

class TwoFactorQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly TwoFactorAuthService $twoFactorAuthService,
        private readonly SessionFactory $sessionFactory,
        private readonly TotpAccount $totpAccount
    ) {
    }

    public function getProviderName(): string
    {
        return 'twoFactor';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'enable' => $this->enable($params),
            'disable' => $this->disable($params),
            'regenerateBackupCodes' => $this->regenerateBackupCodes($params),
            'saveAccount' => $this->saveAccount($params),
            'importAccount' => $this->importAccount($params),
            'deleteAccount' => $this->deleteAccount($params),
            'getCode' => $this->getCode($params),
            default => throw new \InvalidArgumentException('Two factor query provider does not support operation: ' . $operation),
        };
    }

    private function enable(array $params): array
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->failure('Please login first.', 401);
        }

        $secret = trim((string)($params['secret'] ?? ''));
        $code = trim((string)($params['code'] ?? ''));
        $backupCodes = $params['backup_codes'] ?? [];
        if (\is_string($backupCodes)) {
            $decoded = json_decode($backupCodes, true);
            $backupCodes = \is_array($decoded) ? $decoded : [];
        }
        if ($secret === '' || $code === '') {
            return $this->failure('Secret and verification code are required.');
        }

        if (!$this->twoFactorAuthService->enable($userId, $secret, $code, \is_array($backupCodes) ? $backupCodes : [])) {
            return $this->failure('Invalid verification code.');
        }

        return $this->success('Two-factor authentication has been enabled.');
    }

    private function disable(array $params): array
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->failure('Please login first.', 401);
        }

        $code = trim((string)($params['code'] ?? ''));
        if ($code === '') {
            return $this->failure('Verification code is required.');
        }

        if (!$this->twoFactorAuthService->disable($userId, $code)) {
            return $this->failure('Invalid verification code or two-factor authentication is not enabled.');
        }

        return $this->success('Two-factor authentication has been disabled.');
    }

    private function regenerateBackupCodes(array $params): array
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->failure('Please login first.', 401);
        }

        $code = trim((string)($params['code'] ?? ''));
        if ($code === '') {
            return $this->failure('Verification code is required.');
        }

        $codes = $this->twoFactorAuthService->regenerateBackupCodes($userId, $code);
        if (!$codes) {
            return $this->failure('Invalid verification code.');
        }

        return $this->success('Backup codes have been regenerated.', ['backup_codes' => $codes]);
    }

    private function saveAccount(array $params): array
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->failure('Please login first.', 401);
        }

        $name = trim((string)($params['name'] ?? ''));
        $secret = trim((string)($params['secret'] ?? ''));
        $issuer = trim((string)($params['issuer'] ?? ''));
        $algorithm = $this->normalizeAlgorithm((string)($params['algorithm'] ?? 'SHA1'));
        $digits = (int)($params['digits'] ?? 6);
        $period = (int)($params['period'] ?? 30);

        if ($name === '' || $secret === '') {
            return $this->failure('Account name and secret are required.');
        }
        if (!TwoFactorAuthHelper::isValidBase32($secret)) {
            return $this->failure('Invalid secret format.');
        }
        if ($digits < 6 || $digits > 8 || $period < 10 || $period > 120) {
            return $this->failure('Invalid TOTP digits or period.');
        }

        $account = $this->totpAccount->reset()->addAccount(
            $userId,
            $name,
            $secret,
            $issuer !== '' ? $issuer : null,
            $algorithm,
            $digits,
            $period
        );

        if (!$account->getData('account_id')) {
            return $this->failure('Account save failed.');
        }

        return $this->success('Account saved successfully.', ['account' => $account->getData()]);
    }

    private function importAccount(array $params): array
    {
        $uri = trim((string)($params['uri'] ?? ''));
        if ($uri === '') {
            return $this->failure('OTP URI is required.');
        }

        $parsed = $this->parseOtpAuthUri($uri);
        if (!$parsed) {
            return $this->failure('Invalid OTP URI.');
        }

        return $this->saveAccount($parsed);
    }

    private function deleteAccount(array $params): array
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->failure('Please login first.', 401);
        }

        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return $this->failure('Account id is required.');
        }

        $success = $this->totpAccount->reset()->deleteAccount($accountId, $userId);
        return $success
            ? $this->success('Account deleted successfully.')
            : $this->failure('Account delete failed.');
    }

    private function getCode(array $params): array
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->failure('Please login first.', 401);
        }

        $accountId = (int)($params['account_id'] ?? 0);
        if ($accountId <= 0) {
            return $this->failure('Account id is required.');
        }

        $account = $this->totpAccount->reset()
            ->where('account_id', $accountId)
            ->where('user_id', $userId)
            ->find()
            ->fetch();
        if (!$account || !$account->getData('account_id')) {
            return $this->failure('Account does not exist.', 404);
        }

        $secret = (string)$account->getData('secret');
        $algorithm = $this->normalizeAlgorithm((string)($account->getData('algorithm') ?: 'SHA1'));
        $digits = (int)($account->getData('digits') ?: 6);
        $period = (int)($account->getData('period') ?: 30);
        $timestamp = time();
        $key = $this->base32Decode($secret);
        $timeStep = (int)floor($timestamp / $period);
        $timeBytes = pack('N*', 0, $timeStep);
        $hash = hash_hmac(strtolower($algorithm), $timeBytes, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncatedHash = substr($hash, $offset, 4);
        $value = unpack('N', $truncatedHash)[1] & 0x7FFFFFFF;
        $remaining = $period - ($timestamp % $period);

        return [
            'success' => true,
            'code' => 200,
            'message' => (string)__('Code data loaded.'),
            'msg' => (string)__('Code data loaded.'),
            'hash_value' => $value,
            'digits' => $digits,
            'offset' => $offset,
            'remaining' => $remaining,
            'period' => $period,
        ];
    }

    private function parseOtpAuthUri(string $uri): ?array
    {
        if (!str_starts_with($uri, 'otpauth://')) {
            return null;
        }

        $parsed = parse_url($uri);
        if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $label = urldecode(ltrim((string)($parsed['path'] ?? ''), '/'));
        $query = [];
        if (isset($parsed['query'])) {
            parse_str((string)$parsed['query'], $query);
        }

        $parts = explode(':', $label, 2);
        $name = trim((string)($parts[1] ?? $parts[0] ?? ''));
        $issuer = trim((string)($parts[1] ?? '') !== '' ? (string)$parts[0] : (string)($query['issuer'] ?? ''));

        return [
            'name' => $name,
            'issuer' => $issuer,
            'secret' => (string)($query['secret'] ?? ''),
            'algorithm' => $this->normalizeAlgorithm((string)($query['algorithm'] ?? 'SHA1')),
            'digits' => (int)($query['digits'] ?? 6),
            'period' => (int)($query['period'] ?? 30),
        ];
    }

    private function normalizeAlgorithm(string $algorithm): string
    {
        $algorithm = strtoupper(trim($algorithm));
        return in_array($algorithm, ['SHA1', 'SHA256', 'SHA512'], true) ? $algorithm : 'SHA1';
    }

    private function base32Decode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(str_replace([' ', '-', '='], '', $data));
        $binary = '';
        for ($i = 0, $length = strlen($data); $i < $length; $i++) {
            $pos = strpos($chars, $data[$i]);
            if ($pos === false) {
                continue;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $decoded .= chr(bindec($chunk));
        }

        return $decoded;
    }

    private function currentUserId(): int
    {
        $session = $this->sessionFactory->createFrontendSession();
        return (int)($session->getUserId() ?? 0);
    }

    private function success(string $message, array $data = []): array
    {
        return \array_merge([
            'success' => true,
            'code' => 200,
            'message' => (string)__($message),
            'msg' => (string)__($message),
        ], $data);
    }

    private function failure(string $message, int $code = 400): array
    {
        return [
            'success' => false,
            'code' => $code,
            'message' => (string)__($message),
            'msg' => (string)__($message),
        ];
    }

    public function getDescriptor(): array
    {
        $codeParam = ['type' => 'string', 'required' => true, 'max_length' => 12];

        return [
            'provider' => 'twoFactor',
            'name' => 'Frontend two-factor worker API',
            'description' => 'Two-factor account security operations for the current frontend user.',
            'module' => 'Weline_TwoFactorAuth',
            'operations' => [
                [
                    'name' => 'enable',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'secret' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                        'code' => $codeParam,
                        'backup_codes' => ['type' => 'mixed'],
                        'referer' => ['type' => 'string', 'max_length' => 2048],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Enable two-factor auth',
                ],
                [
                    'name' => 'disable',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'code' => $codeParam,
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Disable two-factor auth',
                ],
                [
                    'name' => 'regenerateBackupCodes',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'code' => $codeParam,
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Regenerate two-factor backup codes',
                ],
                [
                    'name' => 'saveAccount',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'name' => ['type' => 'string', 'required' => true, 'max_length' => 255],
                        'secret' => ['type' => 'string', 'required' => true, 'max_length' => 255],
                        'issuer' => ['type' => 'string', 'max_length' => 255],
                        'algorithm' => ['type' => 'string', 'max_length' => 20],
                        'digits' => ['type' => 'int', 'min' => 6, 'max' => 8],
                        'period' => ['type' => 'int', 'min' => 10, 'max' => 120],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Save authenticator account for current user',
                ],
                [
                    'name' => 'importAccount',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'uri' => ['type' => 'string', 'required' => true, 'max_length' => 2048],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Import authenticator account from OTP URI',
                ],
                [
                    'name' => 'deleteAccount',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'account_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Delete authenticator account for current user',
                ],
                [
                    'name' => 'getCode',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 3,
                    'cache_ttl' => 0,
                    'params' => [
                        'account_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load current authenticator code seed data',
                ],
            ],
        ];
    }
}
