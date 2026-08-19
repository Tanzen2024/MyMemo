<?php
// Partial partagé : chaque vue définit sa propre barre du haut (voir plan —
// pas de layout de navbar unique dans ce projet), donc ce contrôle est
// inclus au même endroit relatif dans chacune plutôt que dupliqué. Même
// schéma de réutilisation que templates/flash_messages.php.
$mmCurrentLocale = service('request')->getLocale();
?>
<li class="nav-item dropdown mm-lang-switch">
  <a class="nav-link dropdown-toggle" href="#" id="mmLangDropdown" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false" title="<?= esc(lang('Menu.language')) ?>">
    <?= $mmCurrentLocale === 'fr' ? '🇫🇷 FR' : '🇬🇧 EN' ?>
  </a>
  <div class="dropdown-menu dropdown-menu-right" aria-labelledby="mmLangDropdown">
    <a class="dropdown-item <?= $mmCurrentLocale === 'fr' ? 'active' : '' ?>" href="<?= site_url('language/switch/fr') ?>">🇫🇷 Français</a>
    <a class="dropdown-item <?= $mmCurrentLocale === 'en' ? 'active' : '' ?>" href="<?= site_url('language/switch/en') ?>">🇬🇧 English</a>
  </div>
</li>
<li class="nav-item">
  <button type="button" class="nav-link btn btn-link mm-theme-toggle" id="mmThemeToggle" title="<?= esc(lang('Menu.theme')) ?>">
    <i class="fas fa-moon" id="mmThemeIconDark"></i><i class="fas fa-sun" id="mmThemeIconLight" style="display:none"></i>
  </button>
</li>
<script>
(function () {
  // Bascule 100% client, sans rechargement (contrairement à la langue, qui
  // nécessite un rendu serveur) : écrit directement le cookie lu par
  // templates/header.php au prochain chargement de page (évite un flash
  // clair→sombre), et bascule data-theme immédiatement pour un retour visuel
  // instantané.
  var root = document.documentElement;
  var btn = document.getElementById('mmThemeToggle');
  var iconDark = document.getElementById('mmThemeIconDark');
  var iconLight = document.getElementById('mmThemeIconLight');
  if (!btn) { return; }

  function syncIcon() {
    var isDark = root.getAttribute('data-theme') === 'dark';
    iconDark.style.display = isDark ? 'none' : '';
    iconLight.style.display = isDark ? '' : 'none';
  }

  btn.addEventListener('click', function () {
    var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    document.cookie = 'mymemo_theme=' + next + '; path=/; max-age=31536000; SameSite=Lax';
    syncIcon();
  });

  syncIcon();
})();
</script>
