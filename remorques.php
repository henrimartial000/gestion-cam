<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

// Types proposés : TYPES_REMORQUE (config.php)

$erreurs  = [];
$remorque = ['id' => '', 'immatriculation' => '', 'type' => '', 'charge_utile' => '', 'camion_id' => ''];

// Statut de chaque camion, indexé par id
$camions = [];
foreach (camions_avec_statut() as $c) {
    $camions[$c['id']] = $c;
}

function en_mission(array $camions, $camionId): bool
{
    return $camionId && isset($camions[$camionId]) && $camions[$camionId]['statut'] === 'mission';
}

function remorque_par_id(int $id)
{
    $stmt = db()->prepare('SELECT * FROM remorques WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';
    $actuelle = remorque_par_id((int) ($_POST['id'] ?? 0));

    if ($action === 'supprimer' || $action === 'deteler') {
        if ($actuelle && en_mission($camions, $actuelle['camion_id'])) {
            flash('Impossible : cette remorque est attelée à un camion en mission.', 'erreur');
        } elseif ($actuelle && $action === 'supprimer') {
            db()->prepare('DELETE FROM remorques WHERE id = ?')->execute([$actuelle['id']]);
            flash('Remorque supprimée.');
        } elseif ($actuelle) {
            db()->prepare('UPDATE remorques SET camion_id = NULL WHERE id = ?')->execute([$actuelle['id']]);
            flash('Remorque dételée : elle est au dépôt.');
        }
        header('Location: remorques.php');
        exit;
    }

    if ($action === 'enregistrer') {
        $remorque = [
            'id'              => $actuelle ? (int) $actuelle['id'] : 0,
            'immatriculation' => strtoupper(trim($_POST['immatriculation'] ?? '')),
            'type'            => $_POST['type'] ?? '',
            'charge_utile'    => str_replace([',', ' '], ['.', ''], trim($_POST['charge_utile'] ?? '')),
            'camion_id'       => (int) ($_POST['camion_id'] ?? 0) ?: null,
        ];
        $ancienCamion = $actuelle ? $actuelle['camion_id'] : null;

        if ($remorque['immatriculation'] === '')               $erreurs[] = 'L\'immatriculation est obligatoire.';
        // Une semi enregistrée avec un ancien type (Citerne, Benne…) peut le garder ; sinon Plateau ou AC
        $typeConserve = $actuelle && $remorque['type'] === $actuelle['type'];
        if (!isset(TYPES_REMORQUE[$remorque['type']]) && !$typeConserve) $erreurs[] = 'Choisissez un type de remorque : Plateau ou Autochargeuse (AC).';
        // Charge utile facultative pour un plateau (laissée vide = non renseignée), obligatoire pour les autres types
        $chargeFacultative = in_array($remorque['type'], TYPES_CHARGE_FACULTATIVE, true);
        if ($remorque['charge_utile'] === '' && $chargeFacultative) {
            $remorque['charge_utile'] = null;
        } elseif (!is_numeric($remorque['charge_utile']) || $remorque['charge_utile'] <= 0 || $remorque['charge_utile'] > 100) {
            $erreurs[] = $remorque['charge_utile'] === ''
                ? 'La charge utile est obligatoire pour ce type de semi-remorque.'
                : 'La charge utile doit être un nombre entre 0 et 100 tonnes.';
        }
        if ($remorque['camion_id'] && !isset($camions[$remorque['camion_id']])) {
            $erreurs[] = 'Camion introuvable.';
        }
        if ($remorque['camion_id'] != $ancienCamion) {
            if (en_mission($camions, $ancienCamion))          $erreurs[] = 'Cette remorque est attelée à un camion en mission : terminez d\'abord la mission.';
            if (en_mission($camions, $remorque['camion_id'])) $erreurs[] = 'Le camion choisi est en mission : impossible de changer sa remorque.';
        }

        if (!$erreurs) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                // Un tracteur ne tire qu'une remorque : on dételle l'ancienne
                if ($remorque['camion_id']) {
                    $pdo->prepare('UPDATE remorques SET camion_id = NULL WHERE camion_id = ? AND id <> ?')
                        ->execute([$remorque['camion_id'], $remorque['id']]);
                }
                $valeurs = [$remorque['immatriculation'], $remorque['type'], $remorque['charge_utile'], $remorque['camion_id']];
                if ($remorque['id']) {
                    $valeurs[] = $remorque['id'];
                    $pdo->prepare('UPDATE remorques SET immatriculation = ?, type = ?, charge_utile = ?, camion_id = ? WHERE id = ?')
                        ->execute($valeurs);
                    flash('Remorque modifiée.');
                } else {
                    $pdo->prepare('INSERT INTO remorques (immatriculation, type, charge_utile, camion_id) VALUES (?, ?, ?, ?)')
                        ->execute($valeurs);
                    flash('Remorque ajoutée.');
                }
                $pdo->commit();
                header('Location: remorques.php');
                exit;
            } catch (PDOException $ex) {
                $pdo->rollBack();
                $erreurs[] = $ex->getCode() === '23000'
                    ? 'Cette immatriculation existe déjà.'
                    : 'Erreur base de données : ' . $ex->getMessage();
            }
        }
    }
} elseif (isset($_GET['modifier'])) {
    $remorque = remorque_par_id((int) $_GET['modifier']) ?: $remorque;
}

$remorques = db()->query(
    'SELECT r.*, c.immatriculation AS camion, c.modele
     FROM remorques r LEFT JOIN camions c ON c.id = r.camion_id
     ORDER BY r.immatriculation'
)->fetchAll();

$attelees = 0;
foreach ($remorques as $r) {
    if ($r['camion_id']) $attelees++;
}

$titre = 'Remorques';
$page  = 'remorques';
require __DIR__ . '/includes/entete.php';
?>

<section class="stats">
    <div class="stat"><span><?= count($remorques) ?></span>Remorques</div>
    <div class="stat stat-bleu"><span><?= $attelees ?></span>Attelées</div>
    <div class="stat stat-vert"><span><?= count($remorques) - $attelees ?></span>Au dépôt</div>
</section>

<section class="panneau">
    <h2><?= $remorque['id'] ? 'Modifier la remorque' : 'Ajouter une semi-remorque' ?></h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" class="grille-form">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="enregistrer">
        <input type="hidden" name="id" value="<?= e((string) $remorque['id']) ?>">
        <div>
            <label for="immatriculation">Immatriculation</label>
            <input type="text" id="immatriculation" name="immatriculation" required maxlength="20"
                   value="<?= e($remorque['immatriculation']) ?>" placeholder="RA-111-AA">
        </div>
        <div>
            <label for="type">Type</label>
            <select id="type" name="type" required>
                <option value="">— Choisir —</option>
                <?php foreach (TYPES_REMORQUE as $valeur => $libelle): ?>
                    <option value="<?= e($valeur) ?>" <?= $valeur === $remorque['type'] ? 'selected' : '' ?>><?= e($libelle) ?></option>
                <?php endforeach; ?>
                <?php if ($remorque['type'] !== '' && !isset(TYPES_REMORQUE[$remorque['type']])): ?>
                    <option value="<?= e($remorque['type']) ?>" selected><?= e($remorque['type']) ?> (ancien type)</option>
                <?php endif; ?>
            </select>
        </div>
        <div>
            <?php $chargeFacultative = in_array($remorque['type'], TYPES_CHARGE_FACULTATIVE, true); ?>
            <label for="charge_utile">Charge utile (tonnes)<span id="charge-facultative" class="petit"<?= $chargeFacultative ? '' : ' hidden' ?>> – facultative</span></label>
            <input type="number" id="charge_utile" name="charge_utile" step="0.1" min="0.1" max="100" <?= $chargeFacultative ? '' : 'required' ?>
                   value="<?= e((string) $remorque['charge_utile']) ?>">
            <script>
                // Plateau : charge utile facultative ; autres types : obligatoire
                (function () {
                    var type = document.getElementById('type'), charge = document.getElementById('charge_utile');
                    var mention = document.getElementById('charge-facultative');
                    var facultatifs = <?= json_encode(TYPES_CHARGE_FACULTATIVE) ?>;
                    type.addEventListener('change', function () {
                        var facultative = facultatifs.indexOf(type.value) !== -1;
                        charge.required = !facultative;
                        mention.hidden = !facultative;
                    });
                })();
            </script>
        </div>
        <div>
            <label for="camion_id">Attelée au camion</label>
            <select id="camion_id" name="camion_id">
                <option value="">— Aucun (au dépôt) —</option>
                <?php foreach ($camions as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $c['id'] == $remorque['camion_id'] ? 'selected' : '' ?>>
                        <?= e($c['immatriculation']) ?> – <?= e($c['modele']) ?>
                        <?php if ($c['remorque'] && $c['remorque_id'] != $remorque['id']): ?>(tire <?= e($c['remorque']) ?>)<?php endif; ?>
                        <?= $c['statut'] === 'mission' ? '— en mission' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions-form">
            <button type="submit" class="btn btn-auto"><?= $remorque['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($remorque['id']): ?>
                <a href="remorques.php" class="btn btn-auto btn-gris">Annuler</a>
            <?php endif; ?>
        </div>
    </form>
    <p class="petit">Si le camion choisi tire déjà une remorque, celle-ci est automatiquement dételée et revient au dépôt.</p>
</section>

<section class="panneau">
    <h2>Liste des semi-remorques</h2>
    <table>
        <thead>
            <tr><th>Immatriculation</th><th>Type</th><th>Charge utile</th><th>Attelage</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($remorques as $r): ?>
                <tr>
                    <td><b><?= e($r['immatriculation']) ?></b></td>
                    <td><?= e(TYPES_REMORQUE[$r['type']] ?? $r['type']) ?></td>
                    <td><?= $r['charge_utile'] !== null ? (float) $r['charge_utile'] . ' t' : '<span class="petit">—</span>' ?></td>
                    <td>
                        <?php if ($r['camion_id']): ?>
                            <?= e($r['camion']) ?> <span class="petit"><?= e($r['modele']) ?></span>
                            <?php if (en_mission($camions, $r['camion_id'])): ?>
                                <span class="badge badge-mission">En mission</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-disponible">Au dépôt</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a href="?modifier=<?= $r['id'] ?>" class="lien">Modifier</a>
                        <?php if ($r['camion_id'] && !en_mission($camions, $r['camion_id'])): ?>
                            <form method="post">
                                <?= csrf_champ() ?>
                                <input type="hidden" name="action" value="deteler">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="lien">Dételer</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Supprimer cette remorque ?');">
                            <?= csrf_champ() ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="lien lien-rouge">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$remorques): ?>
                <tr><td colspan="5" class="vide">Aucune remorque enregistrée.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
