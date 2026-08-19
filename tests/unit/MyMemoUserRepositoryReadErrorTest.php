<?php

use App\Services\MyMemoUserRepository;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * users.csv illisible (permissions, disque...) doit être une erreur explicite
 * — jamais une liste vide silencieuse (Partie H). Simulé de façon portable
 * (Windows inclus) en remplaçant users.csv par un RÉPERTOIRE de ce nom :
 * file_exists() reste vrai (ensureBootstrapped() ne recrée donc rien) mais
 * fopen(..., 'r') échoue, exactement comme un fichier sans droit de lecture.
 */
final class MyMemoUserRepositoryReadErrorTest extends CIUnitTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mymemo-unreadable-' . uniqid();
        mkdir($this->directory, 0750, true);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'users.csv'); // "fichier" illisible en lecture CSV
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

    // TEST : lecture d'un users.csv inaccessible → exception explicite, pas une liste vide silencieuse.
    public function testReadAllThrowsOnUnreadableFile(): void
    {
        $repo = new MyMemoUserRepository($this->directory);

        $this->expectException(\RuntimeException::class);
        $repo->readAll();
    }

    // TEST : une mutation sur un users.csv inaccessible échoue AUSSI bruyamment
    // — sinon mutate() écrirait silencieusement un fichier vide par-dessus des
    // données réelles temporairement illisibles (perte de données).
    public function testMutationThrowsRatherThanSilentlyOverwritingWithEmptyFile(): void
    {
        $repo = new MyMemoUserRepository($this->directory);

        $this->expectException(\RuntimeException::class);
        $repo->add(['username' => 'jean.dupont', 'roles' => ['USER'], 'enabled' => true, 'display_name' => 'Jean DUPONT']);
    }
}
