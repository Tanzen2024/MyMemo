<?php

use App\Libraries\LdapGroupMatcher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Couvre la comparaison groupe(s) AD réel(s) / groupe(s) configuré(s), et la
 * composition is_mymemo_user / is_mymemo_admin (MyMemoAdmins implique
 * MyMemoUsers). Logique extraite de AdminAuditFilter (cf. tâches
 * précédentes) pour être réutilisée par la détermination des droits
 * utilisateur MyMemo.
 */
final class LdapGroupMatcherTest extends CIUnitTestCase
{
    /** Les 22 groupes réels observés en session pour un utilisateur du diagnostic précédent. */
    private function realUserGroupsWithoutMyMemoGroups(): array
    {
        return [
            'CN=ApplicationsAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm',
            'CN=VPN_ADMIN_Commercial,OU=Groups,OU=Cameroon,DC=camlight,DC=cm',
            'CN=TSPLUS,DC=camlight,DC=cm',
            'CN=TEST,DC=camlight,DC=cm',
        ];
    }

    // ---- matchesAny() : DN/CN, casse, espaces, plusieurs groupes ----

    public function testExactConfiguredDnMatches(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny(
            $this->realUserGroupsWithoutMyMemoGroups(),
            ['CN=ApplicationsAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm']
        ));
    }

    public function testHistoricalHardcodedGroupNeverMatchesThisRealUser(): void
    {
        $this->assertFalse(LdapGroupMatcher::matchesAny(
            $this->realUserGroupsWithoutMyMemoGroups(),
            ['CN=Admins,OU=Groups,DC=camlight,DC=cm']
        ));
    }

    public function testCaseAndWhitespaceDifferencesAreTolerated(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny(
            $this->realUserGroupsWithoutMyMemoGroups(),
            ['  cn=applicationsadmins,ou=groups,ou=cameroon,dc=camlight,dc=cm  ']
        ));
    }

    public function testBareGroupNameMatchesRegardlessOfOuDepth(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny($this->realUserGroupsWithoutMyMemoGroups(), ['ApplicationsAdmins']));
        $this->assertTrue(LdapGroupMatcher::matchesAny($this->realUserGroupsWithoutMyMemoGroups(), ['TSPLUS']));
    }

    public function testMultipleConfiguredGroupsAnyMatchGrantsAccess(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny(
            $this->realUserGroupsWithoutMyMemoGroups(),
            ['CN=NeMatchePas,DC=x,DC=y', 'TSPLUS', 'CN=AutreNeMatchePas,DC=x,DC=y']
        ));
    }

    public function testUnrelatedGroupDoesNotMatch(): void
    {
        $this->assertFalse(LdapGroupMatcher::matchesAny($this->realUserGroupsWithoutMyMemoGroups(), ['CN=DomainAdmins,OU=Groups,DC=camlight,DC=cm']));
    }

    public function testEmptyConfigurationDeniesAccessRatherThanFailingOpen(): void
    {
        $this->assertFalse(LdapGroupMatcher::matchesAny($this->realUserGroupsWithoutMyMemoGroups(), []));
    }

    public function testEmptyUserGroupsDoesNotMatchAnything(): void
    {
        $this->assertFalse(LdapGroupMatcher::matchesAny([], ['MyMemoUsers']));
    }

    public function testPartialSubstringDoesNotFalselyMatch(): void
    {
        $this->assertFalse(LdapGroupMatcher::matchesAny($this->realUserGroupsWithoutMyMemoGroups(), ['Admins']));
        $this->assertFalse(LdapGroupMatcher::matchesAny(['CN=MyMemoAdminsExtended,DC=camlight,DC=cm'], ['MyMemoAdmins']));
    }

    public function testFullDnAndBareCnBothMatch(): void
    {
        $this->assertTrue(LdapGroupMatcher::matchesAny(
            ['CN=MyMemoAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm'],
            ['CN=MyMemoAdmins,OU=Groups,OU=Cameroon,DC=camlight,DC=cm']
        ));
        $this->assertTrue(LdapGroupMatcher::matchesAny(
            ['CN=MyMemoAdmins,DC=camlight,DC=cm'], // profondeur d'OU différente
            ['MyMemoAdmins']
        ));
    }

    // ---- computeMyMemoRoles() : matrice utilisateurs A-E de la consigne ----

    public function testUserA_MyMemoUsersOnly(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['MyMemoUsers'], ['MyMemoUsers'], ['MyMemoAdmins']);
        $this->assertTrue($roles['is_mymemo_user']);
        $this->assertFalse($roles['is_mymemo_admin']);
    }

    public function testUserB_MyMemoAdminsOnly_ImpliesMyMemoUsers(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['MyMemoAdmins'], ['MyMemoUsers'], ['MyMemoAdmins']);
        $this->assertTrue($roles['is_mymemo_user']);
        $this->assertTrue($roles['is_mymemo_admin']);
    }

    public function testUserC_BothGroups(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['MyMemoUsers', 'MyMemoAdmins'], ['MyMemoUsers'], ['MyMemoAdmins']);
        $this->assertTrue($roles['is_mymemo_user']);
        $this->assertTrue($roles['is_mymemo_admin']);
    }

    public function testUserD_ApplicationsAdminsOnly(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles(['ApplicationsAdmins'], ['MyMemoUsers'], ['MyMemoAdmins']);
        $this->assertFalse($roles['is_mymemo_user']);
        $this->assertFalse($roles['is_mymemo_admin']);
    }

    public function testUserE_NoGroups(): void
    {
        $roles = LdapGroupMatcher::computeMyMemoRoles([], ['MyMemoUsers'], ['MyMemoAdmins']);
        $this->assertFalse($roles['is_mymemo_user']);
        $this->assertFalse($roles['is_mymemo_admin']);
    }
}
