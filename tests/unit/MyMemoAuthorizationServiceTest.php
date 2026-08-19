<?php

use App\Services\MyMemoAuthorizationService;
use App\Services\MyMemoUserRepository;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Couvre la matrice d'autorisation basée sur writable/security/users.csv :
 * absent, désactivé, USER, ADMIN, rôles multiples, rôles inconnus, rôles
 * vides, protection du dernier administrateur.
 */
final class MyMemoAuthorizationServiceTest extends CIUnitTestCase
{
    private string $directory;
    private MyMemoUserRepository $repository;
    private MyMemoAuthorizationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-authz-' . uniqid();
        mkdir($this->directory, 0750, true);
        // Fichier avec en-tête seul : évite l'ADMIN d'amorçage automatique dans ces tests.
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'users.csv', "username,roles,enabled,display_name\n");

        $this->repository = new MyMemoUserRepository($this->directory);
        $this->service = new MyMemoAuthorizationService($this->repository);
    }

    protected function tearDown(): void
    {
        foreach ((glob($this->directory . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*') ?: []) as $f) {
            unlink($f);
        }
        @rmdir($this->directory . DIRECTORY_SEPARATOR . 'backups');
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    // TEST : absent du CSV → refusé.
    public function testUnknownUserIsDenied(): void
    {
        $this->assertFalse($this->service->isAuthorized('inconnu'));
        $this->assertFalse($this->service->resolveSession('inconnu')['is_mymemo_user']);
    }

    // TEST : présent mais enabled=0 → refusé.
    public function testDisabledUserIsDenied(): void
    {
        $this->repository->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => false, 'display_name' => 'Jean DUPONT']);
        $this->assertFalse($this->service->isAuthorized('jean.dupont'));
    }

    // TEST : présent, actif, sans rôle → refusé (deny-by-default).
    public function testEnabledWithoutRolesIsDenied(): void
    {
        $this->repository->add(['username' => 'jean.dupont', 'roles' => [], 'enabled' => true, 'display_name' => 'Jean DUPONT']);
        $this->assertFalse($this->service->isAuthorized('jean.dupont'));
    }

    // TEST : USER → accès fonctionnel, pas d'administration.
    public function testUserRoleGrantsMymemoAccessOnly(): void
    {
        $this->repository->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $this->assertTrue($this->service->can('jean.dupont', MyMemoAuthorizationService::CAPABILITY_MYMEMO_ACCESS));
        $this->assertFalse($this->service->can('jean.dupont', MyMemoAuthorizationService::CAPABILITY_MANAGE_USERS));
        $this->assertFalse($this->service->can('jean.dupont', MyMemoAuthorizationService::CAPABILITY_VIEW_AUDIT));
    }

    // TEST : ADMIN → accès fonctionnel + administration + gestion utilisateurs + audit.
    public function testAdminRoleGrantsEveryCapability(): void
    {
        $this->repository->add(['username' => 'hugues.nwameh', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Hugues NWAMEH']);

        $this->assertTrue($this->service->can('hugues.nwameh', MyMemoAuthorizationService::CAPABILITY_MYMEMO_ACCESS));
        $this->assertTrue($this->service->can('hugues.nwameh', MyMemoAuthorizationService::CAPABILITY_ADMINISTRATION_ACCESS));
        $this->assertTrue($this->service->can('hugues.nwameh', MyMemoAuthorizationService::CAPABILITY_MANAGE_USERS));
        $this->assertTrue($this->service->can('hugues.nwameh', MyMemoAuthorizationService::CAPABILITY_VIEW_AUDIT));
    }

    // TEST : rôles multiples → union des permissions.
    public function testMultipleRolesUnionOfCapabilities(): void
    {
        $this->repository->add(['username' => 'paul.martin', 'roles' => ['USER', 'ADMIN'], 'enabled' => true, 'display_name' => 'Paul MARTIN']);

        $this->assertTrue($this->service->hasRole('paul.martin', 'USER'));
        $this->assertTrue($this->service->hasRole('paul.martin', 'ADMIN'));
        $this->assertTrue($this->service->hasAllRoles('paul.martin', ['USER', 'ADMIN']));
    }

    // TEST : normalisation — casse et espaces n'affectent pas les vérifications.
    public function testAuthorizationChecksAreCaseAndWhitespaceInsensitive(): void
    {
        $this->repository->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);
        $this->assertTrue($this->service->isAuthorized('  Jean.DUPONT '));
    }

    // TEST : rôle inconnu rejeté par isKnownRole().
    public function testUnknownRoleIsRejected(): void
    {
        $this->assertFalse($this->service->isKnownRole('SUPERADMIN'));
        $this->assertTrue($this->service->isKnownRole('ADMIN'));
    }

    // TEST : protection du dernier administrateur — désactivation refusée.
    public function testLastAdminCannotBeDisabled(): void
    {
        $this->repository->add(['username' => 'hugues.nwameh', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Hugues NWAMEH']);

        $this->expectException(\DomainException::class);
        $this->repository->update('hugues.nwameh', ['enabled' => false], $this->service->lastAdminGuard());
    }

    // TEST : protection du dernier administrateur — révocation du rôle ADMIN refusée.
    public function testLastAdminRoleCannotBeRevoked(): void
    {
        $this->repository->add(['username' => 'hugues.nwameh', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Hugues NWAMEH']);

        $this->expectException(\DomainException::class);
        $this->repository->update('hugues.nwameh', ['roles' => ['USER']], $this->service->lastAdminGuard());
    }

    // TEST : protection du dernier administrateur — suppression refusée.
    public function testLastAdminCannotBeDeleted(): void
    {
        $this->repository->add(['username' => 'hugues.nwameh', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Hugues NWAMEH']);

        $this->expectException(\DomainException::class);
        $this->repository->remove('hugues.nwameh', $this->service->lastAdminGuard());
    }

    // TEST : avec un second ADMIN actif, la désactivation du premier est autorisée.
    public function testDisablingOneAdminIsAllowedWhenAnotherRemains(): void
    {
        $this->repository->add(['username' => 'hugues.nwameh', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Hugues NWAMEH']);
        $this->repository->add(['username' => 'paul.martin', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Paul MARTIN']);

        $result = $this->repository->update('hugues.nwameh', ['enabled' => false], $this->service->lastAdminGuard());

        $this->assertTrue($result);
        $this->assertFalse($this->service->isAuthorized('hugues.nwameh'));
        $this->assertTrue($this->service->isAuthorized('paul.martin'));
    }
}
