<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs = [];
$commune = ['id' => '', 'nom' => '', 'zone' => (int) ($_GET['zone'] ?? 0) ?: ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'supprimer') {
        db()->prepare('DELETE FROM communes WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('Commune supprimée.');
        header('Location: communes.php');
        exit;
    }

    if ($action === 'enregistrer') {
        $commune = [
            'id'   => (int) ($_POST['id'] ?? 0),
            'nom'  => trim($_POST['nom'] ?? ''),
            'zone' => (int) ($_POST['zone'] ?? 0),
        ];

        if ($commune['nom'] === '')           $erreurs[] = 'Le nom de la commune est obligatoire.';
        if (!isset(ZONES[$commune['zone']])) $erreurs[] = 'Choisissez la zone de la commune.';

        if (!$erreurs) {
            $valeurs = [$commune['nom'], $commune['zone']];
            try {
                if ($commune['id']) {
                    $valeurs[] = $commune['id'];
                    db()->prepare('UPDATE communes SET nom = ?, zone = ? WHERE id = ?')->execute($valeurs);
                    flash('Commune modifiée.');
                } else {
                    db()->prepare('INSERT INTO communes (nom, zone) VALUES (?, ?)')->execute($valeurs);
                    flash('Commune ajoutée à la ' . ZONES[$commune['zone']] . '.');
                }
                // Garde la zone choisie pour enchaîner la saisie des communes d'une même zone
                header('Location: communes.php?zone=' . $commune['zone']);
                exit;
            } catch (PDOException $ex) {
                $erreurs[] = $ex->getCode() === '23000'
                    ? 'Cette commune existe déjà.'
                    : 'Erreur base de données : ' . $ex->getMessage();
            }
        }
    }
} elseif (isset($_GET['modifier'])) {
    $stmt = db()->prepare('SELECT * FROM communes WHERE id = ?');
    $stmt->execute([(int) $_GET['modifier']]);
    $commune = $stmt->fetch() ?: $commune;
}

$parZone = array_fill_keys(array_keys(ZONES), []);
foreach (db()->query('SELECT * FROM communes ORDER BY nom')->fetchAll() as $c) {
    $parZone[$c['zone']][] = $c;
}

$titre = 'Communes';
$page  = 'communes';
require __DIR__ . '/includes/entete.php';
?>

<section class="panneau ne-pas-imprimer">
    <h2><?= $commune['id'] ? 'Modifier la commune' : 'Ajouter une commune' ?></h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" class="grille-form">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="enregistrer">
        <input type="hidden" name="id" value="<?= e((string) $commune['id']) ?>">
        <div>
            <label for="nom">Commune</label>
            <input type="text" id="nom" name="nom" required maxlength="100" autofocus
                   value="<?= e($commune['nom']) ?>" placeholder="Yopougon">
        </div>
        <div>
            <label for="zone">Zone</label>
            <select id="zone" name="zone" required>
                <option value="">— Choisir —</option>
                <?php foreach (ZONES as $num => $nom): ?>
                    <option value="<?= $num ?>" <?= $num == $commune['zone'] ? 'selected' : '' ?>><?= e($nom) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions-form">
            <button type="submit" class="btn btn-auto"><?= $commune['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($commune['id']): ?>
                <a href="communes.php" class="btn btn-auto btn-gris">Annuler</a>
            <?php endif; ?>
        </div>
    </form>
    <p class="petit">Quand on choisit une commune pour un conteneur, sa zone et le prix de livraison sont remplis automatiquement.</p>
</section>

<section class="grille-tarifs">
    <?php foreach ($parZone as $zone => $communes): ?>
        <div class="panneau">
            <h2><?= e(ZONES[$zone]) ?> (<?= count($communes) ?>)</h2>
            <table>
                <tbody>
                    <?php foreach ($communes as $c): ?>
                        <tr>
                            <td><?= e($c['nom']) ?></td>
                            <td class="actions">
                                <a href="?modifier=<?= $c['id'] ?>" class="lien">Modifier</a>
                                <form method="post" onsubmit="return confirm('Supprimer cette commune ?');">
                                    <?= csrf_champ() ?>
                                    <input type="hidden" name="action" value="supprimer">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="lien lien-rouge">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$communes): ?>
                        <tr><td colspan="2" class="vide">Aucune commune.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
