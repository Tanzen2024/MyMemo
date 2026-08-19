<?php

use App\Controllers\MyMemoUsersController;
use App\Services\AuditLoggerService;
use App\Services\MyMemoAuthorizationService;
use App\Services\MyMemoUserRepository;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Couvre la gestion des utilisateurs (Administration > Utilisateurs MyMemo) :
 * validation serveur des rôles (§19/§21), anti-auto-élévation, protection du
 * dernier administrateur en bout de chaîne CRUD. L'autorisation par le
 * filtre de route 'auditadmin' est déjà couverte, inchangée, par
 * MyMemoAuthorizationTest.php — ce test appelle directement le contrôleur
 * (repository pointé sur un répertoire temporaire, jamais le vrai
 * writable/security/users.csv).
 */
final class MyMemoUsersControllerTest extends CIUnitTestCase
{
    private string $directory;
    private MyMemoUserRepository $repository;
    private MyMemoUsersController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-users-ctrl-' . uniqid();
        mkdir($this->directory, 0750, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'users.csv', "username,roles,enabled,display_name\n");

        $this->repository = new MyMemoUserRepository($this->directory);
        $this->controller = new MyMemoUsersController(
            $this->repository,
            new MyMemoAuthorizationService($this->repository),
            new AuditLoggerService()
        );
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

    private function callWithPost(array $post, string $method, ...$args)
    {
        $_POST = $post;
        $request = service('request', null, false);
        $this->controller->initController($request, service('response'), service('logger'));

        return $this->controller->{$method}(...$args);
    }

    // TEST : création — utilisateur autorisé avec succès.
    public function testStoreCreatesAuthorizedUser(): void
    {
        $this->callWithPost([
            'username' => 'jean.dupont',
            'roles' => ['USER'],
            'enabled' => '1',
            'display_name' => 'Jean DUPONT',
        ], 'store');

        $this->assertTrue($this->repository->exists('jean.dupont'));
    }

    // TEST : création — rôle hors liste blanche refusé, même envoyé explicitement par le client.
    public function testStoreRejectsUnknownRole(): void
    {
        $this->callWithPost([
            'username' => 'jean.dupont',
            'roles' => ['SUPERADMIN'],
            'enabled' => '1',
            'display_name' => 'Jean DUPONT',
        ], 'store');

        $this->assertFalse($this->repository->exists('jean.dupont'));
    }

    // TEST : création — identifiant AD au format invalide refusé.
    public function testStoreRejectsInvalidUsername(): void
    {
        $this->callWithPost([
            'username' => 'jean dupont !!',
            'roles' => ['USER'],
            'enabled' => '1',
            'display_name' => 'x',
        ], 'store');

        $this->assertFalse($this->repository->exists('jean dupont !!'));
    }

    // TEST : création — doublon refusé.
    public function testStoreRejectsDuplicateUsername(): void
    {
        $this->repository->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $this->callWithPost([
            'username' => 'jean.dupont',
            'roles' => ['ADMIN'],
            'enabled' => '1',
            'display_name' => 'Doublon',
        ], 'store');

        $this->assertSame(['USER'], $this->repository->findByUsername('jean.dupont')['roles']);
    }

    // TEST : mise à jour — rôles et statut appliqués.
    public function testUpdateAppliesRoleAndStatusChanges(): void
    {
        $this->repository->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $this->callWithPost([
            'roles' => ['USER', 'ADMIN'],
            'enabled' => '1',
            'display_name' => 'Jean DUPONT',
        ], 'update', 'jean.dupont');

        $updated = $this->repository->findByUsername('jean.dupont');
        $this->assertSame(['USER', 'ADMIN'], $updated['roles']);
    }

    // TEST : le dernier ADMIN ne peut pas se retirer lui-même son rôle via update().
    public function testLastAdminCannotSelfRevokeThroughUpdate(): void
    {
        $this->repository->add(['username' => 'hugues.nwameh', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Hugues NWAMEH']);

        $this->callWithPost([
            'roles' => ['USER'],
            'enabled' => '1',
            'display_name' => 'Hugues NWAMEH',
        ], 'update', 'hugues.nwameh');

        $this->assertSame(['ADMIN'], $this->repository->findByUsername('hugues.nwameh')['roles']);
    }

    // TEST : le dernier ADMIN ne peut pas être désactivé.
    public function testLastAdminCannotBeDisabledThroughController(): void
    {
        $this->repository->add(['username' => 'hugues.nwameh', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Hugues NWAMEH']);

        $this->callWithPost([], 'disable', 'hugues.nwameh');

        $this->assertTrue($this->repository->findByUsername('hugues.nwameh')['enabled']);
    }

    // TEST : le dernier ADMIN ne peut pas être supprimé.
    public function testLastAdminCannotBeDeletedThroughController(): void
    {
        $this->repository->add(['username' => 'hugues.nwameh', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Hugues NWAMEH']);

        $this->callWithPost([], 'delete', 'hugues.nwameh');

        $this->assertTrue($this->repository->exists('hugues.nwameh'));
    }

    // TEST : désactivation normale (pas le dernier admin) fonctionne.
    public function testDisableWorksWhenNotLastAdmin(): void
    {
        $this->repository->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $this->callWithPost([], 'disable', 'jean.dupont');

        $this->assertFalse($this->repository->findByUsername('jean.dupont')['enabled']);
    }

    // TEST : suppression normale (pas le dernier admin) fonctionne.
    public function testDeleteWorksWhenNotLastAdmin(): void
    {
        $this->repository->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $this->callWithPost([], 'delete', 'jean.dupont');

        $this->assertFalse($this->repository->exists('jean.dupont'));
    }

    // TEST : users.csv inaccessible → message d'erreur clair, pas de page en erreur 500.
    public function testIndexShowsClearErrorWhenUsersCsvIsUnreadable(): void
    {
        $unreadableDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-users-ctrl-unreadable-' . uniqid();
        mkdir($unreadableDir, 0750, true);
        mkdir($unreadableDir . DIRECTORY_SEPARATOR . 'users.csv');

        $repository = new MyMemoUserRepository($unreadableDir);
        $controller = new MyMemoUsersController($repository, new MyMemoAuthorizationService($repository), new AuditLoggerService());

        $_POST = [];
        $controller->initController(service('request', null, false), service('response'), service('logger'));
        $html = (string) $controller->index();

        $this->assertStringContainsString('Impossible de lire la liste des utilisateurs', $html);

        foreach (glob($unreadableDir . DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
        rmdir($unreadableDir);
    }
}
