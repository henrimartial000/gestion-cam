<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs   = [];
$vide      = ['id' => '', 'nom' => '', 'prenom' => '', 'telephone' => '', 'telephone2' => '',
              'email' => '', 'ville' => '', 'adresse' => '', 'numero_permis' => ''];
$chauffeur = $vide;

function telephone_valide(string $tel): bool
{
    return (bool) preg_match('/^\+?[0-9 .\-()]{6,30}$/', $tel);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'supprimer') {
        $stmt = db()->prepare('SELECT COUNT(*) FROM missions WHERE chauffeur_id = ? AND terminee_le IS NULL');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) {
            flash('Impossible : ce chauffeur est en mission.', 'erreur');
        } else {
            db()->prepare('DELETE FROM chauffeurs WHERE id = ?')->execute([$id]);
            flash('Chauffeur supprimé.');
        }
        header('Location: chauffeurs.php');
        exit;
    }

    if ($action === 'enregistrer') {
        $chauffeur = ['id' => $id];
        foreach (array_keys($vide) as $champ) {
            if ($champ !== 'id') $chauffeur[$champ] = trim($_POST[$champ] ?? '');
        }
        $chauffeur['nom'] = mb_strtoupper($chauffeur['nom'], 'UTF-8');

        if ($chauffeur['nom'] === '')    $erreurs[] = 'Le nom est obligatoire.';
        if ($chauffeur['prenom'] === '') $erreurs[] = 'Le prénom est obligatoire.';
        if (!telephone_valide($chauffeur['telephone'])) $erreurs[] = 'Le téléphone principal est invalide.';
        if ($chauffeur['telephone2'] !== '' && !telephone_valide($chauffeur['telephone2'])) {
            $erreurs[] = 'Le second téléphone est invalide.';
        }
        if ($chauffeur['email'] !== '' && !filter_var($chauffeur['email'], FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'L\'adresse e-mail est invalide.';
        }
        if ($chauffeur['ville'] === '') $erreurs[] = 'Le lieu d\'habitation (ville) est obligatoire.';

        if (!$erreurs) {
            // Les champs facultatifs vides sont enregistrés comme NULL
            $valeurs = [];
            foreach (['nom', 'prenom', 'telephone', 'telephone2', 'email', 'ville', 'adresse', 'numero_permis'] as $champ) {
                $valeurs[] = $chauffeur[$champ] === '' ? null : $chauffeur[$champ];
            }
            try {
                if ($id) {
                    $valeurs[] = $id;
                    db()->prepare('UPDATE chauffeurs SET nom = ?, prenom = ?, telephone = ?, telephone2 = ?, email = ?,
                                   ville = ?, adresse = ?, numero_permis = ? WHERE id = ?')->execute($valeurs);
                    flash('Chauffeur modifié.');
                } else {
                    db()->prepare('INSERT INTO chauffeurs (nom, prenom, telephone, telephone2, email, ville, adresse, numero_permis)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($valeurs);
                    flash('Chauffeur ajouté.');
                }
                header('Location: chauffeurs.php');
                exit;
            } catch (PDOException $ex) {
                $erreurs[] = $ex->getCode() === '23000'
                    ? 'Ce numéro de téléphone est déjà utilisé par un autre chauffeur.'
                    : 'Erreur base de données : ' . $ex->getMessage();
            }
        }
    }
} elseif (isset($_GET['modifier'])) {
    $stmt = db()->prepare('SELECT * FROM chauffeurs WHERE id = ?');
    $stmt->execute([(int) $_GET['modifier']]);
    $trouve = $stmt->fetch();
    if ($trouve) {
        $chauffeur = array_map(function ($v) { return (string) $v; }, $trouve);
    }
}

$chauffeurs = chauffeurs_avec_statut();
$enMission  = 0;
foreach ($chauffeurs as $ch) {
    if ($ch['mission_id']) $enMission++;
}

// Recherche par nom, téléphone ou lieu d'habitation
$q = trim($_GET['q'] ?? '');
$liste = $chauffeurs;
if ($q !== '') {
    $liste = array_filter($chauffeurs, function ($ch) use ($q) {
        $texte = implode(' ', [$ch['nom'], $ch['prenom'], $ch['telephone'], $ch['telephone2'], $ch['ville'], $ch['adresse']]);
        return mb_stripos($texte, $q, 0, 'UTF-8') !== false;
    });
}

$titre = 'Chauffeurs';
$page  = 'chauffeurs';
require __DIR__ . '/includes/entete.php';
?>

<section class="stats">
    <div class="stat"><span><?= count($chauffeurs) ?></span>Chauffeurs</div>
    <div class="stat stat-bleu"><span><?= $enMission ?></span>En mission</div>
    <div class="stat stat-vert"><span><?= count($chauffeurs) - $enMission ?></span>Disponibles</div>
</section>

<section class="panneau">
    <h2><?= $chauffeur['id'] ? 'Modifier le chauffeur' : 'Ajouter un chauffeur' ?></h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="enregistrer">
        <input type="hidden" name="id" value="<?= e((string) $chauffeur['id']) ?>">

        <h3 class="sous-section">Identité</h3>
        <div class="grille-form">
            <div>
                <label for="nom">Nom *</label>
                <input type="text" id="nom" name="nom" required maxlength="100" value="<?= e($chauffeur['nom']) ?>">
            </div>
            <div>
                <label for="prenom">Prénom *</label>
                <input type="text" id="prenom" name="prenom" required maxlength="100" value="<?= e($chauffeur['prenom']) ?>">
            </div>
            <div>
                <label for="numero_permis">N° de permis</label>
                <input type="text" id="numero_permis" name="numero_permis" maxlength="50" value="<?= e($chauffeur['numero_permis']) ?>">
            </div>
        </div>

        <h3 class="sous-section">Contact</h3>
        <div class="grille-form">
            <div>
                <label for="telephone">Téléphone principal *</label>
                <input type="tel" id="telephone" name="telephone" required maxlength="30"
                       value="<?= e($chauffeur['telephone']) ?>" placeholder="+225 07 00 00 00 00">
            </div>
            <div>
                <label for="telephone2">Second téléphone</label>
                <input type="tel" id="telephone2" name="telephone2" maxlength="30" value="<?= e($chauffeur['telephone2']) ?>">
            </div>
            <div>
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" maxlength="150" value="<?= e($chauffeur['email']) ?>">
            </div>
        </div>

        <h3 class="sous-section">Lieu d'habitation</h3>
        <div class="grille-form">
            <div>
                <label for="ville">Ville *</label>
                <input type="text" id="ville" name="ville" required maxlength="100" value="<?= e($chauffeur['ville']) ?>">
            </div>
            <div class="large">
                <label for="adresse">Quartier, rue, point de repère</label>
                <input type="text" id="adresse" name="adresse" maxlength="255"
                       value="<?= e($chauffeur['adresse']) ?>" placeholder="Cocody, Riviera 2, près de la pharmacie">
            </div>
        </div>

        <div class="actions-form">
            <button type="submit" class="btn btn-auto"><?= $chauffeur['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($chauffeur['id']): ?>
                <a href="chauffeurs.php" class="btn btn-auto btn-gris">Annuler</a>
            <?php endif; ?>
        </div>
        <p class="petit">* champs obligatoires</p>
    </form>
</section>

<section class="panneau">
    <div class="titre-recherche">
        <h2>Liste des chauffeurs</h2>
        <form method="get" class="recherche">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Rechercher : nom, téléphone, ville…">
            <?php if ($q !== ''): ?><a href="chauffeurs.php" class="lien petit">Effacer</a><?php endif; ?>
        </form>
    </div>
    <table>
        <thead>
            <tr><th>Chauffeur</th><th>Contact</th><th>Lieu d'habitation</th><th>Statut</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($liste as $ch): ?>
                <tr>
                    <td>
                        <b><?= e($ch['nom']) ?></b> <?= e($ch['prenom']) ?>
                        <?php if ($ch['numero_permis']): ?><br><span class="petit">Permis <?= e($ch['numero_permis']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $ch['telephone'])) ?>" class="lien"><?= e($ch['telephone']) ?></a>
                        <?php if ($ch['telephone2']): ?><br><span class="petit"><?= e($ch['telephone2']) ?></span><?php endif; ?>
                        <?php if ($ch['email']): ?><br><a href="mailto:<?= e($ch['email']) ?>" class="lien petit"><?= e($ch['email']) ?></a><?php endif; ?>
                    </td>
                    <td>
                        <?= e($ch['ville']) ?>
                        <?php if ($ch['adresse']): ?><br><span class="petit"><?= e($ch['adresse']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ch['mission_id']): ?>
                            <span class="badge badge-mission">En mission</span>
                            <br><span class="petit"><?= e($ch['camion'] ?? '') ?> → <?= e($ch['destination'] ?? '?') ?></span>
                        <?php else: ?>
                            <span class="badge badge-disponible">Disponible</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <a href="?modifier=<?= $ch['id'] ?>" class="lien">Modifier</a>
                        <?php if (!$ch['mission_id']): ?>
                            <form method="post" onsubmit="return confirm('Supprimer ce chauffeur ?');">
                                <?= csrf_champ() ?>
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="id" value="<?= $ch['id'] ?>">
                                <button type="submit" class="lien lien-rouge">Supprimer</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$liste): ?>
                <tr><td colspan="5" class="vide"><?= $q !== '' ? 'Aucun chauffeur ne correspond à la recherche.' : 'Aucun chauffeur enregistré.' ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
