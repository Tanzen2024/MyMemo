<?php

use App\Libraries\LdapGroupMatcher;
use App\Filters\AdminAuditFilter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Couvre les 15 cas de la consigne "autorisation MyMemoUsers/MyMemoAdmins" :
 * matrice de rôles (tests 1-8, la matrice A-E étant couverte par
 * LdapGroupMatcherTest), tolérance de comparaison avec les groupes officiels
 * (tests 9-13), et le comportement réel du filtre d'administration
 * (tests 14-15, en s'appuyant sur is_mymemo_admin déjà calculé en session).
 */
final class MyMemoAuthorizationTest extends CIUnitTestCase
{
    private const USER_GROUPS = ['MyMemoUsers'];
    private const ADMIN_GROUPS = ['MyMemoAdmins'];

    // TEST 1 : aucun groupe MyMemo → refusé.
    public function testNoMyMemoGroupIsDenied(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['CN=Autre Groupe,DC=camlight,DC=cm'], self::USER_GROUPS, self::ADMIN_GROUPS);
        $this->assertFalse($roles['is_mymemo_user']);
        $this->assertFalse($roles['is_mymemo_admin']);
    }

    // TEST 2 : MyMemoUsers → accès MyMemo autorisé.
    public function testMyMemoUsersGrantsAccess(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['CN=MyMemoUsers,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'], self::USER_GROUPS, self::ADMIN_GROUPS);
        $this->assertTrue($roles['is_mymemo_user']);
        $this->assertFalse($roles['is_mymemo_admin']);
    }

    // TEST 3 : MyMemoAdmins → accès MyMemo + administration.
    public function testMyMemoAdminsGrantsAccessAndAdministration(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['CN=MyMemoAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'], self::USER_GROUPS, self::ADMIN_GROUPS);
        $this->assertTrue($roles['is_mymemo_user']);
        $this->assertTrue($roles['is_mymemo_admin']);
    }

    // TEST 4 : MyMemoUsers + MyMemoAdmins → les deux.
    public function testBothGroupsGrantBoth(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(
            ['CN=MyMemoUsers,OU=Groups,OU=Cameroon,DC=camlight,DC=cm', 'CN=MyMemoAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'],
            self::USER_GROUPS,
            self::ADMIN_GROUPS
        );
        $this->assertTrue($roles['is_mymemo_user']);
        $this->assertTrue($roles['is_mymemo_admin']);
    }

    // TEST 5 : ApplicationsAdmins uniquement → refusé (n'est plus un groupe MyMemo).
    public function testApplicationsAdminsAloneIsDenied(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['CN=ApplicationsAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'], self::USER_GROUPS, self::ADMIN_GROUPS);
        $this->assertFalse($roles['is_mymemo_user']);
        $this->assertFalse($roles['is_mymemo_admin']);
    }

    // TEST 6 : ancien groupe historique "Admins" uniquement → refusé.
    public function testHistoricalAdminsGroupAloneIsDenied(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['CN=Admins,OU=Groups,DC=camlight,DC=cm'], self::USER_GROUPS, self::ADMIN_GROUPS);
        $this->assertFalse($roles['is_mymemo_user']);
        $this->assertFalse($roles['is_mymemo_admin']);
    }

    // TEST 7 : MyMemoAdmins sans MyMemoUsers dans memberOf → accès MyMemo quand même autorisé.
    public function testMyMemoAdminsWithoutMyMemoUsersInMemberOfStillGrantsUserAccess(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['CN=MyMemoAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'], self::USER_GROUPS, self::ADMIN_GROUPS);
        $this->assertTrue($roles['is_mymemo_user'], 'MyMemoAdmins doit impliquer MyMemoUsers même sans imbrication AD.');
    }

    // TEST 8 : configuration vide → refusé (deny-by-default), jamais un accès par défaut.
    public function testEmptyConfigurationDeniesAccess(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['CN=MyMemoAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'], [], []);
        $this->assertFalse($roles['is_mymemo_user']);
        $this->assertFalse($roles['is_mymemo_admin']);
    }

    // TEST 9 : groupe configuré en CN simple → fonctionne.
    public function testBareCnConfiguration(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny(['CN=MyMemoUsers,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'], ['MyMemoUsers']));
        $this->assertTrue(LdapGroupMatcher::matchesAny(['CN=MyMemoAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'], ['MyMemoAdmins']));
    }

    // TEST 10 : DN complet correspondant → fonctionne.
    public function testFullDnConfiguration(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny(
            ['CN=MyMemoUsers,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'],
            ['CN=MyMemoUsers,OU=Groups,OU=Cameroon,DC=camlight,DC=cm']
        ));
    }

    // TEST 11 : casse différente → fonctionne.
    public function testCaseInsensitiveConfiguration(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny(['CN=MYMEMOUSERS,DC=camlight,DC=cm'], ['mymemousers']));
    }

    // TEST 12 : espaces superflus → fonctionne.
    public function testWhitespaceTolerantConfiguration(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny(['CN=MyMemoUsers,DC=camlight,DC=cm'], ['  MyMemoUsers  ']));
    }

    // TEST 13 : plusieurs groupes configurés → fonctionne.
    public function testMultipleConfiguredGroups(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny(
            ['CN=MyMemoUsers,DC=camlight,DC=cm'],
            ['CN=AutreGroupe,DC=x,DC=y', 'MyMemoUsers', 'CN=EncoreUnAutre,DC=x,DC=y']
        ));
    }

    // TEST 14 : utilisateur MyMemoUsers (pas admin) essayant d'accéder au Journal d'audit → refus + ACCESS_DENIED.
    public function testMyMemoUserWithoutAdminIsDeniedAuditAccess(): void
    {
        session()->set(['is_mymemo_admin' => false, 'username' => 'test.mymemouser@camlight.cm']);

        $filter = new AdminAuditFilter();
        $result = $filter->before(service('request'));

        $this->assertNotNull($result, 'Le filtre doit renvoyer une redirection, pas laisser passer.');
        $this->assertStringContainsString('/dashboard', (string) $result->header('Location')?->getValue());
    }

    // TEST 15 : utilisateur MyMemoAdmins accédant au Journal d'audit → laissé passer (avant de rendre AuditController::index()).
    public function testMyMemoAdminIsGrantedAuditAccess(): void
    {
        session()->set(['is_mymemo_admin' => true, 'username' => 'test.mymemoadmin@camlight.cm']);

        $filter = new AdminAuditFilter();
        $result = $filter->before(service('request'));

        $this->assertNull($result, 'Le filtre doit laisser passer (avant() ne renvoie rien) pour un administrateur MyMemo.');
    }
}
