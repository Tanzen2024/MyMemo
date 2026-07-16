<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion</title>
</head>
<body>
    <main>
        <h1>Connexion</h1>
        <?php if (session('msg')): ?>
            <p role="alert"><?= esc(session('msg')) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= site_url('authentification/login') ?>">
            <?= csrf_field() ?>
            <label>Identifiant <input name="username" value="<?= esc(old('username')) ?>" autocomplete="username" required></label>
            <label>Mot de passe <input type="password" name="password" autocomplete="current-password" required></label>
            <button type="submit">Se connecter</button>
        </form>
    </main>
</body>
</html>
