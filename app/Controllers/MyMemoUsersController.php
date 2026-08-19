<?php

namespace App\Controllers;

use App\Services\AuditLoggerService;
use App\Services\MyMemoAuthorizationService;
use App\Services\MyMemoUserRepository;

/**
 * Gestion des utilisateurs MyMemo (CRUD complet sur writable/security/users.csv
 * via MyMemoUserRepository — jamais d'accès fichier direct ici). « Ajouter »
 * autorise un compte AD existant à utiliser MyMemo : ne crée ni compte AD, ni
 * mot de passe, ni compte local. Toutes les actions sont déjà protégées par
 * le filtre de route 'auditadmin' (voir Config/Routes.php), lui-même basé
 * sur session('is_mymemo_admin'), rafraîchi à chaque requête par
 * AutoLogoutFilter depuis le même CSV.
 */
final class MyMemoUsersController extends BaseController
{
    private MyMemoUserRepository $repository;
    private MyMemoAuthorizationService $authorization;
    private AuditLoggerService $auditLogger;

    public function __construct(
        ?MyMemoUserRepository $repository = null,
        ?MyMemoAuthorizationService $authorization = null,
        ?AuditLoggerService $auditLogger = null
    ) {
        $this->repository    = $repository ?? new MyMemoUserRepository();
        $this->authorization = $authorization ?? new MyMemoAuthorizationService($this->repository);
        $this->auditLogger   = $auditLogger ?? new AuditLoggerService();
    }

    public function index()
    {
        try {
            $users = $this->repository->readAll();
        } catch (\Throwable $e) {
            log_message('critical', 'MyMemoUsersController::index : lecture de users.csv impossible : {msg}', ['msg' => $e->getMessage()]);

            return view('mymemo_users/index', [
                'users'      => [],
                'search'     => '',
                'knownRoles' => MyMemoAuthorizationService::KNOWN_ROLES,
                'readError'  => "Impossible de lire la liste des utilisateurs (users.csv inaccessible). Contactez un administrateur système.",
            ]);
        }

        $search = trim((string) $this->request->getGet('search'));
        if ($search !== '') {
            $users = array_filter($users, static fn ($u) => stripos($u['username'], $search) !== false
                || stripos($u['display_name'], $search) !== false);
        }

        $users = array_values($users);
        usort($users, static fn ($a, $b) => strcasecmp($a['username'], $b['username']));

        return view('mymemo_users/index', [
            'users'      => $users,
            'search'     => $search,
            'knownRoles' => MyMemoAuthorizationService::KNOWN_ROLES,
        ]);
    }

    public function create()
    {
        return view('mymemo_users/form', [
            'mode'       => 'create',
            'user'       => ['username' => '', 'roles' => [], 'enabled' => true, 'display_name' => ''],
            'knownRoles' => MyMemoAuthorizationService::KNOWN_ROLES,
        ]);
    }

    public function store()
    {
        $input = $this->readUserInput();

        try {
            $this->assertUsernameFormat($input['username']);
            if ($this->repository->exists($input['username'])) {
                throw new \InvalidArgumentException('Cet utilisateur est déjà autorisé.');
            }
            $this->assertKnownRoles($input['roles']);

            $this->repository->add($input);

            $this->auditLogger->log('MYMEMO_USER_CREATED', 'SUCCESS', 0.0, $this->request, [
                'category'    => 'ADMINISTRATION',
                'severity'    => 'INFO',
                'module'      => 'GestionUtilisateurs',
                'target_user' => $input['username'],
                'new_roles'   => $input['roles'],
                'new_enabled' => $input['enabled'],
            ]);

            return redirect()->to('/administration/users')->with('msg', lang('Users.flashCreated'));
        } catch (\Throwable $e) {
            $this->auditLogger->log('MYMEMO_USER_CREATED', 'FAILED', 0.0, $this->request, [
                'category'    => 'ADMINISTRATION',
                'severity'    => 'WARNING',
                'module'      => 'GestionUtilisateurs',
                'target_user' => $input['username'],
                'message'     => $e->getMessage(),
            ]);

            return redirect()->to('/administration/users/create')->with('msg', $e->getMessage())->withInput();
        }
    }

    public function edit(string $username)
    {
        $user = $this->repository->findByUsername($username);
        if ($user === null) {
            return redirect()->to('/administration/users')->with('msg', lang('Users.flashNotFound'));
        }

        return view('mymemo_users/form', [
            'mode'       => 'edit',
            'user'       => $user,
            'knownRoles' => MyMemoAuthorizationService::KNOWN_ROLES,
        ]);
    }

    public function update(string $username)
    {
        $input = $this->readUserInput();
        $before = $this->repository->findByUsername($username);

        try {
            $this->assertKnownRoles($input['roles']);

            $this->repository->update($username, [
                'roles'        => $input['roles'],
                'enabled'      => $input['enabled'],
                'display_name' => $input['display_name'],
            ], $this->authorization->lastAdminGuard());

            $this->auditLogger->log('MYMEMO_USER_UPDATED', 'SUCCESS', 0.0, $this->request, [
                'category'    => 'ADMINISTRATION',
                'severity'    => 'INFO',
                'module'      => 'GestionUtilisateurs',
                'target_user' => $username,
                'old_roles'   => $before['roles'] ?? null,
                'new_roles'   => $input['roles'],
                'old_enabled' => $before['enabled'] ?? null,
                'new_enabled' => $input['enabled'],
            ]);

            $this->auditRoleDiff($username, $before['roles'] ?? [], $input['roles']);

            return redirect()->to('/administration/users')->with('msg', lang('Users.flashUpdated'));
        } catch (\Throwable $e) {
            $this->auditLogger->log('MYMEMO_USER_UPDATED', 'FAILED', 0.0, $this->request, [
                'category'    => 'ADMINISTRATION',
                'severity'    => 'WARNING',
                'module'      => 'GestionUtilisateurs',
                'target_user' => $username,
                'message'     => $e->getMessage(),
            ]);

            return redirect()->to('/administration/users/' . rawurlencode($username) . '/edit')->with('msg', $e->getMessage());
        }
    }

    public function enable(string $username)
    {
        return $this->toggleEnabled($username, true);
    }

    public function disable(string $username)
    {
        return $this->toggleEnabled($username, false);
    }

    public function delete(string $username)
    {
        $before = $this->repository->findByUsername($username);

        try {
            $this->repository->remove($username, $this->authorization->lastAdminGuard());

            $this->auditLogger->log('MYMEMO_USER_DELETED', 'SUCCESS', 0.0, $this->request, [
                'category'    => 'ADMINISTRATION',
                'severity'    => 'WARNING',
                'module'      => 'GestionUtilisateurs',
                'target_user' => $username,
                'old_roles'   => $before['roles'] ?? null,
            ]);

            return redirect()->to('/administration/users')->with('msg', lang('Users.flashDeleted'));
        } catch (\Throwable $e) {
            $this->auditLogger->log('MYMEMO_USER_DELETED', 'FAILED', 0.0, $this->request, [
                'category'    => 'ADMINISTRATION',
                'severity'    => 'WARNING',
                'module'      => 'GestionUtilisateurs',
                'target_user' => $username,
                'message'     => $e->getMessage(),
            ]);

            return redirect()->to('/administration/users')->with('msg', $e->getMessage());
        }
    }

    private function toggleEnabled(string $username, bool $enabled)
    {
        $action = $enabled ? 'MYMEMO_USER_ENABLED' : 'MYMEMO_USER_DISABLED';

        try {
            $this->repository->update($username, ['enabled' => $enabled], $this->authorization->lastAdminGuard());

            $this->auditLogger->log($action, 'SUCCESS', 0.0, $this->request, [
                'category'    => 'ADMINISTRATION',
                'severity'    => 'INFO',
                'module'      => 'GestionUtilisateurs',
                'target_user' => $username,
            ]);

            return redirect()->to('/administration/users')->with('msg', $enabled ? lang('Users.flashEnabled') : lang('Users.flashDisabled'));
        } catch (\Throwable $e) {
            $this->auditLogger->log($action, 'FAILED', 0.0, $this->request, [
                'category'    => 'ADMINISTRATION',
                'severity'    => 'WARNING',
                'module'      => 'GestionUtilisateurs',
                'target_user' => $username,
                'message'     => $e->getMessage(),
            ]);

            return redirect()->to('/administration/users')->with('msg', $e->getMessage());
        }
    }

    private function auditRoleDiff(string $username, array $oldRoles, array $newRoles): void
    {
        foreach (array_diff($newRoles, $oldRoles) as $granted) {
            $this->auditLogger->log('MYMEMO_ROLE_GRANTED', 'SUCCESS', 0.0, $this->request, [
                'category' => 'ADMINISTRATION', 'severity' => 'INFO', 'module' => 'GestionUtilisateurs',
                'target_user' => $username, 'role' => $granted,
            ]);
        }
        foreach (array_diff($oldRoles, $newRoles) as $revoked) {
            $this->auditLogger->log('MYMEMO_ROLE_REVOKED', 'SUCCESS', 0.0, $this->request, [
                'category' => 'ADMINISTRATION', 'severity' => 'INFO', 'module' => 'GestionUtilisateurs',
                'target_user' => $username, 'role' => $revoked,
            ]);
        }
    }

    private function readUserInput(): array
    {
        $roles = array_map(
            static fn ($r) => strtoupper(trim((string) $r)),
            (array) $this->request->getPost('roles')
        );

        return [
            'username'     => MyMemoUserRepository::normalize((string) $this->request->getPost('username')),
            'roles'        => array_values(array_unique($roles)),
            'enabled'      => $this->request->getPost('enabled') !== null,
            'display_name' => trim((string) $this->request->getPost('display_name')),
        ];
    }

    private function assertUsernameFormat(string $username): void
    {
        if ($username === '' || ! preg_match('/^[a-z0-9._-]{1,64}$/', $username)) {
            throw new \InvalidArgumentException('Identifiant AD invalide.');
        }
    }

    /** Jamais de rôle accepté hors liste blanche — aucune valeur envoyée par le navigateur n'est approuvée telle quelle (§19/§21). */
    private function assertKnownRoles(array $roles): void
    {
        if ($roles === []) {
            throw new \InvalidArgumentException('Sélectionnez au moins un rôle.');
        }
        foreach ($roles as $role) {
            if (! $this->authorization->isKnownRole($role)) {
                throw new \InvalidArgumentException("Rôle inconnu : {$role}");
            }
        }
    }
}
