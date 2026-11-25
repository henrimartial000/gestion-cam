<?php
// Correction des chiffres d'une mission : carburant réel, prix du litre, montant facturé, frais
require_once __DIR__ . '/config.php';
exiger_connexion();

$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT m.*, c.immatriculation, d.nom AS destination
     FROM missions m JOIN camions c ON c.id = m.camion_id LEFT JOIN destinations d ON d.id = m.destination_id
     WHERE m.id = ?'
);
$stmt->execute([$id]);
$mission = $stmt->fetch();

if (!$mission) {
    flash('Mission introuvable.', 'erreur');
    header('Location: rapports.php');
    exit;
}

$params  = parametres();
$devise  = $params['devise'];
$erreurs = [];
$retourRapport = 'rapports.php?periode=jour&date=' . substr($mission['depart'], 0, 10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $valeurs = [];
    foreach (['carburant_l' => 'Le carburant', 'prix_litre' => 'Le prix du litre', 'montant' => 'Le montant facturé', 'frais' => 'Les frais'] as $champ => $nom) {
        $v = str_replace([',', ' '], ['.', ''], trim($_POST[$champ] ?? ''));
        if ($v === '') $v = '0';
        if (!is_numeric($v) || $v < 0) $erreurs[] = "$nom doit être un nombre positif.";
        $valeurs[$champ] = $v;
        $mission[$champ] = $v;
    }

    if (!$erreurs) {
        db()->prepare('UPDATE missions SET carburant_l = ?, prix_litre = ?, montant = ?, frais = ? WHERE id = ?')
            ->execute([$valeurs['carburant_l'], $valeurs['prix_litre'], $valeurs['montant'], $valeurs['frais'], $id]);
        flash('Mission mise à jour.');
        header('Location: ' . $retourRapport);
        exit;
    }
}

$titre = 'Mission';
$page  = 'rapports';
$imprimable = false;
require __DIR__ . '/includes/entete.php';
?>

<section class="panneau panneau-etroit">
    <h2>Chiffres de la mission</h2>
    <ul class="details">
        <li><b>Camion :</b> <?= e($mission['immatriculation']) ?> — <?= e($mission['chauffeur']) ?></li>
        <li><b>Destination :</b> <?= e($mission['destination'] ?? '?') ?><?= $mission['distance_km'] ? ' — ' . $mission['distance_km'] . ' km' : '' ?></li>
        <li><b>Départ :</b> <?= date('d/m/Y H:i', strtotime($mission['depart'])) ?></li>
    </ul>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post">
        <?= csrf_champ() ?>
        <input type="hidden" name="id" value="<?= $id ?>">

        <label for="carburant_l">Carburant (litres)</label>
        <input type="number" id="carburant_l" name="carburant_l" step="0.1" min="0" value="<?= e((string) $mission['carburant_l']) ?>">
        <p class="petit">Calculé automatiquement au départ. Remplacez-le par la quantité réelle si vous la connaissez.</p>

        <label for="prix_litre">Prix du litre (<?= e($devise) ?>)</label>
        <input type="number" id="prix_litre" name="prix_litre" step="0.01" min="0"
               value="<?= e((string) ($mission['prix_litre'] ?? $params['prix_litre'])) ?>">

        <label for="montant">Montant facturé (<?= e($devise) ?>)</label>
        <input type="number" id="montant" name="montant" step="1" min="0" value="<?= e((string) (float) $mission['montant']) ?>">

        <label for="frais">Autres frais (<?= e($devise) ?>)</label>
        <input type="number" id="frais" name="frais" step="1" min="0" value="<?= e((string) (float) $mission['frais']) ?>">

        <div class="actions-form">
            <button type="submit" class="btn btn-auto">Enregistrer</button>
            <a href="<?= e($retourRapport) ?>" class="btn btn-auto btn-gris">Annuler</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
