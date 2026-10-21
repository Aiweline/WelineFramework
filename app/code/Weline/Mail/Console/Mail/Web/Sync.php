<?php
declare(strict_types=1);

namespace Weline\Mail\Console\Mail\Web;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailDomain;
use Weline\Mail\Service\MailWebVhostService;

class Sync extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        $model = ObjectManager::getInstance(MailDomain::class)->clear()->select()->fetch();
        $items = $model->getItems();
        $ready = 0;
        $needs = 0;
        $errors = 0;

        foreach ($items as $item) {
            $host = strtolower(trim((string) $item->getData(MailDomain::schema_fields_HOSTNAME)));
            if (!str_starts_with($host, 'mail.')) {
                $host = 'mail.' . strtolower(trim((string) $item->getData(MailDomain::schema_fields_DOMAIN_NAME)));
            }
            if ($host === 'mail.' || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                continue;
            }

            $result = ObjectManager::getInstance(MailWebVhostService::class)->ensureForHostname($host);
            $status = $result['status'] ?? 'error';
            if (in_array($status, ['ready', 'unchanged'], true)) {
                ++$ready;
            } elseif ($status === 'needs_cert') {
                ++$needs;
            } else {
                ++$errors;
            }
            $this->printer->printing($host . ' => ' . $status);
        }

        $this->printer->success(__('mail web vhost: ready=%{1} needs_cert=%{2} errors=%{3}', [$ready, $needs, $errors]));
    }

    public function tip(): string
    {
        return __('同步邮箱域名 Web 反代 vhost');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'mail:web:sync',
            __('同步邮箱域名 Web 反代 vhost'),
            [],
            [],
            ['同步' => 'php bin/w mail:web:sync']
        );
    }
}
