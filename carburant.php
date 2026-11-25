<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$camions      = camions_avec_statut();
$destinations = db()->query('SELECT * FROM destinations ORDER BY nom')->fetchAll();
$taux         = taux_consommation();

$titre = 'Calcul carburant';
$imprimable = false;
$page  = 'carburant';
require __DIR__ . '/includes/entete.php';
?>

<section class="panneau">
    <h2>Calcul du carburant nécessaire</h2>

    <?php if (!$camions || !$destinations): ?>
        <p class="alerte">
            Ajoutez d'abord au moins un <a href="camions.php">camion</a>
            et une <a href="destinations.php">destination</a>.
        </p>
    <?php endif; ?>

    <form id="form-calcul" class="grille-form">
        <div>
            <label for="camion">Camion (facultatif)</label>
            <select id="camion" name="camion_id">
                <option value="">— Tous les camions —</option>
                <?php foreach ($camions as $c): ?>
                    <option value="<?= $c['id'] ?>">
                        <?= e($c['immatriculation']) ?> – <?= e($c['modele']) ?>
                        <?= $c['remorque'] ? '+ ' . e($c['remorque']) : '' ?>
                        <?= $c['statut'] !== 'disponible' ? ' — ' . LIBELLES_STATUT[$c['statut']] : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="destination">Destination</label>
            <select id="destination" name="destination_id" required data-recherche="Tapez au moins 3 lettres de la destination…">
                <option value="">— Choisir une destination —</option>
                <?php foreach ($destinations as $d): ?>
                    <option value="<?= $d['id'] ?>"><?= e($d['nom']) ?> (<?= $d['distance_km'] ?> km)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="aller">Aller</label>
            <select id="aller" name="aller">
                <option value="charge" selected>Chargé (× <?= $taux['charge'] ?> L/km)</option>
                <option value="vide">À vide (× <?= $taux['vide'] ?> L/km)</option>
            </select>
        </div>
        <div>
            <label for="retour">Retour</label>
            <select id="retour" name="retour">
                <option value="vide" selected>À vide (× <?= $taux['vide'] ?> L/km)</option>
                <option value="charge">Chargé (× <?= $taux['charge'] ?> L/km)</option>
                <option value="aucun">Pas de retour</option>
            </select>
        </div>
        <div>
            <label for="marge">Marge de sécurité (%)</label>
            <input type="number" id="marge" name="marge" value="0" min="0" max="100">
        </div>
    </form>
    <p class="petit">Carburant = distance × taux. Les taux se modifient dans <a href="parametres.php" class="lien">Réglages</a>.</p>

    <div id="resultat" class="resultat cache"></div>
</section>

<script src="assets/app.js"></script>

<?php require __DIR__ . '/includes/pied.php'; ?>
