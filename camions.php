<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs = [];
$camion  = ['id' => '', 'immatriculation' => '', 'modele' => '', 'reservoir' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'supprimer') {
        // Un camion en mission ou en maintenance ne peut pas être supprimé
        $id     = (int) ($_POST['id'] ?? 0);
        $occupe = 0;
        foreach (['missions', 'maintenances'] as $table) {
            $stmt = db()->prepare("SELECT COUNT(*) FROM $table WHERE camion_id = ? AND terminee_le IS NULL");
            $stmt->execute([$id]);
            $occupe += (int) $stmt->fetchColumn();
        }
        if ($occupe) {
            flash('Impossible : ce camion est en mission ou en maintenance. Terminez-la d\'abord.', 'erreur');
        } else {
            db()->prepare('DELETE FROM camions WHERE id = ?')->execute([$id]);
            flash('Camion supprimé, ainsi que son historique.');
        }
        header('Location: camions.php');
        exit;
    }

    if ($action === 'enregistrer') {
        $camion = [
            'id'              => (int) ($_POST['id'] ?? 0),
            'immatriculation' => strtoupper(trim($_POST['immatriculation'] ?? '')),
            'modele'          => trim($_POST['modele'] ?? ''),
            'reservoir'       => $_POST['reservoir'] ?? '',
        ];

        if ($camion['immatriculation'] === '') $erreurs[] = 'L\'immatriculation est obligatoire.';
        if ($camion['modele'] === '')          $erreurs[] = 'Le modèle est obligatoire.';
        if (!ctype_digit((string) $camion['reservoir']) || $camion['reservoir'] <= 0) {
            $erreurs[] = 'La capacité du réservoir doit être un nombre entier positif.';
        }

        if (!$erreurs) {
            $valeurs = [$camion['immatriculation'], $camion['modele'], $camion['reservoir']];
            try {
                if ($camion['id']) {
                    $valeurs[] = $camion['id'];
                    db()->prepare('UPDATE camions SET immatriculation = ?, modele = ?, reservoir = ? WHERE id = ?')
                        ->execute($valeurs);
                    flash('Camion modifié.');
                } else {
                    db()->prepare('INSERT INTO camions (immatriculation, modele, reservoir) VALUES (?, ?, ?)')
                        ->execute($valeurs);
                    flash('Camion ajouté.');
                }
                header('Location: camions.php');
                exit;
            } catch (PDOException $ex) {
                $erreurs[] = $ex->getCode() === '23000'
                    ? 'Cette immatriculation existe déjà.'
                    : 'Erreur base de données : ' . $ex->getMessage();
            }
        }
    }
} elseif (isset($_GET['modifier'])) {
    $stmt = db()->prepare('SELECT * FROM camions WHERE id = ?');
    $stmt->execute([(int) $_GET['modifier']]);
    $camion = $stmt->fetch() ?: $camion;
}

$camions = camions_avec_statut();

// Nombre de missions par camion : elles sont supprimées avec lui (et disparaissent des rapports)
$nbMissions = db()->query('SELECT camion_id, COUNT(*) FROM missions GROUP BY camion_id')->fetchAll(PDO::FETCH_KEY_PAIR);

$titre = 'Camions';
$page  = 'camions';
require __DIR__ . '/includes/entete.php';
?>

<section class="panneau">
    <h2><?= $camion['id'] ? 'Modifier le camion' : 'Ajouter un camion' ?></h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" class="grille-form">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="enregistrer">
        <input type="hidden" name="id" value="<?= e((string) $camion['id']) ?>">
        <div>
            <label for="immatriculation">Immatriculation</label>
            <input type="text" id="immatriculation" name="immatriculation" required maxlength="20"
                   value="<?= e($camion['immatriculation']) ?>" placeholder="AB-123-CD">
        </div>
        <div>
            <label for="modele">Modèle</label>
            <input type="text" id="modele" name="modele" required maxlength="100"
                   value="<?= e($camion['modele']) ?>" placeholder="Renault T 480">
        </div>
        <div>
            <label for="reservoir">Réservoir (litres)</label>
            <input type="number" id="reservoir" name="reservoir" required min="1"
                   value="<?= e((string) $camion['reservoir']) ?>">
        </div>
        <div class="actions-form">
            <button type="submit" class="btn btn-auto"><?= $camion['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($camion['id']): ?>
                <a href="camions.php" class="btn btn-auto btn-gris">Annuler</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="panneau">
    <h2>Liste des camions (<?= count($camions) ?>)</h2>
    <table>
        <thead>
            <tr><th>Immatriculation</th><th>Modèle</th><th>Réservoir</th><th>Semi-remorque</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($camions as $c): ?>
                <tr>
                    <td><?= e($c['immatriculation']) ?></td>
                    <td><?= e($c['modele']) ?></td>
                    <td><?= $c['reservoir'] ?> L</td>
                    <td>
                        <?php if ($c['remorque']): ?>
                            <?= e($c['remorque']) ?> <span class="petit"><?= e($c['remorque_type']) ?></span>
                        <?php else: ?>
                            <a href="remorques.php" class="lien petit">Aucune — atteler</a>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a href="?modifier=<?= $c['id'] ?>" class="lien">Modifier</a>
                        <?php if ($c['statut'] === 'disponible'): $nb = (int) ($nbMissions[$c['id']] ?? 0); ?>
                            <form method="post" onsubmit="return confirm('Supprimer ce camion ?<?= $nb ? '\nAttention : ses ' . $nb . ' mission(s) seront aussi supprimées de l\\\'historique et des rapports (carburant et bénéfice).' : '' ?>');">
                                <?= csrf_champ() ?>
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="lien lien-rouge">Supprimer</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-<?= $c['statut'] ?>"><?= LIBELLES_STATUT[$c['statut']] ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$camions): ?>
                <tr><td colspan="5" class="vide">Aucun camion enregistré.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
