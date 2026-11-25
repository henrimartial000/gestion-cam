<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs   = [];
$conteneur = ['id' => '', 'numero_bl' => '', 'client' => '', 'transitaire' => '', 'numero' => '',
              'plomb' => '', 'date_livraison' => '', 'zone' => '', 'commune_id' => '', 'destination_id' => '',
              'taille' => '20', 'operation' => 'import', 'materiel' => 'remorque', 'scanner' => 0, 'camion_id' => '', 'remorque_id' => '',
              'date_reception' => '', 'date_recuperation' => '', 'date_recuperation_client' => '', 'date_restitution' => '',
              'lieu_restitution' => '', 'chauffeur_id' => '', 'livre_le' => ''];
// Dates de suivi, toutes facultatives : [champ => libellé]
// livre_le = date de livraison réelle : remplie, le conteneur est livré ; vidée, il redevient à livrer
const DATES_SUIVI = [
    'date_reception'           => 'Réception du dossier',
    'date_recuperation'        => 'Récupération du conteneur',
    'livre_le'                 => 'Date de livraison',
    'date_recuperation_client' => 'Récupération chez le client',
    'date_restitution'         => 'Restitution',
];
$devise    = parametres()['devise'];

// Engins pris par des conteneurs à livrer : [id de l'engin => [n° de BL => true]]
function engins_par_dossier(string $colonne, int $sauf): array
{
    if (!in_array($colonne, ['camion_id', 'remorque_id'], true)) return [];
    $stmt = db()->prepare("SELECT c.$colonne, d.numero_bl FROM conteneurs c JOIN dossiers d ON d.id = c.dossier_id
                           WHERE c.$colonne IS NOT NULL AND c.livre_le IS NULL AND c.id <> ?");
    $stmt->execute([$sauf]);
    $par = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $numeroBl]) $par[$id][$numeroBl] = true;
    return $par;
}

// Tracteurs et semi-remorques utilisables : ni en mission, ni en maintenance, ni sur un conteneur à livrer
// d'un AUTRE dossier (les conteneurs d'un même BL peuvent partir sur le même attelage).
// $sauf = conteneur en cours de modification, qui garde le droit à son propre attelage.
// $bl = dossier saisi ; null = on garde aussi les engins déjà pris par un seul dossier (clé 'bl' = ce BL),
// le formulaire ne les rend choisissables que pour ce BL.
function engins_disponibles(int $sauf, ?string $bl = null): array
{
    // '' = libre, 'BL' = pris par ce seul dossier, false = indisponible
    $dossierDe = function (array $par, int $id) use ($bl) {
        if (!isset($par[$id])) return '';
        if (count($par[$id]) !== 1) return false;
        $sonBl = (string) key($par[$id]);
        return $bl === null || $sonBl === $bl ? $sonBl : false;
    };
    $occupes = engins_par_dossier('camion_id', $sauf);
    $camions = [];
    foreach (camions_avec_statut() as $c) {
        $c['bl'] = $dossierDe($occupes, (int) $c['id']);
        if ($c['statut'] === 'disponible' && $c['bl'] !== false) $camions[$c['id']] = $c;
    }
    // Une remorque attelée suit son tracteur : utilisable seulement si celui-ci l'est
    $occupees  = engins_par_dossier('remorque_id', $sauf);
    $remorques = [];
    foreach (db()->query('SELECT r.*, c.immatriculation AS camion FROM remorques r
                          LEFT JOIN camions c ON c.id = r.camion_id ORDER BY r.immatriculation')->fetchAll() as $r) {
        $r['bl'] = $dossierDe($occupees, (int) $r['id']);
        if ($r['bl'] !== false && (!$r['camion_id'] || isset($camions[$r['camion_id']]))) $remorques[$r['id']] = $r;
    }
    return ['camions' => $camions, 'remorques' => $remorques];
}

// Place déjà prise sur chaque semi par les conteneurs à livrer, en EVP : [id de la remorque => 1 ou 2]
// $sauf = conteneur en cours de modification (sa propre place n'est pas comptée)
function charge_remorques(int $sauf): array
{
    $stmt = db()->prepare('SELECT remorque_id, taille FROM conteneurs
                           WHERE remorque_id IS NOT NULL AND livre_le IS NULL AND id <> ?');
    $stmt->execute([$sauf]);
    $charge = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$remorqueId, $taille]) {
        $charge[$remorqueId] = ($charge[$remorqueId] ?? 0) + (EVP_CONTENEUR[$taille] ?? CAPACITE_SEMI_EVP);
    }
    return $charge;
}

// Message d'erreur si le conteneur ne tient pas sur la semi, sinon null
function depassement_semi(int $remorqueId, string $taille, int $sauf): ?string
{
    $charge = charge_remorques($sauf)[$remorqueId] ?? 0;
    if ($charge + (EVP_CONTENEUR[$taille] ?? CAPACITE_SEMI_EVP) <= CAPACITE_SEMI_EVP) return null;
    return $charge >= CAPACITE_SEMI_EVP
        ? 'Cette semi-remorque est déjà pleine : un 40\' ou deux 20\' au maximum.'
        : 'Cette semi-remorque porte déjà un 20\' : elle ne peut plus prendre qu\'un autre 20\', pas un 40\'.';
}

function conteneur_par_id(int $id)
{
    $stmt = db()->prepare('SELECT c.*, d.numero_bl, d.client, d.transitaire, d.date_reception
                           FROM conteneurs c JOIN dossiers d ON d.id = c.dossier_id WHERE c.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Montant gardé après la commission reversée au client
function net_conteneur(array $c): float
{
    return (float) $c['prix'] * (1 - (float) $c['commission_pct'] / 100);
}

// Un dossier sans conteneur n'a plus de raison d'exister
function supprimer_dossiers_vides(PDO $pdo): void
{
    $pdo->exec('DELETE FROM dossiers WHERE id NOT IN (SELECT dossier_id FROM conteneurs)');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $action   = $_POST['action'] ?? '';
    $actuel   = conteneur_par_id((int) ($_POST['id'] ?? 0));

    if ($actuel && $action === 'supprimer') {
        db()->prepare('DELETE FROM conteneurs WHERE id = ?')->execute([$actuel['id']]);
        supprimer_dossiers_vides(db());
        flash('Conteneur supprimé.');
    } elseif ($actuel && $action === 'livrer') {
        db()->prepare('UPDATE conteneurs SET livre_le = NOW(), livre_par = ? WHERE id = ? AND livre_le IS NULL')
            ->execute([$_SESSION['identifiant'], $actuel['id']]);
        flash('Conteneur ' . $actuel['numero'] . ' marqué comme livré.');
    } elseif ($action === 'livrer_selection') {
        // Plusieurs conteneurs livrés en même temps (ex. les deux 20' d'une semi), à la date indiquée ;
        // les autres restent à livrer et seront livrés plus tard
        $ids  = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $jour = $_POST['jour_livraison'] ?? '';
        $d    = DateTime::createFromFormat('!Y-m-d', $jour);
        if (!$ids) {
            flash('Cochez au moins un conteneur à marquer comme livré.', 'erreur');
        } elseif (!$d || $d->format('Y-m-d') !== $jour || $jour > date('Y-m-d')) {
            flash('Date de livraison invalide (elle ne peut pas être dans le futur).', 'erreur');
        } else {
            $moment = $jour === date('Y-m-d') ? date('Y-m-d H:i:s') : $jour . ' 00:00:00';
            $place  = implode(', ', array_fill(0, count($ids), '?'));
            // Même contrôle que le formulaire : pas de livraison avant la récupération ni après la restitution
            $stmt = db()->prepare("UPDATE conteneurs SET livre_le = ?, livre_par = ?
                                   WHERE livre_le IS NULL AND id IN ($place)
                                     AND (date_recuperation IS NULL OR date_recuperation <= ?)
                                     AND (date_restitution IS NULL OR date_restitution >= ?)");
            $stmt->execute(array_merge([$moment, $_SESSION['identifiant']], $ids, [$jour, $jour]));
            $nb = $stmt->rowCount();
            $ignores = count($ids) - $nb;
            flash($nb . ' conteneur(s) marqué(s) livré(s) le ' . $d->format('d/m/Y') . '.'
                . ($ignores ? ' ' . $ignores . ' ignoré(s) : déjà livré(s), ou date incompatible avec leur récupération ou restitution.' : ''),
                $nb ? 'succes' : 'erreur');
        }
    } elseif ($actuel && $action === 'annuler_livraison') {
        db()->prepare('UPDATE conteneurs SET livre_le = NULL, livre_par = NULL WHERE id = ?')->execute([$actuel['id']]);
        flash('Livraison annulée : le conteneur ' . $actuel['numero'] . ' est de nouveau à livrer.');
    }
    if ($action !== 'enregistrer') {
        header('Location: conteneurs.php');
        exit;
    }

    $conteneur = [
        'id'             => $actuel ? (int) $actuel['id'] : 0,
        'numero_bl'      => trim($_POST['numero_bl'] ?? ''),
        'client'         => trim($_POST['client'] ?? ''),
        'transitaire'    => trim($_POST['transitaire'] ?? ''),
        // Espaces et tirets tolérés à la saisie : MSCU 123456-7 → MSCU1234567
        'numero'         => strtoupper(preg_replace('/[\s-]/', '', $_POST['numero'] ?? '')),
        'plomb'          => strtoupper(trim($_POST['plomb'] ?? '')),
        'date_livraison' => $_POST['date_livraison'] ?? '',
        // Lieu de livraison : "c12" = commune 12 (dans une zone), "d5" = destination 5 (hors zone)
        'commune_id'     => preg_match('/^c(\d+)$/', $_POST['lieu'] ?? '', $m) ? (int) $m[1] : null,
        'destination_id' => preg_match('/^d(\d+)$/', $_POST['lieu'] ?? '', $m) ? (int) $m[1] : null,
        'zone'           => null,
        'taille'         => $_POST['taille'] ?? '',
        'operation'      => $_POST['operation'] ?? '',
        'materiel'       => $_POST['materiel'] ?? '',
        'scanner'        => empty($_POST['scanner']) ? 0 : 1,
        'camion_id'      => (int) ($_POST['camion_id'] ?? 0) ?: null,
        'remorque_id'    => (int) ($_POST['remorque_id'] ?? 0) ?: null,
        'lieu_restitution' => trim($_POST['lieu_restitution'] ?? ''),
        'chauffeur_id'   => (int) ($_POST['chauffeur_id'] ?? 0) ?: null,
    ];
    foreach (DATES_SUIVI as $champ => $libelle) {
        $conteneur[$champ] = trim($_POST[$champ] ?? '');
        $d = DateTime::createFromFormat('!Y-m-d', $conteneur[$champ]);
        if ($conteneur[$champ] !== '' && (!$d || $d->format('Y-m-d') !== $conteneur[$champ])) {
            $erreurs[] = 'Date invalide : ' . mb_strtolower($libelle, 'UTF-8') . '.';
        }
    }
    if ($conteneur['date_recuperation'] !== '' && $conteneur['date_restitution'] !== ''
        && $conteneur['date_restitution'] < $conteneur['date_recuperation']) {
        $erreurs[] = 'La restitution ne peut pas être avant la récupération du conteneur.';
    }
    if ($conteneur['livre_le'] !== '') {
        if ($conteneur['livre_le'] > date('Y-m-d')) {
            $erreurs[] = 'La date de livraison ne peut pas être dans le futur.';
        }
        if ($conteneur['date_recuperation'] !== '' && $conteneur['livre_le'] < $conteneur['date_recuperation']) {
            $erreurs[] = 'La livraison ne peut pas être avant la récupération du conteneur.';
        }
        if ($conteneur['date_restitution'] !== '' && $conteneur['date_restitution'] < $conteneur['livre_le']) {
            $erreurs[] = 'La restitution ne peut pas être avant la livraison.';
        }
    }
    // Même jour qu'avant : on garde l'heure et l'auteur enregistrés par le bouton « Livré »
    $dejaLivre = $actuel && $actuel['livre_le'];
    if ($conteneur['livre_le'] === '') {
        $livreLe = $livrePar = null;
    } elseif ($dejaLivre && substr($actuel['livre_le'], 0, 10) === $conteneur['livre_le']) {
        [$livreLe, $livrePar] = [$actuel['livre_le'], $actuel['livre_par']];
    } else {
        $livreLe  = $conteneur['livre_le'] . ($conteneur['livre_le'] === date('Y-m-d') ? date(' H:i:s') : ' 00:00:00');
        $livrePar = $dejaLivre ? $actuel['livre_par'] : $_SESSION['identifiant'];
    }
    if ($conteneur['chauffeur_id']) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM chauffeurs WHERE id = ?');
        $stmt->execute([$conteneur['chauffeur_id']]);
        if (!$stmt->fetchColumn()) $erreurs[] = 'Ce chauffeur n\'existe plus.';
    }
    if ($conteneur['commune_id']) {
        // La zone est toujours celle de la commune de livraison
        $stmt = db()->prepare('SELECT zone FROM communes WHERE id = ?');
        $stmt->execute([$conteneur['commune_id']]);
        $conteneur['zone'] = (int) $stmt->fetchColumn() ?: null;
    }

    // BL : 9 chiffres, enregistrés au format 123-456-789
    $chiffresBl = preg_replace('/[\s-]/', '', $conteneur['numero_bl']);
    if (preg_match('/^\d{9}$/', $chiffresBl)) {
        $conteneur['numero_bl'] = implode('-', str_split($chiffresBl, 3));
    } else {
        $erreurs[] = 'Le numéro de dossier (BL) doit comporter 9 chiffres, par exemple 123-456-789.';
    }
    if (!preg_match('/^[A-Z]{4}\d{7}$/', $conteneur['numero'])) {
        $erreurs[] = 'Le numéro de conteneur doit comporter 4 lettres suivies de 7 chiffres, par exemple MSCU1234567.';
    }
    if ($conteneur['client'] === '')      $erreurs[] = 'Le nom du client est obligatoire.';
    if ($conteneur['transitaire'] === '') $erreurs[] = 'Le nom du transitaire est obligatoire.';
    $date = DateTime::createFromFormat('!Y-m-d', $conteneur['date_livraison']);
    if (!$date || $date->format('Y-m-d') !== $conteneur['date_livraison']) {
        $erreurs[] = 'Le DO est obligatoire.';
    }
    if (!isset(TAILLES_CONTENEUR[$conteneur['taille']]))      $erreurs[] = 'Choisissez la taille du conteneur.';
    if (!isset(OPERATIONS_CONTENEUR[$conteneur['operation']])) $erreurs[] = 'Choisissez l\'opération.';
    if (isset(OPERATIONS_CONTENEUR[$conteneur['operation']])) {
        if (!isset(ZONES[$conteneur['zone']]) && !$conteneur['destination_id']) {
            $erreurs[] = 'Choisissez la commune ou la destination de livraison.';
        }
        if (!isset(MATERIELS_CONTENEUR[$conteneur['materiel']])) {
            $erreurs[] = 'Choisissez le mode de livraison.';
        } elseif (!isset(GRILLE_ZONES[$conteneur['operation']][$conteneur['materiel']])) {
            $erreurs[] = MATERIELS_CONTENEUR[$conteneur['materiel']] . ' : non proposé en '
                . mb_strtolower(OPERATIONS_CONTENEUR[$conteneur['operation']], 'UTF-8') . '.';
        } elseif ($conteneur['destination_id'] && !isset(MATERIELS_HORS_ZONE[$conteneur['materiel']])) {
            $erreurs[] = 'Hors zone, le mode de livraison doit être : ' . implode(' ou ', MATERIELS_HORS_ZONE) . '.';
        }
    }

    // Tracteur + semi-remorque : facultatifs à la saisie, mais toujours ensemble et parmi les engins libres
    if ($conteneur['camion_id'] xor $conteneur['remorque_id']) {
        $erreurs[] = 'Choisissez à la fois le tracteur et la semi-remorque (ou aucun des deux).';
    } elseif ($conteneur['camion_id']) {
        $enginsInchanges = $actuel && $actuel['camion_id'] == $conteneur['camion_id'] && $actuel['remorque_id'] == $conteneur['remorque_id']
            && $actuel['numero_bl'] === $conteneur['numero_bl'];
        $libres = engins_disponibles($actuel ? (int) $actuel['id'] : 0, $conteneur['numero_bl']);
        $remorque = $libres['remorques'][$conteneur['remorque_id']] ?? null;
        if (!$enginsInchanges && !isset($libres['camions'][$conteneur['camion_id']])) {
            $erreurs[] = 'Ce tracteur n\'est plus disponible (en mission, en maintenance ou sur un autre dossier).';
        }
        // Attelage existant : un tracteur attelé est occupé par sa semi, une semi attelée par son tracteur
        if (!$enginsInchanges) {
            $erreurAttelage = erreur_attelage((int) $conteneur['camion_id'], (int) $conteneur['remorque_id']);
            if ($erreurAttelage) $erreurs[] = $erreurAttelage;
        }
        if (!$enginsInchanges && !$remorque) {
            $erreurs[] = 'Cette semi-remorque n\'est plus disponible (son tracteur est pris, ou elle est sur un autre dossier).';
        } elseif ($remorque && ($conteneur['materiel'] === 'autochargeuse') !== ($remorque['type'] === TYPE_AUTOCHARGEUSE)) {
            $erreurs[] = $conteneur['materiel'] === 'autochargeuse'
                ? 'Le mode de livraison est l\'AC : choisissez une remorque de type « ' . TYPE_AUTOCHARGEUSE . ' ».'
                : 'Une remorque « ' . TYPE_AUTOCHARGEUSE . ' » ne s\'utilise qu\'avec le mode de livraison AC.';
        }
        // Un 40' ou deux 20' par semi (un conteneur déjà livré ne compte plus)
        if ($livreLe === null && isset(TAILLES_CONTENEUR[$conteneur['taille']])) {
            $depassement = depassement_semi((int) $conteneur['remorque_id'], $conteneur['taille'], (int) $conteneur['id']);
            if ($depassement) $erreurs[] = $depassement;
        }
    }

    // Prix et commission copiés depuis la grille ; gardés tels quels si la prestation ne change pas
    $prestationInchangee = $actuel && $actuel['prix'] !== null
        && [$actuel['operation'], $actuel['materiel'], (int) $actuel['scanner'], $actuel['zone'], $actuel['destination_id'], $actuel['taille']]
           == [$conteneur['operation'], $conteneur['materiel'], $conteneur['scanner'], $conteneur['zone'], $conteneur['destination_id'], $conteneur['taille']];
    if ($prestationInchangee) {
        $conteneur['prix']           = $actuel['prix'];
        $conteneur['commission_pct'] = $actuel['commission_pct'];
    } elseif (!$erreurs) {
        $conteneur['prix'] = $conteneur['destination_id']
            ? tarif_hors_zone($conteneur['destination_id'], $conteneur['materiel'], $conteneur['taille'])
            : tarif_conteneur($conteneur['operation'], (string) $conteneur['materiel'], $conteneur['zone'], $conteneur['taille']);
        $conteneur['commission_pct'] = commission_conteneur();
        if ($conteneur['prix'] === null) {
            $erreurs[] = $conteneur['destination_id']
                ? 'Aucun montant pour cette destination, ce mode de livraison et cette taille : complétez-le dans « Destinations hors zone ».'
                : 'Cette prestation n\'existe pas dans la grille tarifaire (voir « Tarifs conteneurs »).';
        } elseif ($conteneur['scanner']) {
            $conteneur['prix'] += supplement_scanner($conteneur['taille']);
        }
    }

    if (!$erreurs) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            // Semi verrouillée puis chargement revérifié : deux saisies simultanées ne peuvent pas la surcharger
            if ($conteneur['camion_id'] && empty($enginsInchanges)) {
                // Attelage revérifié sous verrou : personne n'a attelé ce tracteur ou cette semi entre-temps
                $pdo->prepare('SELECT id FROM remorques WHERE id = ? OR camion_id = ? FOR UPDATE')
                    ->execute([$conteneur['remorque_id'], $conteneur['camion_id']]);
                $erreurAttelage = erreur_attelage((int) $conteneur['camion_id'], (int) $conteneur['remorque_id']);
                if ($erreurAttelage) throw new RuntimeException($erreurAttelage);
            }
            if ($conteneur['remorque_id'] && $livreLe === null) {
                $pdo->prepare('SELECT id FROM remorques WHERE id = ? FOR UPDATE')->execute([$conteneur['remorque_id']]);
                $depassement = depassement_semi((int) $conteneur['remorque_id'], $conteneur['taille'], (int) $conteneur['id']);
                if ($depassement) throw new RuntimeException($depassement);
            }
            // Le client et le transitaire appartiennent au dossier : ils valent pour tous ses conteneurs
            $pdo->prepare('INSERT INTO dossiers (numero_bl, client, transitaire, date_reception) VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE client = VALUES(client), transitaire = VALUES(transitaire), date_reception = VALUES(date_reception)')
                ->execute([$conteneur['numero_bl'], $conteneur['client'], $conteneur['transitaire'], $conteneur['date_reception'] ?: null]);
            $stmt = $pdo->prepare('SELECT id FROM dossiers WHERE numero_bl = ?');
            $stmt->execute([$conteneur['numero_bl']]);
            $dossierId = (int) $stmt->fetchColumn();

            $valeurs = [$dossierId, $conteneur['numero'], $conteneur['plomb'] ?: null,
                        $conteneur['date_livraison'], $conteneur['zone'], $conteneur['commune_id'], $conteneur['destination_id'], $conteneur['taille'],
                        $conteneur['operation'], $conteneur['materiel'], $conteneur['scanner'], $conteneur['prix'], $conteneur['commission_pct'],
                        $conteneur['camion_id'], $conteneur['remorque_id'],
                        $conteneur['date_recuperation'] ?: null, $conteneur['date_recuperation_client'] ?: null, $conteneur['date_restitution'] ?: null,
                        $conteneur['lieu_restitution'] !== '' ? $conteneur['lieu_restitution'] : null, $conteneur['chauffeur_id'],
                        $livreLe, $livrePar];
            if ($conteneur['id']) {
                $valeurs[] = $conteneur['id'];
                $pdo->prepare('UPDATE conteneurs SET dossier_id = ?, numero = ?, plomb = ?, date_livraison = ?, zone = ?, commune_id = ?, destination_id = ?,
                               taille = ?, operation = ?, materiel = ?, scanner = ?, prix = ?, commission_pct = ?, camion_id = ?, remorque_id = ?,
                               date_recuperation = ?, date_recuperation_client = ?, date_restitution = ?, lieu_restitution = ?, chauffeur_id = ?,
                               livre_le = ?, livre_par = ? WHERE id = ?')
                    ->execute($valeurs);
                supprimer_dossiers_vides($pdo);
                flash('Conteneur modifié.');
            } else {
                $valeurs[] = $_SESSION['identifiant'];
                $pdo->prepare('INSERT INTO conteneurs (dossier_id, numero, plomb, date_livraison, zone, commune_id, destination_id,
                               taille, operation, materiel, scanner, prix, commission_pct, camion_id, remorque_id,
                               date_recuperation, date_recuperation_client, date_restitution, lieu_restitution, chauffeur_id,
                               livre_le, livre_par, cree_par)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute($valeurs);
                flash('Conteneur ajouté au dossier ' . $conteneur['numero_bl'] . '.');
            }
            // Tracteur et semi libres : l'attelage est créé automatiquement (s'ils l'étaient déjà, rien ne change)
            if ($conteneur['camion_id'] && empty($enginsInchanges)) {
                $pdo->prepare('UPDATE remorques SET camion_id = ? WHERE id = ?')
                    ->execute([$conteneur['camion_id'], $conteneur['remorque_id']]);
            }
            $pdo->commit();
            header('Location: conteneurs.php');
            exit;
        } catch (PDOException $ex) {
            $pdo->rollBack();
            $erreurs[] = $ex->getCode() === '23000'
                ? 'Ce conteneur est déjà enregistré dans ce dossier.'
                : 'Erreur base de données : ' . $ex->getMessage();
        } catch (RuntimeException $ex) {
            // Semi devenue pleine entre-temps (PDOException, plus précise, est traitée avant)
            $pdo->rollBack();
            $erreurs[] = $ex->getMessage();
        }
    }
} elseif (isset($_GET['modifier'])) {
    $conteneur = conteneur_par_id((int) $_GET['modifier']) ?: $conteneur;
}

// Filtres de la liste
$horsZone = ($_GET['zone'] ?? '') === 'hors';
$zone     = (int) ($_GET['zone'] ?? 0);
$statut   = $_GET['statut'] ?? 'a_livrer';
$q        = trim($_GET['q'] ?? '');
if (!isset(ZONES[$zone])) $zone = 0;
if (!in_array($statut, ['a_livrer', 'livres', 'tous'], true)) $statut = 'a_livrer';

$conditions = [];
$params     = [];
if ($zone) {
    $conditions[] = 'c.zone = ?';
    $params[]     = $zone;
}
if ($horsZone) $conditions[] = 'c.destination_id IS NOT NULL';
if ($statut === 'a_livrer') $conditions[] = 'c.livre_le IS NULL';
if ($statut === 'livres')   $conditions[] = 'c.livre_le IS NOT NULL';
if ($q !== '') {
    $conditions[] = '(d.numero_bl LIKE ? OR c.numero LIKE ? OR c.plomb LIKE ? OR d.client LIKE ? OR d.transitaire LIKE ? OR co.nom LIKE ? OR de.nom LIKE ?
                      OR c.lieu_restitution LIKE ? OR CONCAT(ch.prenom, \' \', ch.nom) LIKE ?)';
    array_push($params, ...array_fill(0, 9, '%' . $q . '%'));
}
$stmt = db()->prepare(
    'SELECT c.*, d.numero_bl, d.client, d.transitaire, d.date_reception, co.nom AS commune,
            de.nom AS destination, de.distance_km, ca.immatriculation AS tracteur, rm.immatriculation AS remorque,
            CONCAT(ch.prenom, \' \', ch.nom) AS chauffeur
     FROM conteneurs c JOIN dossiers d ON d.id = c.dossier_id
     LEFT JOIN camions ca ON ca.id = c.camion_id
     LEFT JOIN remorques rm ON rm.id = c.remorque_id
     LEFT JOIN chauffeurs ch ON ch.id = c.chauffeur_id
     LEFT JOIN communes co ON co.id = c.commune_id
     LEFT JOIN destinations_hors_zone de ON de.id = c.destination_id'
    . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '')
    . ' ORDER BY c.livre_le IS NOT NULL, c.date_livraison, d.numero_bl, c.numero'
);
$stmt->execute($params);
$liste = $stmt->fetchAll();

$compteurs = db()->query(
    'SELECT SUM(livre_le IS NULL) AS a_livrer,
            SUM(livre_le IS NULL AND date_livraison < CURDATE()) AS en_retard,
            SUM(livre_le IS NULL AND date_livraison = CURDATE()) AS aujourdhui,
            SUM(livre_le IS NOT NULL) AS livres
     FROM conteneurs'
)->fetch();

// Dossiers connus : remplit le client et le transitaire quand on saisit un BL existant
$dossiers = db()->query('SELECT numero_bl, client, transitaire, date_reception FROM dossiers ORDER BY numero_bl')->fetchAll();

// Chauffeurs pour le suivi, et lieux de restitution déjà utilisés (proposés à la saisie)
$chauffeurs = db()->query('SELECT id, nom, prenom FROM chauffeurs ORDER BY nom, prenom')->fetchAll();
$lieuxRestitution = db()->query('SELECT DISTINCT lieu_restitution FROM conteneurs WHERE lieu_restitution IS NOT NULL ORDER BY lieu_restitution')->fetchAll(PDO::FETCH_COLUMN);

// Date au format jj/mm/aaaa, ou vide
function date_fr(?string $date): string
{
    return $date ? date('d/m/Y', strtotime($date)) : '';
}

// Communes regroupées par zone pour la liste de choix
$communesParZone = [];
foreach (db()->query('SELECT id, nom, zone FROM communes ORDER BY zone, nom')->fetchAll() as $co) {
    if (isset(ZONES[$co['zone']])) $communesParZone[$co['zone']][] = $co;
}

$destinationsHorsZone = db()->query('SELECT * FROM destinations_hors_zone ORDER BY nom')->fetchAll();

// Engins libres pour le formulaire (le conteneur modifié garde son attelage dans la liste)
$engins = engins_disponibles((int) $conteneur['id']);
// Place déjà prise sur chaque semi (le conteneur modifié n'est pas compté)
$chargeSemis = charge_remorques((int) $conteneur['id']);

// Grille tarifaire pour afficher le prix pendant la saisie
$tarifs = [];
foreach (GRILLE_ZONES as $operation => $parMateriel) {
    foreach ($parMateriel as $materiel => $parZone) {
        foreach ($parZone as $numZone => $prixTailles) {
            foreach (array_keys(TAILLES_CONTENEUR) as $i => $taille) {
                $tarifs["$operation|$materiel|$numZone|$taille"] = (float) $prixTailles[$i];
            }
        }
    }
}
// Hors zone : clé "hors|<materiel>|<destination>|<taille>"
foreach ($destinationsHorsZone as $de) {
    foreach (array_keys(MATERIELS_HORS_ZONE) as $materiel) {
        foreach (array_keys(TAILLES_CONTENEUR) as $taille) {
            $prix = prix_hors_zone($de, $materiel, (string) $taille);
            if ($prix !== null) $tarifs["hors|$materiel|{$de['id']}|$taille"] = $prix;
        }
    }
}

// Garde les autres filtres quand on en change un
function lien_filtre(array $changement): string
{
    $actuels = array_filter(['zone' => $_GET['zone'] ?? null, 'statut' => $_GET['statut'] ?? null, 'q' => $_GET['q'] ?? null]);
    return '?' . http_build_query(array_merge($actuels, $changement));
}

$aujourdhui = date('Y-m-d');
$titre = 'Conteneurs';
$page  = 'conteneurs';
require __DIR__ . '/includes/entete.php';
?>

<section class="stats ne-pas-imprimer">
    <a class="stat stat-bleu" href="?statut=a_livrer"><span><?= (int) $compteurs['a_livrer'] ?></span>À livrer</a>
    <a class="stat stat-orange" href="?statut=a_livrer"><span><?= (int) $compteurs['aujourdhui'] ?></span>DO aujourd'hui</a>
    <a class="stat stat-rouge" href="?statut=a_livrer"><span><?= (int) $compteurs['en_retard'] ?></span>En retard</a>
    <a class="stat stat-vert" href="?statut=livres"><span><?= (int) $compteurs['livres'] ?></span>Livrés</a>
</section>

<section class="panneau ne-pas-imprimer">
    <h2><?= $conteneur['id'] ? 'Modifier le conteneur' : 'Ajouter un conteneur' ?></h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <form method="post" class="grille-form">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="enregistrer">
        <input type="hidden" name="id" value="<?= e((string) $conteneur['id']) ?>">
        <div>
            <label for="numero_bl">N° de dossier (BL) *</label>
            <input type="text" id="numero_bl" name="numero_bl" required maxlength="15" list="liste-dossiers" inputmode="numeric"
                   pattern="\s*\d{3}-?\d{3}-?\d{3}\s*" title="9 chiffres, par exemple 123-456-789"
                   value="<?= e($conteneur['numero_bl']) ?>" placeholder="123-456-789" autocomplete="off">
            <datalist id="liste-dossiers">
                <?php foreach ($dossiers as $d): ?>
                    <option value="<?= e($d['numero_bl']) ?>"><?= e($d['client']) ?></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div>
            <label for="client">Client *</label>
            <input type="text" id="client" name="client" required maxlength="150" value="<?= e($conteneur['client']) ?>">
        </div>
        <div>
            <label for="transitaire">Transitaire *</label>
            <input type="text" id="transitaire" name="transitaire" required maxlength="150" value="<?= e($conteneur['transitaire']) ?>">
        </div>
        <div>
            <label for="numero">N° de conteneur *</label>
            <input type="text" id="numero" name="numero" required maxlength="11"
                   pattern="[A-Za-z]{4}\d{7}" title="4 lettres et 7 chiffres, par exemple MSCU1234567"
                   value="<?= e($conteneur['numero']) ?>" placeholder="MSCU1234567" style="text-transform: uppercase">
        </div>
        <div>
            <label for="taille">Taille *</label>
            <select id="taille" name="taille" required>
                <?php foreach (TAILLES_CONTENEUR as $t => $nom): ?>
                    <option value="<?= $t ?>" <?= (string) $t === (string) $conteneur['taille'] ? 'selected' : '' ?>><?= $nom ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="plomb">N° de plomb</label>
            <input type="text" id="plomb" name="plomb" maxlength="30" value="<?= e((string) $conteneur['plomb']) ?>" placeholder="Facultatif">
        </div>
        <div>
            <label for="date_livraison">DO *</label>
            <input type="date" id="date_livraison" name="date_livraison" required value="<?= e($conteneur['date_livraison']) ?>">
        </div>
        <div>
            <label for="operation">Opération *</label>
            <select id="operation" name="operation" required>
                <?php foreach (OPERATIONS_CONTENEUR as $op => $nom): ?>
                    <option value="<?= $op ?>" <?= $op === $conteneur['operation'] ? 'selected' : '' ?>><?= e($nom) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="materiel">Mode de livraison *</label>
            <select id="materiel" name="materiel" required>
                <?php foreach (MATERIELS_CONTENEUR as $m => $nom): ?>
                    <option value="<?= $m ?>" <?= $m === $conteneur['materiel'] ? 'selected' : '' ?>><?= e($nom) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="case">
            <label><input type="checkbox" id="scanner" name="scanner" value="1" <?= $conteneur['scanner'] ? 'checked' : '' ?>>
                Option scanner (+ <?= montant(SUPPLEMENT_SCANNER[0], $devise) ?> en 20', <?= montant(SUPPLEMENT_SCANNER[1], $devise) ?> en 40')</label>
        </div>
        <div>
            <label for="lieu">Commune / destination *</label>
            <select id="lieu" name="lieu" required data-recherche="Tapez au moins 3 lettres de la commune ou destination…">
                <option value="">— Choisir —</option>
                <?php foreach ($communesParZone as $num => $liste_communes): ?>
                    <optgroup label="<?= e(ZONES[$num]) ?>">
                        <?php foreach ($liste_communes as $co): ?>
                            <option value="c<?= $co['id'] ?>" data-zone="<?= $num ?>" <?= $co['id'] == $conteneur['commune_id'] ? 'selected' : '' ?>><?= e($co['nom']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
                <?php if ($destinationsHorsZone): ?>
                    <optgroup label="Hors zone">
                        <?php foreach ($destinationsHorsZone as $de): ?>
                            <option value="d<?= $de['id'] ?>" data-destination="<?= $de['id'] ?>" data-distance="<?= $de['distance_km'] ?>"
                                <?= $de['id'] == $conteneur['destination_id'] ? 'selected' : '' ?>><?= e($de['nom']) ?><?= $de['distance_km'] ? ' (' . $de['distance_km'] . ' km)' : '' ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <?php if (!$communesParZone && !$destinationsHorsZone): ?>
                <p class="petit">Aucun lieu : ajoutez-les dans <a href="communes.php" class="lien">Communes et zones</a>
                    ou <a href="destinations_hors_zone.php" class="lien">Destinations hors zone</a>.</p>
            <?php endif; ?>
        </div>
        <div>
            <label>Prix du transport</label>
            <div id="prix-calcule" class="prix-calcule">—</div>
        </div>
        <div>
            <label for="camion_id">Tracteur disponible</label>
            <select id="camion_id" name="camion_id">
                <option value="">— À affecter plus tard —</option>
                <?php foreach ($engins['camions'] as $ca): ?>
                    <option value="<?= $ca['id'] ?>" <?= $ca['id'] == $conteneur['camion_id'] ? 'selected' : '' ?>
                            data-remorque="<?= (int) $ca['remorque_id'] ?>" data-bl="<?= e($ca['bl']) ?>"><?= e($ca['immatriculation']) ?> – <?= e($ca['modele']) ?>
                        <?= $ca['bl'] !== '' ? '(déjà sur le BL ' . e($ca['bl']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$engins['camions']): ?><p class="petit">Aucun tracteur libre en ce moment.</p><?php endif; ?>
        </div>
        <div>
            <label for="remorque_id">Semi-remorque disponible</label>
            <select id="remorque_id" name="remorque_id">
                <option value="">— À affecter plus tard —</option>
                <?php foreach ($engins['remorques'] as $rm): ?>
                    <option value="<?= $rm['id'] ?>" <?= $rm['id'] == $conteneur['remorque_id'] ? 'selected' : '' ?>
                            data-autochargeuse="<?= $rm['type'] === TYPE_AUTOCHARGEUSE ? 1 : 0 ?>" data-bl="<?= e($rm['bl']) ?>"
                            data-camion="<?= (int) $rm['camion_id'] ?>" data-charge="<?= $charge = $chargeSemis[$rm['id']] ?? 0 ?>">
                        <?= e($rm['immatriculation']) ?> – <?= e($rm['type']) ?>
                        <?= $rm['camion'] ? '(attelée à ' . e($rm['camion']) . ')' : '(au dépôt)' ?>
                        <?= $rm['bl'] !== '' ? '– déjà sur le BL ' . e($rm['bl']) : '' ?>
                        <?= $charge >= CAPACITE_SEMI_EVP ? '– pleine' : ($charge ? "– porte un 20', reste 1 place 20'" : '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div id="aide-remorque" aria-live="polite"></div>
            <?php if (!$engins['remorques']): ?><p class="petit">Aucune semi-remorque libre en ce moment.</p><?php endif; ?>
        </div>

        <h3 class="sous-section pleine-ligne">Suivi du conteneur</h3>
        <div>
            <label for="date_reception">Réception du dossier</label>
            <input type="date" id="date_reception" name="date_reception" value="<?= e((string) $conteneur['date_reception']) ?>">
        </div>
        <div>
            <label for="date_recuperation">Récupération du conteneur</label>
            <input type="date" id="date_recuperation" name="date_recuperation" value="<?= e((string) $conteneur['date_recuperation']) ?>">
        </div>
        <div>
            <label for="chauffeur_id">Chauffeur</label>
            <select id="chauffeur_id" name="chauffeur_id">
                <option value="">— À affecter plus tard —</option>
                <?php foreach ($chauffeurs as $ch): ?>
                    <option value="<?= $ch['id'] ?>" <?= $ch['id'] == $conteneur['chauffeur_id'] ? 'selected' : '' ?>><?= e($ch['prenom'] . ' ' . $ch['nom']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$chauffeurs): ?><p class="petit">Aucun chauffeur : ajoutez-les dans <a href="chauffeurs.php" class="lien">Chauffeurs</a>.</p><?php endif; ?>
        </div>
        <div>
            <label for="livre_le">Date de livraison</label>
            <input type="date" id="livre_le" name="livre_le" max="<?= date('Y-m-d') ?>" value="<?= e(substr((string) $conteneur['livre_le'], 0, 10)) ?>">
            <p class="petit">Remplie : le conteneur est livré. Vide : il reste à livrer.</p>
        </div>
        <div>
            <label for="date_recuperation_client">Récupération chez le client</label>
            <input type="date" id="date_recuperation_client" name="date_recuperation_client" value="<?= e((string) $conteneur['date_recuperation_client']) ?>">
        </div>
        <div>
            <label for="date_restitution">Date de restitution</label>
            <input type="date" id="date_restitution" name="date_restitution" value="<?= e((string) $conteneur['date_restitution']) ?>">
        </div>
        <div>
            <label for="lieu_restitution">Lieu de restitution</label>
            <input type="text" id="lieu_restitution" name="lieu_restitution" maxlength="150" list="liste-lieux-restitution"
                   value="<?= e((string) $conteneur['lieu_restitution']) ?>" placeholder="Ex. : terminal, dépôt…">
            <datalist id="liste-lieux-restitution">
                <?php foreach ($lieuxRestitution as $lieuR): ?><option value="<?= e($lieuR) ?>"><?php endforeach; ?>
            </datalist>
        </div>

        <div class="actions-form">
            <button type="submit" class="btn btn-auto"><?= $conteneur['id'] ? 'Enregistrer' : 'Ajouter' ?></button>
            <?php if ($conteneur['id']): ?>
                <a href="conteneurs.php" class="btn btn-auto btn-gris">Annuler</a>
            <?php endif; ?>
        </div>
    </form>
    <p class="petit">* champs obligatoires. Le client, le transitaire et la date de réception sont ceux du dossier : les modifier ici les change pour tous les conteneurs du même BL.
        Le prix vient de la grille <a href="tarifs_conteneurs.php" class="lien">Tarifs conteneurs</a> et reste enregistré dans le conteneur.
        Seuls les tracteurs et remorques libres sont proposés, ainsi que ceux déjà affectés à un autre conteneur du même BL
        (plusieurs conteneurs d'un dossier peuvent partir sur le même attelage) ; ils redeviennent libres quand tous leurs conteneurs sont livrés.
        Une semi-remorque porte au maximum un 40' ou deux 20'.</p>
</section>

<section class="panneau">
    <div class="titre-recherche">
        <h2>Liste des conteneurs (<?= count($liste) ?>)</h2>
        <form method="get" class="recherche">
            <?php if ($zone || $horsZone): ?><input type="hidden" name="zone" value="<?= $horsZone ? 'hors' : $zone ?>"><?php endif; ?>
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Rechercher : BL, conteneur, client…">
            <?php if ($q !== ''): ?><a href="<?= e(lien_filtre(['q' => null])) ?>" class="lien petit">Effacer</a><?php endif; ?>
        </form>
    </div>

    <div class="filtres-conteneurs ne-pas-imprimer">
        <div class="onglets">
            <a href="<?= e(lien_filtre(['zone' => null])) ?>" class="<?= $zone || $horsZone ? '' : 'actif' ?>">Toutes les zones</a>
            <?php foreach (ZONES as $num => $nom): ?>
                <a href="<?= e(lien_filtre(['zone' => $num])) ?>" class="<?= $num === $zone ? 'actif' : '' ?>"><?= e($nom) ?></a>
            <?php endforeach; ?>
            <a href="<?= e(lien_filtre(['zone' => 'hors'])) ?>" class="<?= $horsZone ? 'actif' : '' ?>">Hors zone</a>
        </div>
        <div class="onglets">
            <?php foreach (['a_livrer' => 'À livrer', 'livres' => 'Livrés', 'tous' => 'Tous'] as $s => $nom): ?>
                <a href="<?= e(lien_filtre(['statut' => $s])) ?>" class="<?= $s === $statut ? 'actif' : '' ?>"><?= $nom ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php $aLivrer = array_filter($liste, function ($c) { return !$c['livre_le']; }); ?>
    <?php if ($aLivrer): ?>
        <!-- Livraison groupée : les cases cochées dans la liste sont rattachées à ce formulaire (attribut form) -->
        <form method="post" id="form-livraison" class="livraison-groupee ne-pas-imprimer">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="livrer_selection">
            <span>Conteneurs livrés en même temps : cochez-les, puis</span>
            <label for="jour_livraison">livrés le</label>
            <input type="date" id="jour_livraison" name="jour_livraison" value="<?= $aujourdhui ?>" max="<?= $aujourdhui ?>" required>
            <button type="submit" class="btn btn-auto">Marquer livrés (<span id="nb-coches">0</span>)</button>
            <span class="petit">Les autres restent à livrer et pourront l'être plus tard.</span>
        </form>
    <?php endif; ?>

    <table>
        <thead>
            <tr><th class="ne-pas-imprimer"><?php if ($aLivrer): ?><input type="checkbox" id="tout-cocher" title="Tout cocher"><?php endif; ?></th><th>Dossier (BL)</th><th>Conteneur</th><th>Client / Transitaire</th><th>Prestation</th><th>DO</th><th>Suivi</th><th>Prix</th><th>Statut</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($liste as $c): ?>
                <tr>
                    <td class="ne-pas-imprimer">
                        <?php if (!$c['livre_le']): ?>
                            <input type="checkbox" class="case-livraison" name="ids[]" value="<?= $c['id'] ?>" form="form-livraison"
                                   data-remorque="<?= (int) $c['remorque_id'] ?>" aria-label="Livrer le conteneur <?= e($c['numero']) ?>">
                        <?php endif; ?>
                    </td>
                    <td>
                        <b><?= e($c['numero_bl']) ?></b>
                        <br><a href="dossier.php?id=<?= $c['dossier_id'] ?>" class="lien petit ne-pas-imprimer">Imprimer le dossier</a>
                    </td>
                    <td>
                        <b><?= e($c['numero']) ?></b> <span class="petit"><?= TAILLES_CONTENEUR[$c['taille']] ?? e($c['taille']) ?></span>
                        <?php if ($c['plomb']): ?><br><span class="petit">Plomb <?= e($c['plomb']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e($c['client']) ?><br><span class="petit"><?= e($c['transitaire']) ?></span></td>
                    <td>
                        <?= e(OPERATIONS_CONTENEUR[$c['operation']] ?? $c['operation']) ?>
                        <?php if ($c['destination_id']): ?>
                            <br><span class="petit"><?= e($c['destination']) ?> (hors zone<?= $c['distance_km'] ? ', ' . $c['distance_km'] . ' km' : '' ?>) · <?= e(MATERIELS_HORS_ZONE[$c['materiel']] ?? (string) $c['materiel']) ?><?= $c['scanner'] ? ' + scanner' : '' ?></span>
                        <?php elseif ($c['zone']): ?>
                            <br><span class="petit"><?= $c['commune'] ? e($c['commune']) . ' – ' : '' ?><?= e(ZONES[$c['zone']] ?? 'Zone ' . $c['zone']) ?> · <?= e(MATERIELS_CONTENEUR[$c['materiel']] ?? (string) $c['materiel']) ?><?= $c['scanner'] ? ' + scanner' : '' ?></span>
                        <?php endif; ?>
                        <?php if ($c['tracteur']): ?>
                            <br><span class="petit">Tracteur <?= e($c['tracteur']) ?> + <?= e((string) $c['remorque']) ?></span>
                        <?php elseif (!$c['livre_le']): ?>
                            <br><span class="badge badge-maintenance">Engin à affecter</span>
                        <?php endif; ?>
                    </td>
                    <td><?= date('d/m/Y', strtotime($c['date_livraison'])) ?></td>
                    <td>
                        <ul class="suivi">
                            <?php if ($c['date_reception']): ?><li>Dossier reçu <b><?= date_fr($c['date_reception']) ?></b></li><?php endif; ?>
                            <?php if ($c['date_recuperation']): ?><li>Récupéré <b><?= date_fr($c['date_recuperation']) ?></b></li><?php endif; ?>
                            <?php if ($c['chauffeur']): ?><li>Chauffeur <b><?= e($c['chauffeur']) ?></b></li><?php endif; ?>
                            <?php if ($c['date_recuperation_client']): ?><li>Repris client <b><?= date_fr($c['date_recuperation_client']) ?></b></li><?php endif; ?>
                            <?php if ($c['date_restitution'] || $c['lieu_restitution']): ?>
                                <li>Restitué <b><?= date_fr($c['date_restitution']) ?></b><?= $c['lieu_restitution'] ? ' à <b>' . e($c['lieu_restitution']) . '</b>' : '' ?></li>
                            <?php endif; ?>
                        </ul>
                    </td>
                    <td>
                        <?php if ($c['prix'] !== null): ?>
                            <?= montant((float) $c['prix'], $devise) ?>
                            <?php if ($c['commission_pct'] > 0): ?>
                                <br><span class="petit">Net <?= montant(net_conteneur($c), $devise) ?> (−<?= (float) $c['commission_pct'] ?> %)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="petit">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['livre_le']): ?>
                            <span class="badge badge-disponible">Livré</span>
                            <br><span class="petit">le <?= date('d/m/Y', strtotime($c['livre_le'])) ?></span>
                        <?php elseif ($c['date_livraison'] < $aujourdhui): ?>
                            <span class="badge badge-retard">En retard</span>
                        <?php elseif ($c['date_livraison'] === $aujourdhui): ?>
                            <span class="badge badge-maintenance">Aujourd'hui</span>
                        <?php else: ?>
                            <span class="badge badge-mission">À livrer</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <?php if (!$c['livre_le']): ?>
                            <form method="post">
                                <?= csrf_champ() ?>
                                <input type="hidden" name="action" value="livrer">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="lien">Livré</button>
                            </form>
                        <?php else: ?>
                            <form method="post" onsubmit="return confirm('Remettre ce conteneur dans la liste à livrer ?');">
                                <?= csrf_champ() ?>
                                <input type="hidden" name="action" value="annuler_livraison">
                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                <button type="submit" class="lien">Annuler livraison</button>
                            </form>
                        <?php endif; ?>
                        <a href="?modifier=<?= $c['id'] ?>" class="lien">Modifier</a>
                        <form method="post" onsubmit="return confirm('Supprimer ce conteneur ?');">
                            <?= csrf_champ() ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="lien lien-rouge">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($liste): ?>
                <tr class="ligne-total">
                    <td colspan="7">Total des <?= count($liste) ?> conteneurs affichés</td>
                    <td colspan="3">
                        <?= montant(array_sum(array_column($liste, 'prix')), $devise) ?>
                        <?php $net = array_sum(array_map('net_conteneur', $liste)); ?>
                        <?php if ($net != array_sum(array_column($liste, 'prix'))): ?>
                            <br><span class="petit">Net après commission <?= montant($net, $devise) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <tr><td colspan="10" class="vide">Aucun conteneur<?= $zone || $q !== '' || $statut !== 'tous' ? ' pour ces filtres' : ' enregistré' ?>.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<script>
    // Livraison groupée : compteur des cases cochées, « tout cocher », confirmation
    (function () {
        var form = document.getElementById('form-livraison');
        if (!form) return;
        var cases = document.querySelectorAll('.case-livraison');
        var tout = document.getElementById('tout-cocher');
        var nb = document.getElementById('nb-coches');
        function coches() { return Array.prototype.filter.call(cases, function (c) { return c.checked; }); }
        function maj() {
            var n = coches().length;
            nb.textContent = n;
            if (tout) {
                tout.checked = n > 0 && n === cases.length;
                tout.indeterminate = n > 0 && n < cases.length;
            }
        }
        cases.forEach(function (c) { c.addEventListener('change', maj); });
        if (tout) tout.addEventListener('change', function () {
            cases.forEach(function (c) { c.checked = tout.checked; });
            maj();
        });
        form.addEventListener('submit', function (ev) {
            var n = coches().length;
            var jour = document.getElementById('jour_livraison').value.split('-').reverse().join('/');
            if (!n) { ev.preventDefault(); alert('Cochez les conteneurs livrés.'); return; }
            if (!confirm('Marquer ' + n + ' conteneur(s) comme livré(s) le ' + jour + ' ?')) ev.preventDefault();
        });
        maj();
    })();

    // BL déjà connu : reprend le client et le transitaire du dossier
    (function () {
        var dossiers = <?= json_encode(array_column($dossiers, null, 'numero_bl'), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var bl = document.getElementById('numero_bl');
        // Tirets ajoutés pendant la saisie : 123456789 → 123-456-789 (le curseur reste après le même chiffre)
        bl.addEventListener('input', function () {
            var avantCurseur = bl.value.slice(0, bl.selectionStart).replace(/\D/g, '').length;
            var chiffres = bl.value.replace(/\D/g, '').slice(0, 9);
            var formate = chiffres.replace(/^(\d{3})(\d)/, '$1-$2').replace(/^(\d{3}-\d{3})(\d)/, '$1-$2');
            if (formate === bl.value) return;
            bl.value = formate;
            var pos = 0;
            for (var vus = 0; pos < formate.length && vus < avantCurseur; pos++) {
                if (/\d/.test(formate[pos])) vus++;
            }
            bl.setSelectionRange(pos, pos);
        });
        // N° de conteneur : 4 lettres puis 7 chiffres, en majuscules ; les autres caractères sont ignorés
        var numero = document.getElementById('numero');
        numero.addEventListener('input', function () {
            var brut = numero.value.slice(0, numero.selectionStart);
            var garde = function (texte) {
                var resultat = '';
                texte.toUpperCase().split('').forEach(function (car) {
                    if (resultat.length < 4 ? /[A-Z]/.test(car) : resultat.length < 11 && /\d/.test(car)) resultat += car;
                });
                return resultat;
            };
            var formate = garde(numero.value);
            if (formate === numero.value) return;
            var pos = garde(brut).length;
            numero.value = formate;
            numero.setSelectionRange(pos, pos);
        });
        bl.addEventListener('change', function () {
            var chiffres = bl.value.replace(/[\s-]/g, '');
            if (/^\d{9}$/.test(chiffres)) bl.value = chiffres.replace(/(\d{3})(\d{3})(\d{3})/, '$1-$2-$3');
            var d = dossiers[bl.value];
            if (d) {
                document.getElementById('client').value = d.client;
                document.getElementById('transitaire').value = d.transitaire;
                document.getElementById('date_reception').value = d.date_reception || '';
            }
        });

        // Prix selon la grille
        var tarifs = <?= json_encode($tarifs) ?>;
        var commission = <?= json_encode(commission_conteneur()) ?>;
        var devise = <?= json_encode($devise, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var zones = <?= json_encode(ZONES, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        var materielsParOperation = <?= json_encode(array_map('array_keys', GRILLE_ZONES)) ?>;
        // Supplément de l'option scanner, par taille
        var supplementScanner = <?= json_encode(array_combine(array_keys(TAILLES_CONTENEUR), SUPPLEMENT_SCANNER)) ?>;
        var champs = ['taille', 'operation', 'materiel', 'lieu', 'scanner'].map(function (id) { return document.getElementById(id); });
        function formater(n) { return Math.round(n).toLocaleString('fr-FR') + ' ' + devise; }
        function majPrix() {
            var taille = champs[0].value, operation = champs[1].value;
            // Matériels proposés pour l'opération
            Array.prototype.forEach.call(champs[2].options, function (option) {
                option.disabled = !(materielsParOperation[operation] || []).includes(option.value);
            });
            if (champs[2].selectedOptions[0] && champs[2].selectedOptions[0].disabled) champs[2].value = 'remorque';
            // La zone vient de la commune choisie ; hors zone, le prix vient de la destination
            var lieu = champs[3].selectedOptions[0];
            var zone = lieu ? lieu.getAttribute('data-zone') : null;
            var destination = lieu ? lieu.getAttribute('data-destination') : null;
            var cle = destination ? 'hors|' + champs[2].value + '|' + destination + '|' + taille
                : operation + '|' + champs[2].value + '|' + zone + '|' + taille;
            var prix = tarifs[cle];
            var scanner = champs[4].checked;
            var cadre = document.getElementById('prix-calcule');
            var distance = lieu ? lieu.getAttribute('data-distance') : '';
            var libelleZone = destination ? 'Hors zone' + (distance ? ' (' + distance + ' km)' : '') + ' · ' : zones[zone] + ' · ';
            if (!zone && !destination) cadre.textContent = 'Choisissez la commune ou la destination';
            else if (prix === undefined) cadre.textContent = libelleZone + 'Pas de tarif pour cette prestation';
            else {
                var total = prix + (scanner ? supplementScanner[taille] : 0);
                cadre.innerHTML = libelleZone + '<b>' + formater(total) + '</b>'
                    + (scanner ? ' <span class="petit">(' + formater(prix) + ' + scanner ' + formater(supplementScanner[taille]) + ')</span>' : '')
                    + (commission > 0
                        ? ' · net ' + formater(total * (1 - commission / 100)) + ' <span class="petit">(commission ' + commission + ' %)</span>'
                        : '');
            }
        }
        champs.forEach(function (c) { c.addEventListener('change', majPrix); });
        majPrix();

        // Semi-remorque : seules celles qui vont avec le matériel (auto-chargeuse ou standard) restent choisissables
        var tracteur = document.getElementById('camion_id');
        var remorque = document.getElementById('remorque_id');
        // Un engin déjà sur un conteneur à livrer n'est choisissable que pour le même BL
        function autreDossier(option) {
            var blEngin = option.getAttribute('data-bl');
            return !!blEngin && blEngin !== bl.value.trim();
        }
        // Un 40' ou deux 20' par semi : une semi sans assez de place pour la taille choisie est grisée
        var evp = <?= json_encode(EVP_CONTENEUR) ?>, capacite = <?= CAPACITE_SEMI_EVP ?>;
        function semiPleine(option) {
            return (parseInt(option.getAttribute('data-charge'), 10) || 0) + (evp[champs[0].value] || capacite) > capacite;
        }
        function majRemorques() {
            var autochargeuse = champs[2].value === 'autochargeuse';
            Array.prototype.forEach.call(tracteur.options, function (option) {
                if (option.value) option.disabled = autreDossier(option);
            });
            if (tracteur.selectedOptions[0] && tracteur.selectedOptions[0].disabled) tracteur.value = '';
            // Attelage : un tracteur déjà attelé n'accepte que sa semi ; une semi attelée à un autre tracteur est grisée
            var semiDuTracteur = tracteur.value && tracteur.selectedOptions[0] ? tracteur.selectedOptions[0].getAttribute('data-remorque') : '0';
            var raisons = {};
            Array.prototype.forEach.call(remorque.options, function (option) {
                if (!option.value) return;
                var sonTracteur = option.getAttribute('data-camion') || '0';
                var conflit = tracteur.value !== '' && (semiDuTracteur !== '0'
                    ? option.value !== semiDuTracteur
                    : sonTracteur !== '0' && sonTracteur !== tracteur.value);
                // Raison pour laquelle la semi est grisée (la première trouvée), expliquée sous la liste
                var raison = (option.getAttribute('data-autochargeuse') === '1') !== autochargeuse ? 'type'
                    : autreDossier(option) ? 'dossier'
                    : (!document.getElementById('livre_le').value && semiPleine(option)) ? 'pleine'
                    : conflit ? 'attelage' : '';
                option.disabled = raison !== '';
                if (raison) raisons[raison] = true;
            });
            if (remorque.selectedOptions[0] && remorque.selectedOptions[0].disabled) remorque.value = '';
            afficherRaisons(raisons, autochargeuse, semiDuTracteur);
        }
        // Explique sous la liste pourquoi des semis sont grisées
        function afficherRaisons(raisons, autochargeuse, semiDuTracteur) {
            var aide = document.getElementById('aide-remorque');
            var messages = [];
            var disponibles = Array.prototype.filter.call(remorque.options, function (o) { return o.value && !o.disabled; }).length;
            if (raisons.type) messages.push(autochargeuse
                ? 'Mode de livraison AC : seules les semis de type « Autochargeuse (AC) » conviennent'
                    + (disponibles ? '.' : ', et vous n\'en avez aucune de libre. Ajoutez-en une dans Remorques, ou choisissez le mode Remorque.')
                : 'Les semis de type AC ne servent qu\'au mode de livraison AC.');
            if (raisons.attelage) {
                var semi = semiDuTracteur !== '0' && remorque.querySelector('option[value="' + semiDuTracteur + '"]');
                messages.push(semi
                    ? 'Ce tracteur est attelé à sa semi : lui seul peut la tirer. Pour en changer, dételez-la dans Remorques.'
                    : 'Les semis attelées à un autre tracteur ne partent qu\'avec lui.');
            }
            if (raisons.pleine) messages.push('Semi pleine pour cette taille : un 40\' ou deux 20\' au maximum.');
            if (raisons.dossier) messages.push('Certaines semis sont déjà sur un conteneur d\'un autre dossier (BL).');
            aide.innerHTML = '';
            messages.forEach(function (texte) {
                var ligne = document.createElement('p');
                ligne.className = 'petit';
                ligne.textContent = 'Grisées : ' + texte.charAt(0).toLowerCase() + texte.slice(1);
                aide.appendChild(ligne);
            });
            if (!disponibles && remorque.options.length > 1) aide.classList.add('alerte-texte'); else aide.classList.remove('alerte-texte');
        }
        bl.addEventListener('input', majRemorques);
        bl.addEventListener('change', majRemorques);
        // Tracteur déjà attelé : sa semi est choisie avec lui (tracteur et semi libres : l'attelage sera créé)
        tracteur.addEventListener('change', function () {
            majRemorques();
            var attelee = tracteur.value && tracteur.selectedOptions[0] ? tracteur.selectedOptions[0].getAttribute('data-remorque') : '0';
            var option = attelee !== '0' && remorque.querySelector('option[value="' + attelee + '"]');
            if (option && !option.disabled) remorque.value = attelee;
            else if (!tracteur.value) remorque.value = '';
        });
        // Semi déjà attelée : son tracteur est choisi avec elle
        remorque.addEventListener('change', function () {
            var choisie = remorque.selectedOptions[0];
            var sonTracteur = choisie ? choisie.getAttribute('data-camion') : '0';
            var option = sonTracteur && sonTracteur !== '0' && tracteur.querySelector('option[value="' + sonTracteur + '"]');
            if (option && !option.disabled) { tracteur.value = sonTracteur; majRemorques(); }
        });
        [champs[0], champs[1], champs[2], document.getElementById('livre_le')].forEach(function (c) { c.addEventListener('change', majRemorques); });
        majRemorques();
    })();
</script>

<?php require __DIR__ . '/includes/pied.php'; ?>
