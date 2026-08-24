<?php

declare(strict_types=1);

namespace Weline\Bt_Center\Service;

use Weline\Bt_Center\Model\BtServer;

class DefaultBtServerSeeder
{
    public function __construct(
        private readonly BtServer $btServerModel
    ) {
    }

    public function seed(): void
    {
        foreach ($this->getDefaultServers() as $serverData) {
            $server = $this->findExistingServer($serverData);

            if (!$server->getId()) {
                $server->clearData();
                $server->setData(BtServer::schema_fields_IS_ENABLED, 1);
            }

            $server->setData(BtServer::schema_fields_NAME, $serverData[BtServer::schema_fields_NAME])
                ->setData(BtServer::schema_fields_PLATFORM, $serverData[BtServer::schema_fields_PLATFORM])
                ->setData(BtServer::schema_fields_EXTERNAL_URL, $serverData[BtServer::schema_fields_EXTERNAL_URL])
                ->setData(BtServer::schema_fields_USERNAME, $serverData[BtServer::schema_fields_USERNAME])
                ->setData(BtServer::schema_fields_PASSWORD, $serverData[BtServer::schema_fields_PASSWORD])
                ->setData(BtServer::schema_fields_PORT, $serverData[BtServer::schema_fields_PORT]);

            if (!$server->getData(BtServer::schema_fields_INTERNAL_URL)) {
                $server->setData(BtServer::schema_fields_INTERNAL_URL, (string) ($serverData[BtServer::schema_fields_INTERNAL_URL] ?? ''));
            }

            if (!$server->getData(BtServer::schema_fields_DESCRIPTION)) {
                $server->setData(BtServer::schema_fields_DESCRIPTION, $serverData[BtServer::schema_fields_DESCRIPTION]);
            }

            $server->save();
        }
    }

    private function findExistingServer(array $serverData): BtServer
    {
        $server = clone $this->btServerModel;
        $existing = $server->clearQuery()
            ->where(BtServer::schema_fields_EXTERNAL_URL, $serverData[BtServer::schema_fields_EXTERNAL_URL])
            ->select()
            ->fetch();

        if ($existing && $existing->getId()) {
            return $existing;
        }

        $existing = clone $this->btServerModel;
        $existing->clearQuery()
            ->where(BtServer::schema_fields_NAME, $serverData[BtServer::schema_fields_NAME])
            ->select()
            ->fetch();

        return $existing;
    }

    private function getDefaultServers(): array
    {
        $username = 'iqdlzpys';
        $password = '835378d4';

        return [
            [
                BtServer::schema_fields_NAME => '服务器1',
                BtServer::schema_fields_PLATFORM => BtServer::PLATFORM_AWS,
                BtServer::schema_fields_EXTERNAL_URL => 'https://3.7.199.83:22248/f9a64fa7',
                BtServer::schema_fields_INTERNAL_URL => '',
                BtServer::schema_fields_USERNAME => $username,
                BtServer::schema_fields_PASSWORD => $password,
                BtServer::schema_fields_PORT => 22248,
                BtServer::schema_fields_DESCRIPTION => '默认导入的 BT 面板服务器',
            ],
            [
                BtServer::schema_fields_NAME => '服务器2',
                BtServer::schema_fields_PLATFORM => BtServer::PLATFORM_AWS,
                BtServer::schema_fields_EXTERNAL_URL => 'https://13.203.163.19:22248/f9a64fa7',
                BtServer::schema_fields_INTERNAL_URL => '',
                BtServer::schema_fields_USERNAME => $username,
                BtServer::schema_fields_PASSWORD => $password,
                BtServer::schema_fields_PORT => 22248,
                BtServer::schema_fields_DESCRIPTION => '默认导入的 BT 面板服务器',
            ],
            [
                BtServer::schema_fields_NAME => '服务器3',
                BtServer::schema_fields_PLATFORM => BtServer::PLATFORM_AWS,
                BtServer::schema_fields_EXTERNAL_URL => 'https://13.234.229.115:22248/f9a64fa7',
                BtServer::schema_fields_INTERNAL_URL => '',
                BtServer::schema_fields_USERNAME => $username,
                BtServer::schema_fields_PASSWORD => $password,
                BtServer::schema_fields_PORT => 22248,
                BtServer::schema_fields_DESCRIPTION => '默认导入的 BT 面板服务器',
            ],
            [
                BtServer::schema_fields_NAME => '服务器4-Leo',
                BtServer::schema_fields_PLATFORM => BtServer::PLATFORM_AWS,
                BtServer::schema_fields_EXTERNAL_URL => 'https://13.203.120.70:22248/f9a64fa7',
                BtServer::schema_fields_INTERNAL_URL => '',
                BtServer::schema_fields_USERNAME => $username,
                BtServer::schema_fields_PASSWORD => $password,
                BtServer::schema_fields_PORT => 22248,
                BtServer::schema_fields_DESCRIPTION => '默认导入的 Leo BT 面板服务器',
            ],
            [
                BtServer::schema_fields_NAME => '服务器5-Leo',
                BtServer::schema_fields_PLATFORM => BtServer::PLATFORM_AWS,
                BtServer::schema_fields_EXTERNAL_URL => 'https://13.235.153.67:22248/f9a64fa7',
                BtServer::schema_fields_INTERNAL_URL => '',
                BtServer::schema_fields_USERNAME => $username,
                BtServer::schema_fields_PASSWORD => $password,
                BtServer::schema_fields_PORT => 22248,
                BtServer::schema_fields_DESCRIPTION => '默认导入的 Leo BT 面板服务器',
            ],
            [
                BtServer::schema_fields_NAME => '服务器6 Saas',
                BtServer::schema_fields_PLATFORM => BtServer::PLATFORM_AWS,
                BtServer::schema_fields_EXTERNAL_URL => 'https://43.205.103.113:22248/f9a64fa7',
                BtServer::schema_fields_INTERNAL_URL => '',
                BtServer::schema_fields_USERNAME => $username,
                BtServer::schema_fields_PASSWORD => $password,
                BtServer::schema_fields_PORT => 22248,
                BtServer::schema_fields_DESCRIPTION => '默认导入的 Saas BT 面板服务器',
            ],
        ];
    }
}
