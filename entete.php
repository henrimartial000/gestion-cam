<?php
// En-tête commun aux pages connectées. Définir $titre et $page avant l'include.
// $imprimable = false masque le bouton Imprimer (pages de formulaire uniquement).
$imprimable = $imprimable ?? true;
// Menu : un lien direct, puis des groupes affichés en listes déroulantes
$menu = [
    'dashboard' => ['dashboard.php', 'Tableau de bord'],
    'Opérations' => [
        'disponibilite' => ['disponibilite.php', 'Disponibilité et missions'],
        'conteneurs'    => ['conteneurs.php', 'Conteneurs'],
        'tarifs_conteneurs' => ['tarifs_conteneurs.php', 'Tarifs conteneurs'],
        'communes'      => ['communes.php', 'Communes et zones'],
        'destinations_hors_zone' => ['destinations_hors_zone.php', 'Destinations hors zone'],
        'carburant'     => ['carburant.php', 'Calcul carburant'],
        'rapports'      => ['rapports.php', 'Rapports'],
    ],
    'Flotte' => [
        'camions'      => ['camions.php', 'Camions'],
        'remorques'    => ['remorques.php', 'Remorques'],
        'chauffeurs'   => ['chauffeurs.php', 'Chauffeurs'],
        'destinations' => ['destinations.php', 'Destinations'],
    ],
    'Administration' => [
        'parametres'   => ['parametres.php', 'Réglages'],
        'utilisateurs' => ['utilisateurs.php', 'Utilisateurs'],
        'compte'       => ['compte.php', 'Mon compte'],
    ],
];
if (!est_admin()) {
    unset($menu['Administration']['utilisateurs']);
}
$f = flash();
// Rubrique du menu contenant la page (affichée au-dessus du titre)
$rubrique = '';
foreach ($menu as $cleMenu => $element) {
    if (!is_string($element[0] ?? null) && isset($element[$page])) $rubrique = $cleMenu;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre) ?> – La Lorraine</title>
    <link rel="icon" href="assets/logo.jpg">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body class="<?= peut_modifier() ? '' : 'lecture-seule' ?>">
    <header class="entete">
        <div class="entete-haut largeur">
            <a href="dashboard.php" class="marque"><img src="assets/logo.jpg" alt="La Lorraine – Transport, Import, Export"></a>
            <span class="utilisateur">
                <span class="utilisateur-nom">
                    <b><?= e($_SESSION['nom']) ?></b>
                    <span class="badge-role role-<?= e($_SESSION['role']) ?>"><?= ROLES[$_SESSION['role']] ?? e($_SESSION['role']) ?></span>
                </span>
                <a class="btn btn-petit" href="logout.php">Déconnexion</a>
            </span>
        </div>
        <div class="barre-menu">
        <nav class="menu largeur">
            <?php foreach ($menu as $cleMenu => $element): ?>
                <?php if (is_string($element[0] ?? null)): // lien direct ?>
                    <a href="<?= $element[0] ?>" class="<?= $cleMenu === $page ? 'actif' : '' ?>"><?= $element[1] ?></a>
                <?php else: ?>
                    <details class="menu-groupe <?= isset($element[$page]) ? 'actif' : '' ?>">
                        <summary><?= $cleMenu ?></summary>
                        <div class="sous-menu">
                            <?php foreach ($element as $cleLien => [$urlLien, $nomLien]): ?>
                                <a href="<?= $urlLien ?>" class="<?= $cleLien === $page ? 'actif' : '' ?>"><?= $nomLien ?></a>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <script>
            // Un seul menu ouvert à la fois ; un clic ailleurs ou Échap le referme
            (function () {
                var groupes = document.querySelectorAll('.menu-groupe');
                groupes.forEach(function (g) {
                    g.addEventListener('toggle', function () {
                        if (g.open) groupes.forEach(function (autre) { if (autre !== g) autre.open = false; });
                    });
                });
                document.addEventListener('click', function (ev) {
                    groupes.forEach(function (g) { if (!g.contains(ev.target)) g.open = false; });
                });
                document.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Escape') groupes.forEach(function (g) { g.open = false; });
                });
                // Bouton Retour : page précédente de l'application ; sinon (arrivée directe, ou page rechargée
                // après un enregistrement) le lien mène au tableau de bord
                document.addEventListener('click', function (ev) {
                    var lien = ev.target.closest('[data-retour]');
                    if (!lien) return;
                    var precedente = document.referrer;
                    if (precedente.indexOf(location.origin + '/') === 0 && precedente !== location.href && history.length > 1) {
                        ev.preventDefault();
                        history.back();
                    }
                });
            })();
        </script>
        </div>
    </header>

    <main class="contenu">
        <!-- Visible uniquement à l'impression -->
        <div class="entete-impression">
            <img src="assets/logo.jpg" alt="La Lorraine">
            <div>
                <b><?= e($titre) ?></b>
                <span>Imprimé le <?= date('d/m/Y à H:i') ?> par <?= e($_SESSION['nom']) ?></span>
            </div>
        </div>

        <?php if ($page !== 'dashboard'): ?>
            <div class="titre-page">
                <div>
                    <span class="rubrique"><?= e($rubrique ?: 'La Lorraine') ?></span>
                    <h1><?= e($titre) ?></h1>
                </div>
                <div class="titre-actions">
                    <a href="dashboard.php" class="btn btn-auto btn-gris btn-retour" data-retour>&larr; Retour</a>
                    <?php if ($imprimable): ?>
                        <button type="button" class="btn btn-auto btn-gris btn-imprimer" onclick="window.print()">Imprimer</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($imprimable): ?>
            <div class="barre-impression">
                <button type="button" class="btn btn-auto btn-gris btn-imprimer" onclick="window.print()">Imprimer</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['mdp_defaut'])): ?>
            <p class="erreur ne-pas-imprimer">Vous utilisez encore le mot de passe d'installation.
                <a href="compte.php" class="lien">Changez-le maintenant</a> : il est connu de tous.</p>
        <?php endif; ?>

        <?php if (!peut_modifier()): ?>
            <p class="alerte ne-pas-imprimer">Compte en consultation seule : vous pouvez tout voir et imprimer, mais pas modifier.</p>
        <?php endif; ?>

        <?php if ($f): ?>
            <p class="<?= $f['type'] ?>"><?= e($f['message']) ?></p>
        <?php endif; ?>
