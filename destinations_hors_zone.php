<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs     = [];
$destination = ['id' => '', 'nom' => '', 'distance_km' => '', 'prix_remorque_20' => '', 'prix_remorque_40' => ''];
$devise      = parametres()['devise'];
$coef        = coef_autochargeuse_hors_zone();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'supprimer') {
        db()->prepare('DELETE FROM destinations_hors_zone WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('Destination supprimée.');
        header('Location: destinations_hors_zone.php');
        exit;
    }

    if ($action === 'enregistrer') {
        $destination = [
            'id'               => (int) ($_POST['id'] ?? 0),
            'nom'              => strtoupper(trim($_POST['nom'] ?? '')),
            'distance_km'      => trim($_POST['distance_km'] ?? ''),
            'prix_remorque_20' => str_replace([',', ' '], ['.', ''], trim($_POST['prix_remorque_20'] ?? '')),
            'prix_remorque_40' => str_replace([',', ' '], ['.', ''], trim($_POST['prix_remorque_40'] ?? '')),
        ];

        if ($destination['nom'] === '') $erreurs[] = 'Le nom est obligatoire.';
        if ($destination['distance_km'] !== '' && (!ctype_digit($destination['distance_km']) || $destination['distance_km'] <= 0)) {
            $erreurs[] = 'La distance doit être un nombre entier de kilomètres supérieur à 0.';
        }
        foreach (['prix_remorque_20', 'prix_remorque_40'] as $colonne) {
            if (!is_numeric($destination[$colonne]) || $destination[$colonne] <= 0) {
                $erreurs[] = 'Les tarifs 20\' et 40\' doivent être des nombres supérieurs à 0.';
                break;
            }
        }

        if (!$erreurs) {
            $valeurs = [$destination['nom'], $destination['distance_km'] ?: null,
                        $destination['prix_remorque_20'], $destination['prix_remorque_40']];
            try {
                if ($destination['id']) {
                    $valeurs[] = $destination['id'];
                    db()->prepare('UPDATE destinations_hors_zone SET nom = ?, distance_km = ?, prix_remorque_20 = ?, prix_remorque_40 = ? WHERE id = ?')
                        ->execute($valeurs);
                    flash('Destination modifiée.');
                } else {
                    db()->prepare('INSERT INTO destinations_hors_zone (nom, distance_km, prix_remorque_20, prix_remorque_40) VALUES (?, ?, ?, ?)')
                        ->execute($valeurs);
                    flash('Destination ajoutée.');
                }
                header('Location: destinations_hors_zone.php');
                exit;
            } catch (PDOException $ex) {
                $erreurs[] = $ex->getCode() === '23000'
                    ? 'Cette destination existe déjà.'
                    : 'Erreur base de données : ' . $ex->getMessage();
            }
        }
    }
} elseif (isset($_GET['modifier'])) {
    $stmt = db()->prepare('SELECT * FROM destinations_hors_zone WHERE id = ?');
    $stmt->execute([(int) $_GET['modifier']]);
    $destination = $stmt->fetch() ?: $destination;
}

$q = trim($_GET['q'] ?? '');
$stmt = db()->prepare('SELECT * FROM destinations_hors_zone WHERE nom LIKE ? ORDER BY nom');
$stmt->execute(['%' . $q . '%']);
$destinations = $stmt->fetchAll();

$titre = 'Destinations hors zone';
$page  = 'destinations_hors_zone';
require __DIR__ . '/includes/entete.php';
?>

<section class="panneau ne-pas-imprimer">
    <h2><?= $destination['id'] ? 'Modifier la destination' : 'Ajouter une destination hors zone' ?></h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" class="grille-form">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="enregistrer">
        <input type="hidden" name="id" value="<?= e((string) $destination['id']) ?>">
        <div>
            <label for="nom">Destination *</label>
            <input type="text" id="nom" name="nom" required maxlength="100"
                   value="<?= e($destination['nom']) ?>" placeholder="BOUAKE">
        </div>
        <div>
            <label for="distance_km">Distance depuis Abidjan (km)</label>
            <input type="number" id="distance_km" name="distance_km" min="1"
                   value="<?= e((string) $destination['distance_km']) ?>">
        </div>
        <div>
            <label for="prix_remorque_20">Tarif 20' (<?= e($devise) ?>) *</label>
            <input type="number" id="prix_remorque_20" name="prix_remorque_20" required min="1" step="1"
                   value="<?= e((string) $destination['prix_remorque_20']) ?>">
        </div>
        <div>
            <label for="prix_remorque_40">Tarif 40' (<?= e($devise) ?>) *</label>
            <input type="number" id="prix_remorque_40" name="prix_remorque_40" required min="1" step="1"
                   value="<?= e((string) $destination['prix_remorque_40']) ?>">
        </div>
        <div class="actions-form">
            <button type="submit" class="btn btn-auto"><?= $destination['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($destination['id']): ?>
                <a href="destinations_hors_zone.php" class="btn btn-auto btn-gris">Annuler</a>
            <?php endif; ?>
        </div>
    </form>
    <p class="petit">Les tarifs sont ceux de la semi-remorque standard, au départ d'Abidjan.
        En AC, le prix est multiplié par <?= str_replace('.', ',', (string) $coef) ?>
        (réglable dans <a href="tarifs_conteneurs.php" class="lien">Tarifs conteneurs</a>).</p>
</section>

<section class="panneau">
    <div class="titre-recherche">
        <h2>Destinations hors zone (<?= count($destinations) ?>)</h2>
        <form method="get" class="recherche">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Rechercher une destination…">
            <?php if ($q !== ''): ?><a href="destinations_hors_zone.php" class="lien petit">Effacer</a><?php endif; ?>
        </form>
    </div>
    <table>
        <thead>
            <tr><th>Destination</th><th>Distance</th>
                <?php foreach (MATERIELS_HORS_ZONE as $nomMateriel): ?><th><?= $nomMateriel ?> 20' / 40'</th><?php endforeach; ?>
                <th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($destinations as $d): ?>
                <tr>
                    <td><b><?= e($d['nom']) ?></b></td>
                    <td><?= $d['distance_km'] ? $d['distance_km'] . ' km' : '—' ?></td>
                    <?php foreach (array_keys(MATERIELS_HORS_ZONE) as $materiel): ?>
                        <td>
                            <?= montant(prix_hors_zone($d, $materiel, '20'), $devise) ?>
                            / <?= montant(prix_hors_zone($d, $materiel, '40'), $devise) ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="actions">
                        <a href="?modifier=<?= $d['id'] ?>" class="lien">Modifier</a>
                        <form method="post" onsubmit="return confirm('Supprimer cette destination ?');">
                            <?= csrf_champ() ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="lien lien-rouge">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$destinations): ?>
                <tr><td colspan="5" class="vide">Aucune destination hors zone<?= $q !== '' ? ' pour cette recherche' : '' ?>.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
