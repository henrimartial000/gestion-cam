<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs     = [];
$destination = ['id' => '', 'nom' => '', 'distance_km' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'supprimer') {
        db()->prepare('DELETE FROM destinations WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('Destination supprimée.');
        header('Location: destinations.php');
        exit;
    }

    if ($action === 'enregistrer') {
        $destination = [
            'id'          => (int) ($_POST['id'] ?? 0),
            'nom'         => trim($_POST['nom'] ?? ''),
            'distance_km' => $_POST['distance_km'] ?? '',
        ];

        if ($destination['nom'] === '') $erreurs[] = 'Le nom est obligatoire.';
        if (!ctype_digit((string) $destination['distance_km']) || $destination['distance_km'] <= 0) {
            $erreurs[] = 'La distance doit être un nombre entier de kilomètres supérieur à 0.';
        }

        if (!$erreurs) {
            $valeurs = [$destination['nom'], $destination['distance_km']];
            try {
                if ($destination['id']) {
                    $valeurs[] = $destination['id'];
                    db()->prepare('UPDATE destinations SET nom = ?, distance_km = ? WHERE id = ?')->execute($valeurs);
                    flash('Destination modifiée.');
                } else {
                    db()->prepare('INSERT INTO destinations (nom, distance_km) VALUES (?, ?)')->execute($valeurs);
                    flash('Destination ajoutée.');
                }
                header('Location: destinations.php');
                exit;
            } catch (PDOException $ex) {
                $erreurs[] = $ex->getCode() === '23000'
                    ? 'Cette destination existe déjà.'
                    : 'Erreur base de données : ' . $ex->getMessage();
            }
        }
    }
} elseif (isset($_GET['modifier'])) {
    $stmt = db()->prepare('SELECT * FROM destinations WHERE id = ?');
    $stmt->execute([(int) $_GET['modifier']]);
    $destination = $stmt->fetch() ?: $destination;
}

$destinations = db()->query('SELECT * FROM destinations ORDER BY nom')->fetchAll();

$titre = 'Destinations';
$page  = 'destinations';
require __DIR__ . '/includes/entete.php';
?>

<section class="panneau">
    <h2><?= $destination['id'] ? 'Modifier la destination' : 'Ajouter une destination' ?></h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" class="grille-form">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="enregistrer">
        <input type="hidden" name="id" value="<?= e((string) $destination['id']) ?>">
        <div>
            <label for="nom">Destination</label>
            <input type="text" id="nom" name="nom" required maxlength="100"
                   value="<?= e($destination['nom']) ?>" placeholder="Bouaké">
        </div>
        <div>
            <label for="distance_km">Distance depuis le dépôt (km)</label>
            <input type="number" id="distance_km" name="distance_km" required min="1"
                   value="<?= e((string) $destination['distance_km']) ?>">
        </div>
        <div class="actions-form">
            <button type="submit" class="btn btn-auto"><?= $destination['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($destination['id']): ?>
                <a href="destinations.php" class="btn btn-auto btn-gris">Annuler</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="panneau">
    <h2>Liste des destinations (<?= count($destinations) ?>)</h2>
    <table>
        <thead>
            <tr><th>Destination</th><th>Distance (aller)</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($destinations as $d): ?>
                <tr>
                    <td><?= e($d['nom']) ?></td>
                    <td><?= $d['distance_km'] ?> km</td>
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
                <tr><td colspan="3" class="vide">Aucune destination enregistrée.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
