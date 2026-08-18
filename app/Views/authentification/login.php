<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion</title>
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,600,700" rel="stylesheet">
    <link rel="stylesheet" href="<?= base_url('assets/css/soft-ui.css') ?>">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(160deg, var(--brand-50) 0%, var(--bg-body) 100%);
        }

        .login-shell {
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .login-card {
            width: 100%;
            max-width: 380px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 2.25rem 2rem;
            animation: mm-fade-in .35s ease both;
        }

        .login-brand {
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .login-brand .login-mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: var(--brand-100);
            color: var(--brand-600);
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: .75rem;
        }

        .login-brand h1 {
            font-size: 1.3rem;
            margin: 0;
            color: var(--text-heading);
        }

        .login-brand p {
            margin: .25rem 0 0;
            color: var(--text-muted);
            font-size: .88rem;
        }

        .login-alert {
            background: var(--danger-100);
            color: #7a3a3a;
            border-radius: var(--radius-sm);
            padding: .65rem .9rem;
            font-size: .88rem;
            margin-bottom: 1.25rem;
        }

        .login-field {
            display: block;
            margin-bottom: 1.1rem;
            font-weight: 600;
            color: var(--text-heading);
            font-size: .88rem;
        }

        .login-field input {
            display: block;
            width: 100%;
            margin-top: .4rem;
            padding: .65rem .85rem;
            border: 1px solid var(--border-color-strong);
            border-radius: var(--radius-sm);
            font-size: .95rem;
            color: var(--text-body);
            background: #fff;
            box-sizing: border-box;
            transition: var(--transition-fast);
        }

        .login-field input:focus {
            outline: none;
            border-color: var(--brand-500);
            box-shadow: 0 0 0 3px rgba(91, 107, 147, 0.15);
        }

        .login-submit {
            width: 100%;
            padding: .7rem 1rem;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--brand-500);
            color: #fff;
            font-weight: 600;
            font-size: .95rem;
            cursor: pointer;
            transition: var(--transition-fast);
            margin-top: .5rem;
        }

        .login-submit:hover {
            background: var(--brand-600);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <main class="login-shell">
        <div class="login-card">
            <div class="login-brand">
                <span class="login-mark">MM</span>
                <h1>MyMemo</h1>
                <p>Connectez-vous pour continuer</p>
            </div>

            <?php if (session('msg')): ?>
                <p role="alert" class="login-alert"><?= esc(session('msg')) ?></p>
            <?php endif; ?>

            <form method="post" action="<?= site_url('authentification/login') ?>">
                <?= csrf_field() ?>
                <label class="login-field">
                    Identifiant
                    <input name="username" value="<?= esc(old('username')) ?>" autocomplete="username" required>
                </label>
                <label class="login-field">
                    Mot de passe
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit" class="login-submit">Se connecter</button>
            </form>
        </div>
    </main>
</body>
</html>
