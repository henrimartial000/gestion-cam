<?php
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$erreur  = '';
$message = $_SESSION['message_connexion'] ?? '';
unset($_SESSION['message_connexion']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant = trim($_POST['identifiant'] ?? '');
    $motDePasse  = $_POST['mot_de_passe'] ?? '';

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    try {
        // Limite : 5 essais ratés par compte depuis un même poste, 20 au total par poste, sur 15 minutes
        $pdo = db();
        $pdo->exec('DELETE FROM tentatives_connexion WHERE moment < NOW() - INTERVAL 1 DAY');
        $stmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(identifiant = ?), 0) AS compte
                               FROM tentatives_connexion WHERE ip = ? AND moment > NOW() - INTERVAL 15 MINUTE');
        $stmt->execute([$identifiant, $ip]);
        $essais = $stmt->fetch();
        $bloque = $essais['compte'] >= 5 || $essais['total'] >= 20;

        $stmt = $pdo->prepare('SELECT id, identifiant, mot_de_passe, actif FROM utilisateurs WHERE identifiant = ?');
        $stmt->execute([$identifiant]);
        $user = $stmt->fetch();

        if ($bloque) {
            $erreur = 'Trop d\'essais ratés. Réessayez dans 15 minutes.';
        } elseif ($user && password_verify($motDePasse, $user['mot_de_passe'])) {
            $pdo->prepare('DELETE FROM tentatives_connexion WHERE ip = ? AND identifiant = ?')->execute([$ip, $identifiant]);
            if (!$user['actif']) {
                $erreur = 'Ce compte est désactivé. Contactez un administrateur.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']     = $user['id'];
                $_SESSION['identifiant'] = $user['identifiant'];
                // Mot de passe d'installation encore utilisé : un bandeau demandera de le changer
                $_SESSION['mdp_defaut']  = $motDePasse === MDP_DEFAUT;
                db()->prepare('UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?')->execute([$user['id']]);
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $pdo->prepare('INSERT INTO tentatives_connexion (ip, identifiant) VALUES (?, ?)')
                ->execute([$ip, mb_substr($identifiant, 0, 50, 'UTF-8')]);
            sleep(1);
            $erreur = 'Identifiant ou mot de passe incorrect.';
        }
    } catch (PDOException $ex) {
        $erreur = 'Base de données introuvable. Lancez d\'abord install.php.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion – La Lorraine</title>
    <link rel="icon" href="assets/logo.jpg">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="page-connexion">
    <form class="carte" method="post" autocomplete="off">
        <img src="assets/logo.jpg" alt="La Lorraine – Transport, Import, Export" class="logo-connexion">
        <h1>Gestion de flotte</h1>
        <p class="sous-titre">Connectez-vous pour accéder au tableau de bord</p>

        <?php if ($message): ?>
            <p class="succes"><?= e($message) ?></p>
        <?php endif; ?>
        <?php if ($erreur): ?>
            <p class="erreur"><?= e($erreur) ?></p>
        <?php endif; ?>

        <label for="identifiant">Identifiant</label>
        <input type="text" id="identifiant" name="identifiant" required autofocus
               value="<?= e($_POST['identifiant'] ?? '') ?>">

        <label for="mot_de_passe">Mot de passe</label>
        <input type="password" id="mot_de_passe" name="mot_de_passe" required>

        <button type="submit" class="btn">Se connecter</button>

        <p class="lien-connexion"><a href="mot_de_passe_oublie.php">Mot de passe oublié ?</a></p>
    </form>
</body>
</html>
