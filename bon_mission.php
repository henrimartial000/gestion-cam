<?php
// Bon de mission imprimable (format A4), à remettre au chauffeur
require_once __DIR__ . '/config.php';
exiger_connexion();

$stmt = db()->prepare(
    'SELECT m.*, c.immatriculation, c.modele, c.reservoir,
            r.immatriculation AS remorque, r.type AS remorque_type, r.charge_utile,
            ch.nom AS ch_nom, ch.prenom AS ch_prenom, ch.telephone AS ch_tel, ch.telephone2 AS ch_tel2,
            ch.numero_permis, ch.ville AS ch_ville, ch.adresse AS ch_adresse,
            d.nom AS destination, d.distance_km AS distance_aller
     FROM missions m
     JOIN camions c          ON c.id = m.camion_id
     LEFT JOIN remorques r   ON r.id = m.remorque_id
     LEFT JOIN chauffeurs ch ON ch.id = m.chauffeur_id
     LEFT JOIN destinations d ON d.id = m.destination_id
     WHERE m.id = ?'
);
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$m = $stmt->fetch();

if (!$m) {
    flash('Mission introuvable.', 'erreur');
    header('Location: disponibilite.php');
    exit;
}

$params   = parametres();
$devise   = $params['devise'];
$prix     = (float) ($m['prix_litre'] ?? $params['prix_litre']);
$litres   = (float) $m['carburant_l'];
$cout     = $litres * $prix;
$montant  = (float) $m['montant'];
$frais    = (float) $m['frais'];
$benefice = $montant - $cout - $frais;
$etats    = ['charge' => 'Chargé', 'vide' => 'À vide', 'aucun' => 'Pas de retour'];
$numero   = 'M-' . date('Y', strtotime($m['depart'])) . '-' . str_pad($m['id'], 4, '0', STR_PAD_LEFT);
$nomChauffeur = $m['ch_nom'] ? $m['ch_prenom'] . ' ' . $m['ch_nom'] : $m['chauffeur'];

function ligne(string $libelle, $valeur): string
{
    return '<tr><th>' . e($libelle) . '</th><td>' . ($valeur === null || $valeur === '' ? '—' : $valeur) . '</td></tr>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bon de mission <?= e($numero) ?> – La Lorraine</title>
    <link rel="icon" href="assets/logo.jpg">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
    <style>
        body { background: #e5e7eb; }
        .outils { max-width: 210mm; margin: 16px auto; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 0 16px; }
        .outils .btn { margin: 0; }
        .outils label { display: flex; gap: 6px; align-items: center; margin: 0 0 0 auto; font-weight: 500; }
        .feuille { width: 210mm; max-width: 100%; min-height: 297mm; margin: 0 auto 30px; padding: 14mm 14mm 12mm; background: #fff;
                   box-shadow: 0 6px 24px rgba(0,0,0,.15); display: flex; flex-direction: column; }
        .bm-tete { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;
                   padding-bottom: 10px; border-bottom: 4px solid var(--accent); box-shadow: 0 4px 0 var(--primaire); }
        .bm-tete img { height: 62px; }
        .bm-titre { text-align: right; }
        .bm-titre h1 { font-size: 20pt; color: var(--primaire); letter-spacing: .04em; }
        .bm-titre .numero { display: inline-block; margin-top: 4px; padding: 3px 12px; border-radius: 6px; background: var(--primaire); color: #fff; font-weight: 700; }
        .bm-titre p { font-size: 9.5pt; color: var(--gris); margin-top: 4px; }
        .bm-grille { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 18px; }
        .bm-bloc { border: 1px solid var(--bord); border-radius: 8px; overflow: hidden; }
        .bm-bloc h2 { font-size: 10.5pt; text-transform: uppercase; letter-spacing: .05em; margin: 0; padding: 7px 12px; background: #eef3fc; color: var(--primaire); border-bottom: 1px solid var(--bord); }
        .bm-bloc table { font-size: 10pt; }
        .bm-bloc th { text-transform: none; background: none; color: var(--gris); font-weight: 500; width: 42%; font-size: 10pt; }
        .bm-bloc th, .bm-bloc td { padding: 6px 12px; border-bottom: 1px solid #eef2f7; }
        .bm-bloc tr:last-child th, .bm-bloc tr:last-child td { border-bottom: 0; }
        .bm-large { grid-column: 1 / -1; }
        .bm-carburant { display: grid; grid-template-columns: repeat(3, 1fr); text-align: center; }
        .bm-carburant div { padding: 12px; border-right: 1px solid var(--bord); }
        .bm-carburant div:last-child { border-right: 0; }
        .bm-carburant small { display: block; color: var(--gris); font-size: 8.5pt; text-transform: uppercase; }
        .bm-carburant b { font-size: 15pt; color: var(--primaire); }
        .bm-obs { height: 70px; padding: 8px 12px; background: repeating-linear-gradient(transparent 0 22px, #e5e7eb 22px 23px); }
        .bm-signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: auto; padding-top: 18px; }
        .bm-signatures div { border: 1px solid var(--bord); border-radius: 8px; height: 90px; padding: 8px 10px; font-size: 9.5pt; color: var(--gris); }
        .bm-pied { text-align: center; font-size: 8pt; color: var(--gris); margin-top: 12px; padding-top: 6px; border-top: 1px solid var(--bord); }
        .sans-montants .bm-finances { display: none; }
        @media print {
            body { background: #fff; }
            .outils { display: none !important; }
            .feuille { width: auto; min-height: calc(297mm - 24mm); margin: 0; padding: 0; box-shadow: none; }
            @page { size: A4; margin: 12mm; }
        }
        @media (max-width: 700px) {
            .bm-grille { grid-template-columns: 1fr; }
            .feuille { padding: 16px; min-height: 0; }
        }
    </style>
</head>
<body>
    <div class="outils">
        <button type="button" class="btn btn-auto" onclick="window.print()">Imprimer le bon</button>
        <a href="disponibilite.php" class="btn btn-auto btn-gris" data-retour>&larr; Retour</a>
        <label><input type="checkbox" id="masquer"> Masquer les montants (version chauffeur)</label>
    </div>

    <div class="feuille" id="feuille">
        <div class="bm-tete">
            <img src="assets/logo.jpg" alt="La Lorraine – Transport, Import, Export">
            <div class="bm-titre">
                <h1>BON DE MISSION</h1>
                <span class="numero">N° <?= e($numero) ?></span>
                <p>Établi le <?= date('d/m/Y à H:i', strtotime($m['depart'])) ?>
                    <?= $m['cree_par'] ? ' par ' . e($m['cree_par']) : '' ?>
                    <?= $m['terminee_le'] ? '<br>Mission terminée le ' . date('d/m/Y', strtotime($m['terminee_le']))
                        . ($m['terminee_par'] ? ' par ' . e($m['terminee_par']) : '') : '' ?></p>
            </div>
        </div>

        <div class="bm-grille">
            <div class="bm-bloc">
                <h2>Véhicule</h2>
                <table>
                    <?= ligne('Tracteur', '<b>' . e($m['immatriculation']) . '</b>') ?>
                    <?= ligne('Modèle', e($m['modele'])) ?>
                    <?= ligne('Semi-remorque', $m['remorque'] ? '<b>' . e($m['remorque']) . '</b>' : null) ?>
                    <?= ligne('Type / charge utile', $m['remorque'] ? e($m['remorque_type']) . ($m['charge_utile'] !== null ? ' · ' . e((string) (float) $m['charge_utile']) . ' t' : '') : null) ?>
                    <?= ligne('Réservoir', e((string) $m['reservoir']) . ' L') ?>
                </table>
            </div>

            <div class="bm-bloc">
                <h2>Chauffeur</h2>
                <table>
                    <?= ligne('Nom', '<b>' . e($nomChauffeur) . '</b>') ?>
                    <?= ligne('Téléphone', e($m['ch_tel'] . ($m['ch_tel2'] ? ' / ' . $m['ch_tel2'] : ''))) ?>
                    <?= ligne('N° de permis', e((string) $m['numero_permis'])) ?>
                    <?= ligne('Domicile', e(trim($m['ch_ville'] . ($m['ch_adresse'] ? ', ' . $m['ch_adresse'] : ''), ', '))) ?>
                </table>
            </div>

            <div class="bm-bloc bm-large">
                <h2>Trajet</h2>
                <table>
                    <?= ligne('Départ', 'Dépôt — le ' . date('d/m/Y à H:i', strtotime($m['depart']))) ?>
                    <?= ligne('Destination', '<b>' . e($m['destination'] ?? '?') . '</b>' . ($m['distance_aller'] ? ' — ' . $m['distance_aller'] . ' km' : '')) ?>
                    <?= ligne('Aller', $etats[$m['aller_etat']] ?? '—') ?>
                    <?= ligne('Retour', $etats[$m['retour_etat']] ?? '—') ?>
                    <?= ligne('Distance totale', $m['distance_km'] ? number_format($m['distance_km'], 0, ',', ' ') . ' km' : null) ?>
                    <?= ligne('Retour prévu', $m['retour_prevu'] ? date('d/m/Y', strtotime($m['retour_prevu'])) : null) ?>
                </table>
            </div>

            <div class="bm-bloc bm-large">
                <h2>Carburant prévu</h2>
                <div class="bm-carburant">
                    <div><small>Quantité</small><b><?= litres($litres) ?></b></div>
                    <div class="bm-finances"><small>Prix du litre</small><b><?= montant($prix, $devise) ?></b></div>
                    <div class="bm-finances"><small>Montant carburant</small><b><?= montant($cout, $devise) ?></b></div>
                </div>
            </div>

            <div class="bm-bloc bm-large bm-finances">
                <h2>Informations financières</h2>
                <table>
                    <?= ligne('Montant facturé', montant($montant, $devise)) ?>
                    <?= ligne('Coût du carburant', montant($cout, $devise)) ?>
                    <?= ligne('Autres frais', montant($frais, $devise)) ?>
                    <?= ligne('Bénéfice estimé', '<b class="' . ($benefice < 0 ? 'negatif' : 'positif') . '">' . montant($benefice, $devise) . '</b>') ?>
                </table>
            </div>

            <div class="bm-bloc bm-large">
                <h2>Observations</h2>
                <div class="bm-obs"></div>
            </div>
        </div>

        <div class="bm-signatures">
            <div>Le chauffeur<br><small>(nom et signature)</small></div>
            <div>Le responsable d'exploitation</div>
            <div>Visa au retour<br><small>(date, km, carburant)</small></div>
        </div>

        <div class="bm-pied">La Lorraine – Transport · Import · Export · Document généré le <?= date('d/m/Y à H:i') ?></div>
    </div>

    <script>
        // Version chauffeur : cache les montants (le choix est mémorisé)
        var caseMasquer = document.getElementById('masquer');
        var feuille = document.getElementById('feuille');
        try { caseMasquer.checked = localStorage.getItem('bm_masquer') === '1'; } catch (e) {}
        function appliquer() {
            feuille.classList.toggle('sans-montants', caseMasquer.checked);
            try { localStorage.setItem('bm_masquer', caseMasquer.checked ? '1' : '0'); } catch (e) {}
        }
        caseMasquer.addEventListener('change', appliquer);
        appliquer();

        // Retour : page précédente de l'application, sinon la page Disponibilité et missions
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
