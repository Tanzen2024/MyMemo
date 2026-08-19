<?php

use App\Services\MyMemoUserRepository;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Couvre les cas CSV de la consigne "autorisation writable/security/users.csv" :
 * amorçage, lecture, écriture atomique, verrouillage, doublons, malformation.
 */
final class MyMemoUserRepositoryTest extends CIUnitTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-users-' . uniqid();
        mkdir($this->directory, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->directory);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // TEST : fichier absent → créé automatiquement avec un ADMIN d'amorçage.
    public function testMissingFileIsBootstrappedWithSeedAdmin(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $users = $repo->readAll();

        $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . 'users.csv');
        $this->assertArrayHasKey('hugues.nwameh', $users);
        $this->assertTrue($users['hugues.nwameh']['enabled']);
        $this->assertSame(['ADMIN'], $users['hugues.nwameh']['roles']);
    }

    // TEST : fichier vide (header seul) → aucune entrée, pas d'erreur.
    public function testEmptyFileYieldsNoUsers(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'users.csv', "username,roles,enabled,display_name\n");

        $repo = new MyMemoUserRepository($this->directory);
        $this->assertSame([], $repo->readAll());
    }

    // TEST : ligne malformée (colonnes manquantes) ignorée sans planter la lecture.
    public function testMalformedRowIsSkipped(): void
    {
        file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . 'users.csv',
            "username,roles,enabled,display_name\nincomplete,ADMIN\njean.dupont,USER,1,Jean DUPONT\n"
        );

        $repo = new MyMemoUserRepository($this->directory);
        $users = $repo->readAll();

        $this->assertArrayNotHasKey('incomplete', $users);
        $this->assertArrayHasKey('jean.dupont', $users);
    }

    // TEST : rôles multiples séparés par | correctement lus et réécrits.
    public function testMultipleRolesRoundTrip(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $repo->add(['username' => 'paul.martin', 'roles' => ['USER', 'ADMIN'], 'enabled' => true, 'display_name' => 'Paul MARTIN']);

        $reread = new MyMemoUserRepository($this->directory);
        $user = $reread->findByUsername('paul.martin');

        $this->assertSame(['USER', 'ADMIN'], $user['roles']);
    }

    // TEST : normalisation — casse et espaces n'affectent pas la recherche.
    public function testLookupIsCaseAndWhitespaceInsensitive(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $repo->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $this->assertNotNull($repo->findByUsername('  Jean.DUPONT  '));
        $this->assertTrue($repo->exists('JEAN.DUPONT'));
    }

    // TEST : ajout d'un doublon refusé.
    public function testAddingDuplicateUsernameFails(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $repo->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $this->expectException(\InvalidArgumentException::class);
        $repo->add(['username' => 'jean.dupont', 'roles' => ['ADMIN'], 'enabled' => true, 'display_name' => 'Doublon']);
    }

    // TEST : update() sur un utilisateur inconnu échoue.
    public function testUpdatingUnknownUserFails(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $this->expectException(\DomainException::class);
        $repo->update('inconnu', ['enabled' => false]);
    }

    // TEST : écriture atomique — un fichier temporaire ne subsiste jamais après une écriture réussie.
    public function testWriteLeavesNoTemporaryFileBehind(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $repo->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $tmpFiles = glob($this->directory . DIRECTORY_SEPARATOR . '*.tmp-*') ?: [];
        $this->assertSame([], $tmpFiles);
    }

    // TEST : une sauvegarde horodatée est créée avant chaque écriture.
    public function testBackupIsCreatedBeforeWrite(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $repo->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $backups = glob($this->directory . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'users_*.csv') ?: [];
        $this->assertNotEmpty($backups);
    }

    // TEST : suppression retire bien l'utilisateur.
    public function testRemoveDeletesUser(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $repo->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);
        $repo->remove('jean.dupont');

        $this->assertFalse($repo->exists('jean.dupont'));
    }

    // TEST : garde optionnelle passée à update()/remove() peut refuser l'opération.
    public function testGuardCanRejectAnUpdate(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $repo->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);

        $guard = function () {
            throw new \DomainException('refus de test');
        };

        $this->expectException(\DomainException::class);
        $repo->update('jean.dupont', ['enabled' => false], $guard);
    }

    // TEST : identifiant invalide refusé.
    public function testInvalidUsernameFormatIsRejected(): void
    {
        $repo = new MyMemoUserRepository($this->directory);
        $this->expectException(\InvalidArgumentException::class);
        $repo->add(['username' => 'jean dupont!', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'x']);
    }
}
