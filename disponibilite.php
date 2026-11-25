<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs = [];

function date_valide(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function trouver_camion(int $camionId)
{
    foreach (camions_avec_statut() as $c) {
        if ((int) $c['id'] === $camionId) return $c;
    }
    return null;
}

// Un camion affecté à un conteneur pas encore livré n'est pas libre pour une mission
function est_disponible(int $camionId): bool
{
    $c = trouver_camion($camionId);
    return $c && $c['statut'] === 'disponible' && !isset(engins_sur_conteneur('camion_id')[$camionId]);
}

// Camions libres pour une mission, indexés par id
function camions_disponibles(): array
{
    $surConteneur = engins_sur_conteneur('camion_id');
    $libres = [];
    foreach (camions_avec_statut() as $c) {
        if ($c['statut'] === 'disponible' && !isset($surConteneur[$c['id']])) $libres[$c['id']] = $c;
    }
    return $libres;
}

// Semi-remorques utilisables pour une mission : au dépôt, ou attelées à un camion libre ; jamais sur un conteneur à livrer
function remorques_libres(array $camionsLibres): array
{
    $surConteneur = engins_sur_conteneur('remorque_id');
    $libres = [];
    foreach (db()->query('SELECT r.*, c.immatriculation AS camion FROM remorques r
                          LEFT JOIN camions c ON c.id = r.camion_id ORDER BY r.immatriculation')->fetchAll() as $r) {
        if (isset($surConteneur[$r['id']])) continue;
        if ($r['camion_id'] && !isset($camionsLibres[$r['camion_id']])) continue;
        $libres[$r['id']] = $r;
    }
    return $libres;
}

// À appeler dans une transaction, après verrouiller_disponibilite : verrouille la semi choisie et celle du camion,
// puis revérifie l'attelage (camion attelé = occupé par sa semi) et que la semi n'est pas sur un conteneur à livrer
function verrouiller_remorque(int $remorqueId, int $camionId): ?string
{
    $pdo = db();
    $pdo->prepare('SELECT id FROM remorques WHERE id = ? OR camion_id = ? FOR UPDATE')->execute([$remorqueId, $camionId]);
    if ($remorqueId) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM remorques WHERE id = ?');
        $stmt->execute([$remorqueId]);
        if (!$stmt->fetchColumn()) return 'Semi-remorque introuvable.';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM conteneurs WHERE remorque_id = ? AND livre_le IS NULL');
        $stmt->execute([$remorqueId]);
        if ($stmt->fetchColumn()) return 'Cette semi-remorque vient d\'être prise pour un conteneur.';
    }
    return erreur_attelage($camionId, $remorqueId);
}

// À appeler dans une transaction : verrouille le camion (et le chauffeur) puis revérifie qu'ils sont libres.
// Si deux personnes valident en même temps, la seconde attend la première puis reçoit un refus.
function verrouiller_disponibilite(int $camionId, int $chauffeurId = 0): ?string
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM camions WHERE id = ? FOR UPDATE');
    $stmt->execute([$camionId]);
    if (!$stmt->fetch()) return 'Camion introuvable.';

    foreach (['missions', 'maintenances'] as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE camion_id = ? AND terminee_le IS NULL FOR UPDATE");
        $stmt->execute([$camionId]);
        if ($stmt->fetchColumn() > 0) return 'Ce camion vient d\'être pris par un autre utilisateur : il n\'est plus disponible.';
    }

    if ($chauffeurId) {
        $pdo->prepare('SELECT id FROM chauffeurs WHERE id = ? FOR UPDATE')->execute([$chauffeurId]);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM missions WHERE chauffeur_id = ? AND terminee_le IS NULL FOR UPDATE');
        $stmt->execute([$chauffeurId]);
        if ($stmt->fetchColumn() > 0) return 'Ce chauffeur vient d\'être envoyé en mission par un autre utilisateur.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action   = $_POST['action'] ?? '';
    $camionId = (int) ($_POST['camion_id'] ?? 0);

    if ($action === 'mission') {
        $destinationId = (int) ($_POST['destination_id'] ?? 0);
        $chauffeurId   = (int) ($_POST['chauffeur_id'] ?? 0);
        $chauffeur     = null;
        foreach (chauffeurs_avec_statut() as $ch) {
            if ((int) $ch['id'] === $chauffeurId) $chauffeur = $ch;
        }
        $retour        = $_POST['retour_prevu'] ?? '';
        $sensAller     = ($_POST['aller'] ?? '') === 'vide' ? 'vide' : 'charge';
        $sensRetour    = in_array($_POST['retour'] ?? '', ['charge', 'vide', 'aucun'], true) ? $_POST['retour'] : 'vide';
        $montant       = str_replace([',', ' '], ['.', ''], trim($_POST['montant'] ?? '0')) ?: '0';
        $frais         = str_replace([',', ' '], ['.', ''], trim($_POST['frais'] ?? '0')) ?: '0';
        // Semi-remorque choisie en même temps que le camion (0 = sans semi)
        $remorqueId    = (int) ($_POST['remorque_id'] ?? 0);

        $stmt = db()->prepare('SELECT * FROM destinations WHERE id = ?');
        $stmt->execute([$destinationId]);
        $destination = $stmt->fetch();

        if (!est_disponible($camionId))             $erreurs[] = 'Ce camion n\'est pas disponible.';
        elseif ($remorqueId && !isset(remorques_libres(camions_disponibles())[$remorqueId])) {
            $erreurs[] = 'Cette semi-remorque n\'est pas disponible.';
        } elseif ($erreurAttelage = erreur_attelage($camionId, $remorqueId)) {
            // Camion déjà attelé : il part avec sa semi ; semi déjà attelée : elle part avec son camion
            $erreurs[] = $erreurAttelage;
        }
        if (!$destination)                          $erreurs[] = 'Choisissez une destination.';
        if (!is_numeric($montant) || $montant < 0)  $erreurs[] = 'Le montant facturé doit être un nombre positif.';
        if (!is_numeric($frais) || $frais < 0)      $erreurs[] = 'Les frais doivent être un nombre positif.';
        if (!$chauffeur)                            $erreurs[] = 'Choisissez un chauffeur.';
        elseif ($chauffeur['mission_id'])           $erreurs[] = 'Ce chauffeur est déjà en mission.';
        if ($retour !== '' && !date_valide($retour)) $erreurs[] = 'Date de retour invalide.';
        elseif ($retour !== '' && $retour < date('Y-m-d')) $erreurs[] = 'La date de retour prévue ne peut pas être passée.';

        if (!$erreurs) {
            // Le nom est aussi copié en texte pour l'historique, même si le chauffeur est supprimé plus tard
            $nomChauffeur = $chauffeur['prenom'] . ' ' . $chauffeur['nom'];
            $calcul = calcul_carburant((int) $destination['distance_km'], $sensAller, $sensRetour);
            $params = parametres();
            $pdo    = db();
            $pdo->beginTransaction();
            $refus = verrouiller_disponibilite($camionId, $chauffeurId);
            if (!$refus) $refus = verrouiller_remorque($remorqueId, $camionId);
            if ($refus) {
                $pdo->rollBack();
                $erreurs[] = $refus;
            } else {
                // Camion et semi libres : l'attelage est créé automatiquement ; on en garde la trace dans la mission
                if ($remorqueId) {
                    $pdo->prepare('UPDATE remorques SET camion_id = ? WHERE id = ?')->execute([$camionId, $remorqueId]);
                }
                $pdo->prepare('INSERT INTO missions (camion_id, remorque_id, chauffeur_id, destination_id, chauffeur, retour_prevu,
                                   aller_etat, retour_etat, distance_km, carburant_l, prix_litre, montant, frais, cree_par)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$camionId, $remorqueId ?: null, $chauffeurId, $destinationId, $nomChauffeur, $retour ?: null,
                               $sensAller, $sensRetour, $calcul['km'], $calcul['litres'], $params['prix_litre'], $montant, $frais,
                               $_SESSION['nom']]);
                $pdo->commit();
                flash('Mission enregistrée : ' . litres($calcul['litres']) . ' de carburant prévus, soit '
                    . montant($calcul['litres'] * $params['prix_litre'], $params['devise'])
                    . '. Le bon de mission est disponible avec le lien « Bon ».');
                header('Location: disponibilite.php');
                exit;
            }
        }
    }

    if ($action === 'maintenance') {
        $motif = trim($_POST['motif'] ?? '');
        $fin   = $_POST['fin_prevue'] ?? '';

        if (!est_disponible($camionId))        $erreurs[] = 'Ce camion n\'est pas disponible.';
        if ($motif === '')                     $erreurs[] = 'Le motif est obligatoire.';
        if ($fin !== '' && !date_valide($fin)) $erreurs[] = 'Date de fin invalide.';
        elseif ($fin !== '' && $fin < date('Y-m-d')) $erreurs[] = 'La date de fin prévue ne peut pas être passée.';

        if (!$erreurs) {
            $pdo = db();
            $pdo->beginTransaction();
            $refus = verrouiller_disponibilite($camionId);
            if ($refus) {
                $pdo->rollBack();
                $erreurs[] = $refus;
            } else {
                $pdo->prepare('INSERT INTO maintenances (camion_id, motif, fin_prevue, cree_par) VALUES (?, ?, ?, ?)')
                    ->execute([$camionId, $motif, $fin ?: null, $_SESSION['nom']]);
                $pdo->commit();
                flash('Camion mis en maintenance.');
                header('Location: disponibilite.php');
                exit;
            }
        }
    }

    // Suppression d'une ligne de l'historique (seulement si elle est terminée)
    if ($action === 'supprimer_historique') {
        $table = ($_POST['type'] ?? '') === 'maintenance' ? 'maintenances' : 'missions';
        $stmt  = db()->prepare("DELETE FROM $table WHERE id = ? AND terminee_le IS NOT NULL");
        $stmt->execute([(int) $_POST['id']]);
        flash($stmt->rowCount() ? 'Ligne supprimée de l\'historique.' : 'Rien à supprimer.');
        header('Location: disponibilite.php' . (!empty($_POST['tout']) ? '?historique=tout#historique' : '#historique'));
        exit;
    }

    // Suppression de tout l'historique terminé avant une date
    if ($action === 'purger_historique') {
        $avant = $_POST['avant'] ?? '';
        if (!date_valide($avant)) {
            $erreurs[] = 'Choisissez une date valide pour vider l\'historique.';
        } else {
            $nb = 0;
            foreach ($_POST['types'] ?? [] as $type) {
                if (!in_array($type, ['missions', 'maintenances'], true)) continue;
                $stmt = db()->prepare("DELETE FROM $type WHERE terminee_le IS NOT NULL AND terminee_le < ?");
                $stmt->execute([$avant]);
                $nb += $stmt->rowCount();
            }
            flash($nb . ' ligne(s) supprimée(s) de l\'historique.');
            header('Location: disponibilite.php#historique');
            exit;
        }
    }

    if ($action === 'terminer_mission' || $action === 'terminer_maintenance') {
        $table = $action === 'terminer_mission' ? 'missions' : 'maintenances';
        db()->prepare("UPDATE $table SET terminee_le = NOW(), terminee_par = ? WHERE id = ? AND terminee_le IS NULL")
            ->execute([$_SESSION['nom'], (int) $_POST['id']]);
        flash($action === 'terminer_mission' ? 'Mission terminée : le camion est disponible.' : 'Maintenance terminée : le camion est disponible.');
        header('Location: disponibilite.php');
        exit;
    }
}

$camions      = camions_avec_statut();
$destinations = db()->query('SELECT * FROM destinations ORDER BY nom')->fetchAll();
$surConteneur   = engins_sur_conteneur('camion_id');
$disponibles    = camions_disponibles();
$remorquesLibres = remorques_libres($disponibles);
$chauffeursLibres = array_filter(chauffeurs_avec_statut(), function ($ch) { return !$ch['mission_id']; });

$compteurs = ['disponible' => 0, 'mission' => 0, 'maintenance' => 0];
foreach ($camions as $c) $compteurs[$c['statut']]++;

$filtre = $_GET['filtre'] ?? '';
$liste  = isset($compteurs[$filtre])
    ? array_filter($camions, function ($c) use ($filtre) { return $c['statut'] === $filtre; })
    : $camions;

$toutHistorique = ($_GET['historique'] ?? '') === 'tout';
$nbHistorique   = (int) db()->query('SELECT (SELECT COUNT(*) FROM missions WHERE terminee_le IS NOT NULL)
                                     + (SELECT COUNT(*) FROM maintenances WHERE terminee_le IS NOT NULL)')->fetchColumn();
$historique = db()->query(
    "(SELECT 'mission' AS type, m.id, c.immatriculation,
             CONCAT(m.chauffeur, ' → ', COALESCE(d.nom, '?'), COALESCE(CONCAT(' (remorque ', rm.immatriculation, ')'), '')) AS detail,
             m.depart AS debut, m.terminee_le, m.cree_par, m.terminee_par
      FROM missions m JOIN camions c ON c.id = m.camion_id
      LEFT JOIN destinations d ON d.id = m.destination_id
      LEFT JOIN remorques rm ON rm.id = m.remorque_id
      WHERE m.terminee_le IS NOT NULL)
     UNION ALL
     (SELECT 'maintenance', mt.id, c.immatriculation, mt.motif, mt.debut, mt.terminee_le, mt.cree_par, mt.terminee_par
      FROM maintenances mt JOIN camions c ON c.id = mt.camion_id
      WHERE mt.terminee_le IS NOT NULL)
     ORDER BY terminee_le DESC" . ($toutHistorique ? '' : ' LIMIT 10')
)->fetchAll();

// Pré-remplissage depuis le tableau de bord (?camion=..&destination=..)
$preCamion      = (int) ($_POST['camion_id'] ?? $_GET['camion'] ?? 0);
$preDestination = (int) ($_POST['destination_id'] ?? $_GET['destination'] ?? 0);
// Semi proposée : celle choisie avant une erreur, sinon celle déjà attelée au camion
$preRemorque    = (int) ($_POST['remorque_id'] ?? ($disponibles[$preCamion]['remorque_id'] ?? 0));
$preAller       = $_POST['aller'] ?? $_GET['aller'] ?? 'charge';
$preRetour      = $_POST['retour'] ?? $_GET['retour'] ?? 'vide';
$taux           = taux_consommation();
$devise         = parametres()['devise'];
$aujourdhui     = date('Y-m-d');

function date_fr(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '—';
}

$titre = 'Disponibilité';
$page  = 'disponibilite';
require __DIR__ . '/includes/entete.php';
?>

<section class="stats">
    <a class="stat stat-vert" href="?filtre=disponible"><span><?= $compteurs['disponible'] ?></span>Disponibles</a>
    <a class="stat stat-bleu" href="?filtre=mission"><span><?= $compteurs['mission'] ?></span>En mission</a>
    <a class="stat stat-orange" href="?filtre=maintenance"><span><?= $compteurs['maintenance'] ?></span>En maintenance</a>
</section>

<?php foreach ($erreurs as $err): ?>
    <p class="erreur"><?= e($err) ?></p>
<?php endforeach; ?>

<section class="panneau">
    <h2>
        État de la flotte
        <?php if (isset($compteurs[$filtre])): ?>
            — <?= LIBELLES_STATUT[$filtre] ?> <a href="disponibilite.php" class="lien petit">(tout afficher)</a>
        <?php endif; ?>
    </h2>
    <table>
        <thead>
            <tr><th>Camion</th><th>Statut</th><th>Détail</th><th>Depuis</th><th>Fin prévue</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($liste as $c): ?>
                <?php
                $finPrevue = $c['statut'] === 'mission' ? $c['retour_prevu'] : ($c['statut'] === 'maintenance' ? $c['fin_prevue'] : null);
                $enRetard  = $finPrevue && $finPrevue < $aujourdhui;
                ?>
                <tr>
                    <td>
                        <b><?= e($c['immatriculation']) ?></b><br><span class="petit"><?= e($c['modele']) ?></span>
                        <br><span class="petit">Remorque : <?= $c['remorque'] ? e($c['remorque'] . ' (' . $c['remorque_type'] . ')') : 'Sans remorque' ?></span>
                    </td>
                    <td><span class="badge badge-<?= $c['statut'] ?>"><?= LIBELLES_STATUT[$c['statut']] ?></span></td>
                    <td>
                        <?php if ($c['statut'] === 'mission'): ?>
                            <?= e($c['chauffeur']) ?>
                            <?php if ($c['chauffeur_tel']): ?>
                                <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['chauffeur_tel'])) ?>" class="lien petit"><?= e($c['chauffeur_tel']) ?></a>
                            <?php endif; ?>
                            <br>Vers <?= e($c['destination'] ?? 'destination supprimée') ?>
                        <?php elseif ($c['statut'] === 'maintenance'): ?>
                            <?= e($c['motif']) ?>
                        <?php elseif (isset($surConteneur[$c['id']])): ?>
                            <span class="petit">Réservé pour le conteneur <a href="conteneurs.php?q=<?= e($surConteneur[$c['id']]) ?>&amp;statut=a_livrer" class="lien"><?= e($surConteneur[$c['id']]) ?></a></span>
                        <?php else: ?>
                            <span class="petit">Prêt à partir</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $c['statut'] === 'mission' ? date_fr($c['depart']) : ($c['statut'] === 'maintenance' ? date_fr($c['debut']) : '—') ?></td>
                    <td>
                        <?= date_fr($finPrevue) ?>
                        <?php if ($enRetard): ?><span class="badge badge-retard">En retard</span><?php endif; ?>
                    </td>
                    <td class="actions">
                        <?php if ($c['statut'] === 'mission'): ?>
                            <a href="bon_mission.php?id=<?= $c['mission_id'] ?>" class="lien">Bon</a>
                        <?php endif; ?>
                        <?php if ($c['statut'] !== 'disponible'): ?>
                            <form method="post" onsubmit="return confirm('Terminer et rendre le camion disponible ?');">
                                <?= csrf_champ() ?>
                                <input type="hidden" name="action" value="terminer_<?= $c['statut'] ?>">
                                <input type="hidden" name="id" value="<?= $c[$c['statut'] . '_id'] ?>">
                                <button type="submit" class="lien">Terminer</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$liste): ?>
                <tr><td colspan="6" class="vide">Aucun camion dans cette catégorie.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<div class="deux-colonnes">
    <section class="panneau">
        <h2>Envoyer en mission</h2>
        <?php if (!$disponibles): ?>
            <p class="alerte">Aucun camion disponible.</p>
        <?php else: ?>
            <form method="post">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="mission">
                <label for="m-camion">Camion disponible</label>
                <select id="m-camion" name="camion_id" required>
                    <option value="">— Choisir —</option>
                    <?php foreach ($disponibles as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id'] == $preCamion ? 'selected' : '' ?> data-remorque="<?= (int) $c['remorque_id'] ?>">
                            <?= e($c['immatriculation']) ?> – <?= e($c['modele']) ?>
                            + <?= $c['remorque'] ? e($c['remorque'] . ' ' . $c['remorque_type']) : 'sans remorque' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="m-remorque">Semi-remorque</label>
                <select id="m-remorque" name="remorque_id">
                    <option value="">— Sans semi-remorque —</option>
                    <?php foreach ($remorquesLibres as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $r['id'] == $preRemorque ? 'selected' : '' ?> data-camion="<?= (int) $r['camion_id'] ?>">
                            <?= e($r['immatriculation']) ?> – <?= e($r['type']) ?>
                            <?= $r['camion'] ? '(attelée à ' . e($r['camion']) . ')' : '(au dépôt)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="petit">Camion et semi libres : l'attelage est créé automatiquement. Un camion déjà attelé est occupé
                    par sa semi et part avec elle (pour en changer, dételez-la dans <a href="remorques.php" class="lien">Remorques</a>).</p>

                <label for="m-destination">Destination</label>
                <select id="m-destination" name="destination_id" required data-recherche="Tapez au moins 3 lettres de la destination…">
                    <option value="">— Choisir —</option>
                    <?php foreach ($destinations as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $d['id'] == $preDestination ? 'selected' : '' ?>>
                            <?= e($d['nom']) ?> (<?= $d['distance_km'] ?> km)
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="m-chauffeur">Chauffeur disponible</label>
                <select id="m-chauffeur" name="chauffeur_id" required>
                    <option value="">— Choisir —</option>
                    <?php foreach ($chauffeursLibres as $ch): ?>
                        <option value="<?= $ch['id'] ?>" <?= $ch['id'] == ($_POST['chauffeur_id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e($ch['nom'] . ' ' . $ch['prenom']) ?> – <?= e($ch['telephone']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$chauffeursLibres): ?>
                    <p class="petit">Aucun chauffeur disponible. <a href="chauffeurs.php" class="lien">Ajouter un chauffeur</a></p>
                <?php endif; ?>

                <div class="grille-2">
                    <div>
                        <label for="m-aller">Aller</label>
                        <select id="m-aller" name="aller">
                            <option value="charge" <?= $preAller === 'charge' ? 'selected' : '' ?>>Chargé (× <?= $taux['charge'] ?>)</option>
                            <option value="vide" <?= $preAller === 'vide' ? 'selected' : '' ?>>À vide (× <?= $taux['vide'] ?>)</option>
                        </select>
                    </div>
                    <div>
                        <label for="m-sens-retour">Retour</label>
                        <select id="m-sens-retour" name="retour">
                            <option value="vide" <?= $preRetour === 'vide' ? 'selected' : '' ?>>À vide (× <?= $taux['vide'] ?>)</option>
                            <option value="charge" <?= $preRetour === 'charge' ? 'selected' : '' ?>>Chargé (× <?= $taux['charge'] ?>)</option>
                            <option value="aucun" <?= $preRetour === 'aucun' ? 'selected' : '' ?>>Pas de retour</option>
                        </select>
                    </div>
                    <div>
                        <label for="m-montant">Montant facturé (<?= e($devise) ?>)</label>
                        <input type="number" id="m-montant" name="montant" min="0" step="1"
                               value="<?= e($_POST['montant'] ?? '') ?>" placeholder="0">
                    </div>
                    <div>
                        <label for="m-frais">Autres frais (<?= e($devise) ?>)</label>
                        <input type="number" id="m-frais" name="frais" min="0" step="1"
                               value="<?= e($_POST['frais'] ?? '') ?>" placeholder="Péages, frais de route…">
                    </div>
                </div>

                <!-- Rempli automatiquement par assets/mission.js dès qu'une destination est choisie -->
                <div id="apercu-mission" class="apercu cache"></div>

                <label for="m-retour">Retour prévu (facultatif)</label>
                <input type="date" id="m-retour" name="retour_prevu" min="<?= $aujourdhui ?>">

                <button type="submit" class="btn">Démarrer la mission</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="panneau">
        <h2>Mettre en maintenance</h2>
        <?php if (!$disponibles): ?>
            <p class="alerte">Aucun camion disponible.</p>
        <?php else: ?>
            <form method="post">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="maintenance">
                <label for="mt-camion">Camion disponible</label>
                <select id="mt-camion" name="camion_id" required>
                    <option value="">— Choisir —</option>
                    <?php foreach ($disponibles as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e($c['immatriculation']) ?> – <?= e($c['modele']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="mt-motif">Motif</label>
                <input type="text" id="mt-motif" name="motif" required maxlength="255"
                       placeholder="Vidange, pneus, freins…">

                <label for="mt-fin">Fin prévue (facultatif)</label>
                <input type="date" id="mt-fin" name="fin_prevue" min="<?= $aujourdhui ?>">

                <button type="submit" class="btn btn-orange">Mettre en maintenance</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="panneau" id="historique">
    <div class="titre-recherche">
        <h2><?= $toutHistorique ? 'Historique complet' : 'Historique récent' ?> (<?= $nbHistorique ?>)</h2>
        <?php if ($nbHistorique > 10): ?>
            <a href="<?= $toutHistorique ? 'disponibilite.php#historique' : '?historique=tout#historique' ?>" class="lien">
                <?= $toutHistorique ? 'Afficher les 10 derniers' : 'Tout afficher' ?>
            </a>
        <?php endif; ?>
    </div>
    <table>
        <thead>
            <tr><th>Type</th><th>Camion</th><th>Détail</th><th>Début</th><th>Fin</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($historique as $h): ?>
                <tr>
                    <td><span class="badge badge-<?= $h['type'] ?>"><?= $h['type'] === 'mission' ? 'Mission' : 'Maintenance' ?></span></td>
                    <td><?= e($h['immatriculation']) ?></td>
                    <td><?= e($h['detail']) ?></td>
                    <td><?= date_fr($h['debut']) ?><?php if ($h['cree_par']): ?><br><span class="petit">par <?= e($h['cree_par']) ?></span><?php endif; ?></td>
                    <td><?= date_fr($h['terminee_le']) ?><?php if ($h['terminee_par']): ?><br><span class="petit">par <?= e($h['terminee_par']) ?></span><?php endif; ?></td>
                    <td class="actions">
                        <?php if ($h['type'] === 'mission'): ?>
                            <a href="bon_mission.php?id=<?= $h['id'] ?>" class="lien">Bon</a>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Supprimer définitivement cette ligne de l\'historique ?<?= $h['type'] === 'mission' ? '\nElle disparaîtra aussi des rapports (carburant et bénéfice).' : '' ?>');">
                            <?= csrf_champ() ?>
                            <input type="hidden" name="action" value="supprimer_historique">
                            <input type="hidden" name="type" value="<?= $h['type'] ?>">
                            <input type="hidden" name="id" value="<?= $h['id'] ?>">
                            <?php if ($toutHistorique): ?><input type="hidden" name="tout" value="1"><?php endif; ?>
                            <button type="submit" class="lien lien-rouge">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$historique): ?>
                <tr><td colspan="6" class="vide">Aucune mission ni maintenance terminée.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($nbHistorique): ?>
        <details class="purge">
            <summary>Vider l'historique avant une date…</summary>
            <form method="post" class="grille-form"
                  onsubmit="return confirm('Supprimer définitivement tout l\'historique terminé avant cette date ?\nLes missions supprimées disparaîtront aussi des rapports.');">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="purger_historique">
                <div>
                    <label for="avant">Terminé avant le</label>
                    <input type="date" id="avant" name="avant" required max="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                </div>
                <div class="case">
                    <label><input type="checkbox" name="types[]" value="missions" checked> Missions</label>
                    <label><input type="checkbox" name="types[]" value="maintenances" checked> Maintenances</label>
                </div>
                <div class="actions-form">
                    <button type="submit" class="btn btn-auto btn-rouge">Supprimer</button>
                </div>
            </form>
            <p class="petit">Seules les missions et maintenances terminées sont supprimées. Celles en cours ne sont jamais touchées.</p>
        </details>
    <?php endif; ?>
</section>

<script>
    // Attelage : un camion déjà attelé part avec sa semi (les autres sont grisées) ;
    // une semi attelée à un autre camion est grisée (la choisir sans camion sélectionne son camion).
    // Camion et semi libres : l'attelage est créé à l'enregistrement.
    (function () {
        var camion = document.getElementById('m-camion');
        var remorque = document.getElementById('m-remorque');
        if (!camion || !remorque) return;
        function semiDuCamion() {
            var choisi = camion.selectedOptions[0];
            var s = choisi && camion.value ? choisi.getAttribute('data-remorque') : '0';
            return s && s !== '0' ? s : '';
        }
        function majSemis() {
            var semi = semiDuCamion();
            Array.prototype.forEach.call(remorque.options, function (option) {
                var sonCamion = option.getAttribute('data-camion') || '0';
                option.disabled = camion.value !== '' && (semi
                    ? option.value !== semi
                    : option.value !== '' && sonCamion !== '0' && sonCamion !== camion.value);
            });
            if (remorque.selectedOptions[0] && remorque.selectedOptions[0].disabled) remorque.value = '';
        }
        camion.addEventListener('change', function () {
            majSemis();
            var semi = semiDuCamion();
            if (semi && remorque.querySelector('option[value="' + semi + '"]')) remorque.value = semi;
        });
        remorque.addEventListener('change', function () {
            var choisie = remorque.selectedOptions[0];
            var sonCamion = choisie ? choisie.getAttribute('data-camion') : '0';
            if (sonCamion && sonCamion !== '0' && camion.querySelector('option[value="' + sonCamion + '"]')) camion.value = sonCamion;
            majSemis();
        });
        majSemis();
    })();
</script>
<script src="assets/mission.js"></script>

<?php require __DIR__ . '/includes/pied.php'; ?>
