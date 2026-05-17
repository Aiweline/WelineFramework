<?php

declare(strict_types=1);

namespace WeShop\Customer\Extends\Module\Weline_FakeData\Provider;

use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Service\CustomerProfileService;
use Weline\Acl\Model\Role;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;

class CustomerProvider implements FakeDataProviderInterface
{
    private const CODE = 'weshop_customer';
    private const ENTITY_FRONTEND_CUSTOMER = 'frontend_customer';
    private const ENTITY_BACKEND_USER = 'backend_user';
    private const LOGIN = 'weline';
    private const PASSWORD = 'weline';
    private const BACKEND_EMAIL = 'weline@weline.local';
    private const AVATAR = 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=300&q=80';

    public function __construct(
        private readonly AuthCustomer $authCustomer,
        private readonly CustomerProfileService $customerProfileService,
        private readonly CustomerProfile $customerProfile,
        private readonly BackendUser $backendUser,
        private readonly Role $role,
        private readonly UserRole $userRole,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getModuleName(): string
    {
        return 'WeShop_Customer';
    }

    public function getLabel(): string
    {
        return 'WeShop demo customer and admin users';
    }

    public function getSortOrder(): int
    {
        return 300;
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function describe(): array
    {
        return [
            'entities' => [self::ENTITY_FRONTEND_CUSTOMER, self::ENTITY_BACKEND_USER],
            'accounts' => [
                'frontend' => self::LOGIN . '/' . self::PASSWORD,
                'backend' => self::LOGIN . '/' . self::PASSWORD,
            ],
        ];
    }

    public function seed(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();

        $frontendCreated = !$this->findFrontendCustomerByLogin(self::LOGIN);
        $frontend = $this->upsertFrontendCustomer();
        $context->record(
            self::CODE,
            self::ENTITY_FRONTEND_CUSTOMER,
            (int)$frontend->getId(),
            'frontend_customer:' . self::LOGIN,
            ['username' => self::LOGIN]
        );
        $frontendCreated ? $result->addCreated() : $result->addUpdated();

        $backendCreated = !$this->findBackendUserByLogin(self::LOGIN);
        $backend = $this->upsertBackendUser();
        $context->record(
            self::CODE,
            self::ENTITY_BACKEND_USER,
            (int)$backend->getId(),
            'backend_user:' . self::LOGIN,
            ['username' => self::LOGIN, 'role_id' => 1]
        );
        $backendCreated ? $result->addCreated() : $result->addUpdated();

        return $result;
    }

    public function cleanup(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $records = $context->getRecordService()->getRecords(self::CODE);

        foreach ($records as $record) {
            $entityType = (string)($record['entity_type'] ?? '');
            $entityId = (int)($record['entity_id'] ?? 0);
            $stableKey = (string)($record['stable_key'] ?? '');

            if ($entityType === self::ENTITY_FRONTEND_CUSTOMER && $entityId > 0) {
                $this->customerProfile->clear()
                    ->getQuery()
                    ->where(CustomerProfile::schema_fields_ID, $entityId)
                    ->delete()
                    ->fetch();
                $this->authCustomer->clear()
                    ->getQuery()
                    ->where(AuthCustomer::schema_fields_ID, $entityId)
                    ->delete()
                    ->fetch();
                $result->addDeleted();
            }

            if ($entityType === self::ENTITY_BACKEND_USER && $entityId > 0) {
                $this->userRole->clear()
                    ->getQuery()
                    ->where(UserRole::schema_fields_USER_ID, $entityId)
                    ->delete()
                    ->fetch();
                $this->backendUser->clear()
                    ->getQuery()
                    ->where(BackendUser::schema_fields_ID, $entityId)
                    ->delete()
                    ->fetch();
                $result->addDeleted();
            }

            if ($stableKey !== '') {
                $context->getRecordService()->removeRecord(self::CODE, $stableKey);
            }
        }

        return $result;
    }

    private function upsertFrontendCustomer(): AuthCustomer
    {
        $customer = $this->findFrontendCustomerByLogin(self::LOGIN)
            ?? $this->authCustomer->reset()->clearData();

        $customer->setData(AuthCustomer::schema_fields_username, self::LOGIN)
            ->setEmail(self::LOGIN)
            ->setPassword(self::PASSWORD)
            ->setAvatar(self::AVATAR)
            ->setSandboxAccount(true)
            ->setData(AuthCustomer::schema_fields_attempt_times, 0)
            ->setData(AuthCustomer::schema_fields_attempt_ip, null)
            ->setData(AuthCustomer::schema_fields_sess_id, null)
            ->save();

        $customer = $this->findFrontendCustomerByLogin(self::LOGIN) ?? $customer;
        $this->customerProfileService->getOrCreateByAuthUser($customer, [
            'email' => self::LOGIN,
            'first_name' => 'WeShop',
            'last_name' => 'Demo',
            'phone' => '18800000000',
            'avatar' => self::AVATAR,
            'status' => 'active',
        ]);

        return $customer;
    }

    private function upsertBackendUser(): BackendUser
    {
        $user = $this->findBackendUserByLogin(self::LOGIN)
            ?? $this->backendUser->reset()->clearData();

        $user->setUsername(self::LOGIN)
            ->setEmail(self::BACKEND_EMAIL)
            ->setPassword(self::PASSWORD)
            ->setAvatar(self::AVATAR)
            ->setIsEnabled(true)
            ->setIsDeleted(false)
            ->setSandboxAccount(true)
            ->setData(BackendUser::schema_fields_attempt_times, 0)
            ->setData(BackendUser::schema_fields_attempt_ip, null)
            ->setData(BackendUser::schema_fields_sess_id, null)
            ->save();

        $user = $this->findBackendUserByLogin(self::LOGIN) ?? $user;
        $this->ensureRoleOne();
        $this->ensureBackendRole((int)$user->getId(), 1);

        return $user;
    }

    private function findFrontendCustomerByLogin(string $login): ?AuthCustomer
    {
        $customer = $this->authCustomer->reset()
            ->where(AuthCustomer::schema_fields_username, $login, '=', 'or')
            ->where(AuthCustomer::schema_fields_email, $login)
            ->find()
            ->fetch();

        return $customer->getId() ? $customer : null;
    }

    private function findBackendUserByLogin(string $login): ?BackendUser
    {
        $user = $this->backendUser->reset()
            ->where(BackendUser::schema_fields_username, $login, '=', 'or')
            ->where(BackendUser::schema_fields_email, self::BACKEND_EMAIL)
            ->find()
            ->fetch();

        return $user->getId() ? $user : null;
    }

    private function ensureRoleOne(): void
    {
        $role = $this->role->clear()->load(1);
        if ($role->getId()) {
            return;
        }

        $this->role->clear()
            ->setData(Role::schema_fields_ID, 1)
            ->setRoleName('Super Admin')
            ->setRoleDescription('System built-in super admin role')
            ->save();
    }

    private function ensureBackendRole(int $userId, int $roleId): void
    {
        if ($userId <= 0 || $roleId <= 0) {
            return;
        }

        $existing = $this->userRole->reset()
            ->where(UserRole::schema_fields_USER_ID, $userId)
            ->where(UserRole::schema_fields_ROLE_ID, $roleId)
            ->find()
            ->fetch();
        if ($existing->getUserId() && $existing->getRoleId()) {
            return;
        }

        $this->userRole->clearData()
            ->setUserId($userId)
            ->setRoleId($roleId)
            ->save(true);
    }
}
