<?php
// Fiche dossier imprimable (format A4) : un BL avec tous ses conteneurs et leur suivi
require_once __DIR__ . '/config.php';
exiger_connexion();

$stmt = db()->prepare('SELECT * FROM dossiers WHERE id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$dossier = $stmt->fetch();

if (!$dossier) {
    flash('Dossier introuvable.', 'erreur');
    header('Location: conteneurs.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT c.*, co.nom AS commune, de.nom AS destination, de.distance_km,
            ca.immatriculation AS tracteur, rm.immatriculation AS remorque, rm.type AS remorque_type,
            ch.prenom AS ch_prenom, ch.nom AS ch_nom, ch.telephone AS ch_tel
     FROM conteneurs c
     LEFT JOIN communes co ON co.id = c.commune_id
     LEFT JOIN destinations_hors_zone de ON de.id = c.destination_id
     LEFT JOIN camions ca ON ca.id = c.camion_id
     LEFT JOIN remorques rm ON rm.id = c.remorque_id
     LEFT JOIN chauffeurs ch ON ch.id = c.chauffeur_id
     WHERE c.dossier_id = ?
     ORDER BY c.date_livraison, c.numero'
);
$stmt->execute([$dossier['id']]);
$conteneurs = $stmt->fetchAll();

$devise   = parametres()['devise'];
$total    = array_sum(array_column($conteneurs, 'prix'));
$nbLivres = count(array_filter(array_column($conteneurs, 'livre_le')));

function ligne(string $libelle, $valeur): string
{
    return '<tr><th>' . e($libelle) . '</th><td>' . ($valeur === null || $valeur === '' ? '—' : $valeur) . '</td></tr>';
}

function jour(?string $date): ?string
{
    return $date ? date('d/m/Y', strtotime($date)) : null;
}

// Lieu de livraison et mode de livraison d'un conteneur
function prestation(array $c): string
{
    if ($c['destination_id']) {
        $lieu = e((string) $c['destination']) . ' (hors zone' . ($c['distance_km'] ? ', ' . $c['distance_km'] . ' km' : '') . ')';
        $mode = MATERIELS_HORS_ZONE[$c['materiel']] ?? (string) $c['materiel'];
    } else {
        $lieu = ($c['commune'] ? e($c['commune']) . ' – ' : '') . e(ZONES[$c['zone']] ?? 'Zone ' . $c['zone']);
        $mode = MATERIELS_CONTENEUR[$c['materiel']] ?? (string) $c['materiel'];
    }
    return '<b>' . $lieu . '</b><br>' . e(OPERATIONS_CONTENEUR[$c['operation']] ?? $c['operation']) . ' · ' . e($mode) . ($c['scanner'] ? ' + scanner' : '');
}

function statut(array $c): string
{
    if ($c['livre_le']) return 'Livré le ' . date('d/m/Y', strtotime($c['livre_le'])) . ($c['livre_par'] ? ' par ' . e($c['livre_par']) : '');
    if ($c['date_livraison'] < date('Y-m-d')) return '<b class="negatif">En retard</b>';
    return $c['date_livraison'] === date('Y-m-d') ? 'À livrer aujourd\'hui' : 'À livrer';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dossier <?= e($dossier['numero_bl']) ?> – La Lorraine</title>
    <link rel="icon" href="assets/logo.jpg">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
    <style>
        body { background: #e5e7eb; }
        .outils { max-width: 210mm; margin: 16px auto; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 0 16px; }
        .outils .btn { margin: 0; }
        .outils label { display: flex; gap: 6px; align-items: center; margin: 0 0 0 auto; font-weight: 500; }
        .feuille { width: 210mm; max-width: 100%; margin: 0 auto 30px; padding: 14mm 14mm 12mm; background: #fff;
                   box-shadow: 0 6px 24px rgba(0,0,0,.15); }
        .fd-tete { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;
                   padding-bottom: 10px; border-bottom: 4px solid var(--accent); box-shadow: 0 4px 0 var(--primaire); }
        .fd-tete img { height: 62px; }
        .fd-titre { text-align: right; }
        .fd-titre h1 { font-size: 20pt; color: var(--primaire); letter-spacing: .04em; }
        .fd-titre .numero { display: inline-block; margin-top: 4px; padding: 3px 12px; border-radius: 6px; background: var(--primaire); color: #fff; font-weight: 700; }
        .fd-titre p { font-size: 9.5pt; color: var(--gris); margin-top: 4px; }
        .fd-bloc { border: 1px solid var(--bord); border-radius: 8px; overflow: hidden; margin-top: 14px; break-inside: avoid; page-break-inside: avoid; }
        .fd-bloc h2 { display: flex; justify-content: space-between; gap: 10px; font-size: 10.5pt; text-transform: uppercase; letter-spacing: .05em;
                      margin: 0; padding: 7px 12px; background: #eef3fc; color: var(--primaire); border-bottom: 1px solid var(--bord); }
        .fd-bloc h2 span { text-transform: none; letter-spacing: 0; font-weight: 600; color: var(--texte); }
        .fd-colonnes { display: grid; grid-template-columns: 1fr 1fr; }
        .fd-colonnes table + table { border-left: 1px solid #eef2f7; }
        .fd-bloc table { font-size: 9.5pt; }
        .fd-bloc th { text-transform: none; background: none; color: var(--gris); font-weight: 500; width: 44%; font-size: 9.5pt; letter-spacing: 0; white-space: normal; border-radius: 0; }
        .fd-bloc th, .fd-bloc td { padding: 5px 12px; border-bottom: 1px solid #eef2f7; }
        .fd-bloc tbody tr:nth-child(even) td, .fd-bloc tbody tr:hover td { background: none; }
        .fd-resume { display: grid; grid-template-columns: repeat(3, 1fr); text-align: center; }
        .fd-resume div { padding: 10px; border-right: 1px solid var(--bord); }
        .fd-resume div:last-child { border-right: 0; }
        .fd-resume small { display: block; color: var(--gris); font-size: 8.5pt; text-transform: uppercase; }
        .fd-resume b { font-size: 14pt; color: var(--primaire); }
        .fd-obs { height: 60px; padding: 8px 12px; background: repeating-linear-gradient(transparent 0 22px, #e5e7eb 22px 23px); }
        .fd-signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 16px; break-inside: avoid; }
        .fd-signatures div { border: 1px solid var(--bord); border-radius: 8px; height: 80px; padding: 8px 10px; font-size: 9.5pt; color: var(--gris); }
        .fd-pied { text-align: center; font-size: 8pt; color: var(--gris); margin-top: 12px; padding-top: 6px; border-top: 1px solid var(--bord); }
        .sans-montants .fd-montant { display: none; }
        @media print {
            body { background: #fff; }
            .outils { display: none !important; }
            .feuille { width: auto; margin: 0; padding: 0; box-shadow: none; }
            @page { size: A4; margin: 12mm; }
        }
        @media (max-width: 700px) {
            .fd-colonnes, .fd-resume { grid-template-columns: 1fr; }
            .fd-colonnes table + table { border-left: 0; }
            .feuille { padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="outils">
        <button type="button" class="btn btn-auto" onclick="window.print()">Imprimer le dossier</button>
        <a href="conteneurs.php?statut=tous&amp;q=<?= urlencode($dossier['numero_bl']) ?>" class="btn btn-auto btn-gris" data-retour>&larr; Retour</a>
        <label><input type="checkbox" id="masquer"> Masquer les montants</label>
    </div>

    <div class="feuille" id="feuille">
        <div class="fd-tete">
            <img src="assets/logo.jpg" alt="La Lorraine – Transport, Import, Export">
            <div class="fd-titre">
                <h1>FICHE DOSSIER</h1>
                <span class="numero">BL <?= e($dossier['numero_bl']) ?></span>
                <p>Édité le <?= date('d/m/Y à H:i') ?> par <?= e($_SESSION['nom']) ?></p>
            </div>
        </div>

        <div class="fd-bloc">
            <h2>Dossier</h2>
            <div class="fd-colonnes">
                <table>
                    <?= ligne('N° de dossier (BL)', '<b>' . e($dossier['numero_bl']) . '</b>') ?>
                    <?= ligne('Client', '<b>' . e($dossier['client']) . '</b>') ?>
                    <?= ligne('Transitaire', e($dossier['transitaire'])) ?>
                </table>
                <table>
                    <?= ligne('Réception du dossier', jour($dossier['date_reception'])) ?>
                    <?= ligne('Conteneurs', count($conteneurs) . ' (' . $nbLivres . ' livré' . ($nbLivres > 1 ? 's' : '') . ')') ?>
                    <?= ligne('Total', '<span class="fd-montant"><b>' . montant((float) $total, $devise) . '</b></span>') ?>
                </table>
            </div>
        </div>

        <?php foreach ($conteneurs as $i => $c): ?>
            <div class="fd-bloc">
                <h2>Conteneur <?= $i + 1 ?> / <?= count($conteneurs) ?>
                    <span><?= e($c['numero']) ?> · <?= TAILLES_CONTENEUR[$c['taille']] ?? e($c['taille']) ?></span></h2>
                <div class="fd-colonnes">
                    <table>
                        <?= ligne('N° de plomb', e((string) $c['plomb'])) ?>
                        <?= ligne('DO', '<b>' . jour($c['date_livraison']) . '</b>') ?>
                        <?= ligne('Livraison', prestation($c)) ?>
                        <?= ligne('Tracteur / semi-remorque', $c['tracteur'] ? e($c['tracteur']) . ' + ' . e((string) $c['remorque']) . ($c['remorque_type'] ? ' (' . e($c['remorque_type']) . ')' : '') : null) ?>
                        <?= ligne('Chauffeur', $c['ch_nom'] ? '<b>' . e($c['ch_prenom'] . ' ' . $c['ch_nom']) . '</b>' . ($c['ch_tel'] ? '<br>' . e($c['ch_tel']) : '') : null) ?>
                        <?= ligne('Statut', statut($c)) ?>
                    </table>
                    <table>
                        <?= ligne('Récupération du conteneur', jour($c['date_recuperation'])) ?>
                        <?= ligne('Date de livraison', jour($c['livre_le'])) ?>
                        <?= ligne('Récupération chez le client', jour($c['date_recuperation_client'])) ?>
                        <?= ligne('Date de restitution', jour($c['date_restitution'])) ?>
                        <?= ligne('Lieu de restitution', e((string) $c['lieu_restitution'])) ?>
                        <tr class="fd-montant"><th>Prix</th><td><?= $c['prix'] !== null ? '<b>' . montant((float) $c['prix'], $devise) . '</b>' : '—' ?></td></tr>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$conteneurs): ?>
            <p class="vide">Aucun conteneur dans ce dossier.</p>
        <?php endif; ?>

        <div class="fd-bloc">
            <h2>Récapitulatif</h2>
            <div class="fd-resume">
                <div><small>Conteneurs</small><b><?= count($conteneurs) ?></b></div>
                <div><small>Livrés</small><b><?= $nbLivres ?> / <?= count($conteneurs) ?></b></div>
                <div class="fd-montant"><small>Montant total</small><b><?= montant((float) $total, $devise) ?></b></div>
            </div>
        </div>

        <div class="fd-bloc">
            <h2>Observations</h2>
            <div class="fd-obs"></div>
        </div>

        <div class="fd-signatures">
            <div>Le responsable d'exploitation</div>
            <div>Visa du client / transitaire</div>
        </div>

        <div class="fd-pied">La Lorraine – Transport · Import · Export · Document généré le <?= date('d/m/Y à H:i') ?></div>
    </div>

    <script>
        // Version sans montants (le choix est mémorisé)
        var caseMasquer = document.getElementById('masquer');
        var feuille = document.getElementById('feuille');
        try { caseMasquer.checked = localStorage.getItem('fd_masquer') === '1'; } catch (e) {}
        function appliquer() {
            feuille.classList.toggle('sans-montants', caseMasquer.checked);
            try { localStorage.setItem('fd_masquer', caseMasquer.checked ? '1' : '0'); } catch (e) {}
        }
        caseMasquer.addEventListener('change', appliquer);
        appliquer();

        // Retour : page précédente de l'application, sinon les conteneurs de ce dossier
        document.querySelector('[data-retour]').addEventListener('click', function (ev) {
            var precedente = document.referrer;
            if (precedente.indexOf(location.origin + '/') === 0 && precedente !== location.href && history.length > 1) {
                ev.preventDefault();
                history.back();
            }
        });
    </script>
</body>
</html>
