<?php
// Gestion des comptes (réservé aux administrateurs)
require_once __DIR__ . '/config.php';
exiger_admin();

$erreurs = [];
$vide    = ['id' => '', 'identifiant' => '', 'nom_complet' => '', 'role' => 'exploitation', 'actif' => 1];
$compte  = $vide;
$moi     = (int) $_SESSION['user_id'];

function utilisateur_par_id(int $id)
{
    $stmt = db()->prepare('SELECT * FROM utilisateurs WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Nombre d'administrateurs actifs, sans compter $saufId
function autres_admins_actifs(int $saufId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'admin' AND actif = 1 AND id <> ?");
    $stmt->execute([$saufId]);
    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action  = $_POST['action'] ?? '';
    $id      = (int) ($_POST['id'] ?? 0);
    $actuel  = $id ? utilisateur_par_id($id) : null;

    if ($action === 'supprimer' && $actuel) {
        if ($id === $moi) {
            flash('Vous ne pouvez pas supprimer votre propre compte.', 'erreur');
        } elseif ($actuel['role'] === 'admin' && !autres_admins_actifs($id)) {
            flash('Impossible : il doit rester au moins un administrateur actif.', 'erreur');
        } else {
            db()->prepare('DELETE FROM utilisateurs WHERE id = ?')->execute([$id]);
            flash('Compte « ' . $actuel['identifiant'] . ' » supprimé.');
        }
        header('Location: utilisateurs.php');
        exit;
    }

    if ($action === 'enregistrer') {
        $compte = [
            'id'          => $actuel ? $id : '',
            'identifiant' => trim($_POST['identifiant'] ?? ''),
            'nom_complet' => trim($_POST['nom_complet'] ?? ''),
            'role'        => $_POST['role'] ?? '',
            'actif'       => isset($_POST['actif']) ? 1 : 0,
        ];
        $motDePasse   = $_POST['mot_de_passe'] ?? '';
        $confirmation = $_POST['confirmation'] ?? '';

        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $compte['identifiant'])) {
            $erreurs[] = 'L\'identifiant doit faire de 3 à 50 caractères : lettres, chiffres, point, tiret ou souligné (sans espace ni accent).';
        }
        if (!isset(ROLES[$compte['role']])) $erreurs[] = 'Choisissez un rôle.';
        if (!$actuel && strlen($motDePasse) < 8) $erreurs[] = 'Le mot de passe doit faire au moins 8 caractères.';
        if ($actuel && $motDePasse !== '' && strlen($motDePasse) < 8) $erreurs[] = 'Le nouveau mot de passe doit faire au moins 8 caractères.';
        if ($motDePasse !== $confirmation) $erreurs[] = 'La confirmation du mot de passe ne correspond pas.';

        // Protections : pas d'auto-rétrogradation, toujours un administrateur actif
        if ($actuel && $id === $moi && ($compte['role'] !== 'admin' || !$compte['actif'])) {
            $erreurs[] = 'Vous ne pouvez pas retirer vos propres droits d\'administrateur ni désactiver votre compte.';
        } elseif ($actuel && $actuel['role'] === 'admin' && ($compte['role'] !== 'admin' || !$compte['actif']) && !autres_admins_actifs($id)) {
            $erreurs[] = 'Il doit rester au moins un administrateur actif.';
        }

        if (!$erreurs) {
            try {
                if ($actuel) {
                    db()->prepare('UPDATE utilisateurs SET identifiant = ?, nom_complet = ?, role = ?, actif = ? WHERE id = ?')
                        ->execute([$compte['identifiant'], $compte['nom_complet'] ?: null, $compte['role'], $compte['actif'], $id]);
                    if ($motDePasse !== '') {
                        // Nouveau mot de passe fixé par l'administrateur : l'ancien code de récupération ne sert plus
                        db()->prepare('UPDATE utilisateurs SET mot_de_passe = ?, code_recuperation = NULL WHERE id = ?')
                            ->execute([password_hash($motDePasse, PASSWORD_DEFAULT), $id]);
                    }
                    flash('Compte « ' . $compte['identifiant'] . ' » modifié.');
                } else {
                    db()->prepare('INSERT INTO utilisateurs (identifiant, nom_complet, role, actif, mot_de_passe) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$compte['identifiant'], $compte['nom_complet'] ?: null, $compte['role'], $compte['actif'],
                                   password_hash($motDePasse, PASSWORD_DEFAULT)]);
                    flash('Compte « ' . $compte['identifiant'] . ' » créé. Communiquez-lui son mot de passe : il pourra le changer dans « Mon compte ».');
                }
                header('Location: utilisateurs.php');
                exit;
            } catch (PDOException $ex) {
                $erreurs[] = $ex->getCode() === '23000'
                    ? 'Cet identifiant est déjà utilisé.'
                    : 'Erreur base de données : ' . $ex->getMessage();
            }
        }
    }
} elseif (isset($_GET['modifier'])) {
    $trouve = utilisateur_par_id((int) $_GET['modifier']);
    if ($trouve) $compte = $trouve;
}

$comptes = db()->query('SELECT * FROM utilisateurs ORDER BY actif DESC, identifiant')->fetchAll();

$titre = 'Utilisateurs';
$page  = 'utilisateurs';
require __DIR__ . '/includes/entete.php';
?>

<section class="panneau">
    <h2><?= $compte['id'] ? 'Modifier le compte « ' . e($compte['identifiant']) . ' »' : 'Créer un compte' ?></h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="enregistrer">
        <input type="hidden" name="id" value="<?= e((string) $compte['id']) ?>">

        <div class="grille-form">
            <div>
                <label for="identifiant">Identifiant de connexion *</label>
                <input type="text" id="identifiant" name="identifiant" required maxlength="50"
                       value="<?= e($compte['identifiant']) ?>" placeholder="ex. k.traore">
            </div>
            <div>
                <label for="nom_complet">Nom affiché</label>
                <input type="text" id="nom_complet" name="nom_complet" maxlength="100"
                       value="<?= e((string) $compte['nom_complet']) ?>" placeholder="ex. Karim Traoré">
            </div>
            <div>
                <label for="role">Rôle *</label>
                <select id="role" name="role" required>
                    <?php foreach (ROLES as $cle => $libelle): ?>
                        <option value="<?= $cle ?>" <?= $cle === $compte['role'] ? 'selected' : '' ?>><?= $libelle ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="mot_de_passe"><?= $compte['id'] ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe *' ?></label>
                <input type="password" id="mot_de_passe" name="mot_de_passe" minlength="8" <?= $compte['id'] ? '' : 'required' ?> autocomplete="new-password">
            </div>
            <div>
                <label for="confirmation">Confirmer le mot de passe</label>
                <input type="password" id="confirmation" name="confirmation" minlength="8" autocomplete="new-password">
            </div>
            <div class="case">
                <label><input type="checkbox" name="actif" value="1" <?= $compte['actif'] ? 'checked' : '' ?>> Compte actif</label>
            </div>
        </div>

        <div class="actions-form">
            <button type="submit" class="btn btn-auto"><?= $compte['id'] ? 'Enregistrer' : 'Créer le compte' ?></button>
            <?php if ($compte['id']): ?>
                <a href="utilisateurs.php" class="btn btn-auto btn-gris">Annuler</a>
            <?php endif; ?>
        </div>
    </form>

    <ul class="details roles-explication">
        <li><span class="badge-role role-admin">Administrateur</span> tout faire, y compris gérer les comptes et les réglages.</li>
        <li><span class="badge-role role-exploitation">Exploitation</span> gérer missions, maintenances, camions, remorques, chauffeurs et destinations.</li>
        <li><span class="badge-role role-consultation">Consultation</span> tout voir et imprimer, sans rien modifier.</li>
    </ul>
</section>

<section class="panneau">
    <h2>Comptes (<?= count($comptes) ?>)</h2>
    <table>
        <thead>
            <tr><th>Identifiant</th><th>Nom</th><th>Rôle</th><th>État</th><th>Dernière connexion</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($comptes as $c): ?>
                <tr class="<?= $c['actif'] ? '' : 'compte-inactif' ?>">
                    <td><b><?= e($c['identifiant']) ?></b><?= (int) $c['id'] === $moi ? ' <span class="petit">(vous)</span>' : '' ?></td>
                    <td><?= e((string) $c['nom_complet']) ?: '—' ?></td>
                    <td><span class="badge-role role-<?= e($c['role']) ?>"><?= ROLES[$c['role']] ?? e($c['role']) ?></span></td>
                    <td><?= $c['actif'] ? '<span class="badge badge-disponible">Actif</span>' : '<span class="badge">Désactivé</span>' ?></td>
                    <td><?= $c['derniere_connexion'] ? date('d/m/Y H:i', strtotime($c['derniere_connexion'])) : '<span class="petit">jamais</span>' ?></td>
                    <td class="actions">
                        <a href="?modifier=<?= $c['id'] ?>" class="lien">Modifier</a>
                        <?php if ((int) $c['id'] !== $moi): ?>
                            <form method="post" onsubmit="return confirm('Supprimer définitivement le compte « <?= e($c['identifiant']) ?> » ?\nSon nom restera dans l\'historique des missions.');">
                                <?= csrf_champ() ?>
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="lien lien-rouge">Supprimer</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="petit">Pour bloquer temporairement quelqu'un sans perdre son compte, décochez « Compte actif ». Il est déconnecté immédiatement.</p>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
