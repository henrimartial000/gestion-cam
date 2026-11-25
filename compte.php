<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs     = [];
$erreursCode = [];

// Code lisible, sans caractères ambigus (0/O, 1/I/L) : ex. 7KQM-X4PD-R9TW
function generer_code_recuperation(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 12; $i++) {
        if ($i && $i % 4 === 0) $code .= '-';
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

$stmt = db()->prepare('SELECT mot_de_passe, code_recuperation FROM utilisateurs WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$utilisateur = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $actuel = $_POST['actuel'] ?? '';
    $motDePasseOk = $utilisateur && password_verify($actuel, $utilisateur['mot_de_passe']);

    if (($_POST['action'] ?? '') === 'generer_code') {
        if (!$motDePasseOk) {
            $erreursCode[] = 'Le mot de passe actuel est incorrect.';
        } else {
            $code = generer_code_recuperation();
            db()->prepare('UPDATE utilisateurs SET code_recuperation = ? WHERE id = ?')
                ->execute([password_hash($code, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            // Affiché une seule fois, après la redirection
            $_SESSION['code_affiche'] = $code;
            header('Location: compte.php#code');
            exit;
        }
    } else {
        $nouveau      = $_POST['nouveau'] ?? '';
        $confirmation = $_POST['confirmation'] ?? '';

        if (!$motDePasseOk)             $erreurs[] = 'Le mot de passe actuel est incorrect.';
        if (strlen($nouveau) < 8)       $erreurs[] = 'Le nouveau mot de passe doit faire au moins 8 caractères.';
        if ($nouveau !== $confirmation) $erreurs[] = 'La confirmation ne correspond pas.';
        if ($nouveau === MDP_DEFAUT)    $erreurs[] = 'Choisissez un autre mot de passe que celui d\'installation.';

        if (!$erreurs) {
            db()->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            unset($_SESSION['mdp_defaut']);
            flash('Mot de passe modifié.');
            header('Location: compte.php');
            exit;
        }
    }
}

$codeAffiche = $_SESSION['code_affiche'] ?? null;
unset($_SESSION['code_affiche']);

$titre = 'Mon compte';
$page  = 'compte';
$imprimable = false;
require __DIR__ . '/includes/entete.php';
?>

<div class="deux-colonnes">
    <section class="panneau">
        <h2>Changer le mot de passe</h2>

        <?php foreach ($erreurs as $err): ?>
            <p class="erreur"><?= e($err) ?></p>
        <?php endforeach; ?>

        <form method="post" class="autorise">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="mot_de_passe">
            <label for="actuel">Mot de passe actuel</label>
            <input type="password" id="actuel" name="actuel" required>

            <label for="nouveau">Nouveau mot de passe</label>
            <input type="password" id="nouveau" name="nouveau" required minlength="8">

            <label for="confirmation">Confirmer le nouveau mot de passe</label>
            <input type="password" id="confirmation" name="confirmation" required minlength="8">

            <button type="submit" class="btn">Modifier</button>
        </form>
    </section>

    <section class="panneau" id="code">
        <h2>Code de récupération</h2>
        <p class="petit">Ce code permet de choisir un nouveau mot de passe depuis le lien
            « Mot de passe oublié ? » de la page de connexion. Notez-le et gardez-le en lieu sûr.</p>

        <?php if ($codeAffiche): ?>
            <div class="code-recuperation">
                <small>Votre nouveau code (affiché une seule fois)</small>
                <b><?= e($codeAffiche) ?></b>
                <button type="button" class="btn btn-auto btn-gris" onclick="window.print()">Imprimer</button>
            </div>
            <p class="alerte">Notez ce code maintenant : il ne sera plus jamais affiché. Les anciens codes ne fonctionnent plus.</p>
        <?php elseif ($utilisateur['code_recuperation']): ?>
            <p class="succes">Un code de récupération est actif.</p>
        <?php else: ?>
            <p class="alerte">Aucun code de récupération : en cas d'oubli, la réinitialisation ne sera possible que depuis l'ordinateur du serveur.</p>
        <?php endif; ?>

        <?php foreach ($erreursCode as $err): ?>
            <p class="erreur"><?= e($err) ?></p>
        <?php endforeach; ?>

        <form method="post" class="autorise">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="generer_code">
            <label for="actuel-code">Mot de passe actuel (pour confirmer)</label>
            <input type="password" id="actuel-code" name="actuel" required>
            <button type="submit" class="btn">
                <?= $utilisateur['code_recuperation'] ? 'Générer un nouveau code' : 'Générer mon code' ?>
            </button>
        </form>
    </section>
</div>

<?php require __DIR__ . '/includes/pied.php'; ?>
