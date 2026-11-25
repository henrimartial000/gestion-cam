<?php
// Réinitialisation du mot de passe avec le code de récupération (généré dans "Mon compte").
// Depuis l'ordinateur du serveur lui-même, le code n'est pas exigé (secours si le code est perdu).
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$surServeur = est_sur_serveur();
$erreurs    = [];

// Limite : 5 essais ratés, puis 15 minutes d'attente
$essais = $_SESSION['reinit_essais'] ?? ['nb' => 0, 'depuis' => time()];
if (time() - $essais['depuis'] > 900) {
    $essais = ['nb' => 0, 'depuis' => time()];
}
$bloque = $essais['nb'] >= 5;

// Accepte "7kqm x4pd r9tw", "7KQMX4PDR9TW"… et le remet au format 7KQM-X4PD-R9TW
function normaliser_code(string $code): string
{
    $brut = preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    return implode('-', str_split($brut, 4));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloque) {
    verifier_csrf();
    $identifiant  = trim($_POST['identifiant'] ?? '');
    $code         = normaliser_code($_POST['code'] ?? '');
    $nouveau      = $_POST['nouveau'] ?? '';
    $confirmation = $_POST['confirmation'] ?? '';

    $stmt = db()->prepare('SELECT id, code_recuperation FROM utilisateurs WHERE identifiant = ?');
    $stmt->execute([$identifiant]);
    $user = $stmt->fetch();

    $codeOk = $user && $user['code_recuperation'] && $code !== '' && password_verify($code, $user['code_recuperation']);
    $autorise = $user && ($codeOk || ($surServeur && $code === ''));

    if (!$autorise) {
        $essais['nb']++;
        sleep(1);
        $erreurs[] = 'Identifiant ou code de récupération incorrect.';
    }
    if (strlen($nouveau) < 8)       $erreurs[] = 'Le nouveau mot de passe doit faire au moins 8 caractères.';
    if ($nouveau !== $confirmation) $erreurs[] = 'La confirmation ne correspond pas.';
    if ($nouveau === MDP_DEFAUT)    $erreurs[] = 'Choisissez un autre mot de passe que celui d\'installation.';

    if (!$erreurs) {
        // Le code a servi : il est désactivé, il faudra en générer un nouveau
        db()->prepare('UPDATE utilisateurs SET mot_de_passe = ?, code_recuperation = NULL WHERE id = ?')
            ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $user['id']]);
        unset($_SESSION['reinit_essais']);
        $_SESSION['message_connexion'] = 'Mot de passe modifié. Connectez-vous, puis générez un nouveau code de récupération dans « Mon compte ».';
        header('Location: index.php');
        exit;
    }
    $_SESSION['reinit_essais'] = $essais;
    $bloque = $essais['nb'] >= 5;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mot de passe oublié – La Lorraine</title>
    <link rel="icon" href="assets/logo.jpg">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="page-connexion">
    <form class="carte" method="post" autocomplete="off">
        <img src="assets/logo.jpg" alt="La Lorraine – Transport, Import, Export" class="logo-connexion">
        <h1>Mot de passe oublié</h1>
        <p class="sous-titre">Saisissez votre code de récupération pour choisir un nouveau mot de passe.</p>

        <?php if ($bloque): ?>
            <p class="erreur">Trop d'essais. Réessayez dans 15 minutes.</p>
        <?php endif; ?>
        <?php foreach ($erreurs as $err): ?>
            <p class="erreur"><?= e($err) ?></p>
        <?php endforeach; ?>

        <?php if ($surServeur): ?>
            <p class="alerte petit">Vous êtes sur l'ordinateur du serveur : le code peut être laissé vide.</p>
        <?php endif; ?>

        <?= csrf_champ() ?>
        <label for="identifiant">Identifiant</label>
        <input type="text" id="identifiant" name="identifiant" required value="<?= e($_POST['identifiant'] ?? '') ?>">

        <label for="code">Code de récupération</label>
        <input type="text" id="code" name="code" <?= $surServeur ? '' : 'required' ?> placeholder="XXXX-XXXX-XXXX"
               style="text-transform: uppercase; letter-spacing: .1em" value="<?= e($_POST['code'] ?? '') ?>">

        <label for="nouveau">Nouveau mot de passe</label>
        <input type="password" id="nouveau" name="nouveau" required minlength="8">

        <label for="confirmation">Confirmer le nouveau mot de passe</label>
        <input type="password" id="confirmation" name="confirmation" required minlength="8">

        <button type="submit" class="btn" <?= $bloque ? 'disabled' : '' ?>>Changer le mot de passe</button>

        <p class="lien-connexion"><a href="index.php">← Retour à la connexion</a></p>
        <p class="petit aide-code">Pas de code ? Il se génère dans « Mon compte » une fois connecté.
            Sinon, faites la réinitialisation depuis l'ordinateur où WampServer est installé.</p>
    </form>
</body>
</html>
