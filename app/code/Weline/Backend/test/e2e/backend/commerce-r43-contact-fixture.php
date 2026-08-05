<?php

declare(strict_types=1);

use Weline\Backend\Model\Contact;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

function r43_contact_require_isolated_clone(): string
{
    if ((string)\getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new \RuntimeException('R4.3 contact fixture requires WELINE_E2E_ISOLATED_DB=1');
    }
    $env = require BP . 'app/etc/env.php';
    $type = \strtolower((string)($env['db']['master']['type'] ?? ''));
    if ($type !== 'pgsql') {
        throw new \RuntimeException('R4.3 contact fixture requires PostgreSQL, got: ' . $type);
    }
    $database = (string)($env['db']['master']['database'] ?? '');
    if (\preg_match('/^mig_clone_[a-z0-9_]+$/D', $database) !== 1) {
        throw new \RuntimeException('R4.3 contact fixture refuses non-clone database: ' . $database);
    }
    return $database;
}

/** @param array<string,mixed> $payload */
function r43_contact_output(array $payload): void
{
    echo \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

try {
    r43_contact_require_isolated_clone();
    $input = \json_decode((string)\file_get_contents('php://stdin'), true, 512, JSON_THROW_ON_ERROR);
    if (!\is_array($input)) {
        throw new \InvalidArgumentException('stdin must be a JSON object');
    }
    $action = (string)($input['action'] ?? '');
    $name = \trim((string)($input['name'] ?? ''));
    if ($name === '' || !\str_starts_with($name, 'R43 联系人 ')) {
        throw new \InvalidArgumentException('refusing to inspect or delete a non-R43 contact');
    }

    /** @var Contact $contacts */
    $contacts = ObjectManager::getInstance(Contact::class, [], false);
    if ($action === 'assert') {
        $rows = $contacts->clearQuery()
            ->where(Contact::schema_fields_contact_name, $name)
            ->select()
            ->fetchArray();
        r43_contact_output([
            'ok' => true,
            'action' => 'assert',
            'count' => \count($rows),
            'ids' => \array_values(\array_map(
                static fn (array $row): int => (int)($row[Contact::schema_fields_ID] ?? 0),
                $rows,
            )),
        ]);
        exit(0);
    }

    if ($action === 'cleanup') {
        $contacts->clearQuery()
            ->where(Contact::schema_fields_contact_name, $name)
            ->delete()
            ->fetch();
        r43_contact_output(['ok' => true, 'action' => 'cleanup']);
        exit(0);
    }

    throw new \InvalidArgumentException('unknown action: ' . $action);
} catch (\Throwable $error) {
    r43_contact_output(['ok' => false, 'error' => $error->getMessage()]);
    exit(1);
}
