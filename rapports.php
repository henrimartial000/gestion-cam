<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

require_once __DIR__ . '/includes/stats.php';

$params = parametres();
$devise = $params['devise'];

// Suppression d'une mission terminée, puis retour sur la même période
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer_mission') {
    verifier_csrf();
    $stmt = db()->prepare('DELETE FROM missions WHERE id = ? AND terminee_le IS NOT NULL');
    $stmt->execute([(int) $_POST['id']]);
    flash($stmt->rowCount() ? 'Mission supprimée.' : 'Une mission en cours ne peut pas être supprimée : terminez-la d\'abord.',
          $stmt->rowCount() ? 'succes' : 'erreur');
    header('Location: rapports.php?' . http_build_query(['periode' => $_GET['periode'] ?? 'mois', 'date' => $_GET['date'] ?? '']));
    exit;
}

// Période choisie
$periode = in_array($_GET['periode'] ?? '', ['jour', 'semaine', 'mois'], true) ? $_GET['periode'] : 'mois';
$date    = DateTime::createFromFormat('Y-m-d', $_GET['date'] ?? '') ?: new DateTime();
[$debut, $fin] = bornes($periode, $date);

$missions = missions_entre($debut, $fin);
$total    = totaux($missions);

$pas       = ['jour' => '1 day', 'semaine' => '7 days', 'mois' => '1 month'][$periode];
$precedent = (clone $debut)->modify("-$pas")->format('Y-m-d');
$suivant   = (clone $debut)->modify("+$pas")->format('Y-m-d');

$finAffichee = (clone $fin)->modify('-1 day');
if ($periode === 'jour') {
    $libellePeriode = JOURS[$debut->format('N')] . ' ' . $debut->format('j') . ' ' . MOIS[$debut->format('n')] . ' ' . $debut->format('Y');
} elseif ($periode === 'semaine') {
    $libellePeriode = 'Semaine du ' . $debut->format('d/m') . ' au ' . $finAffichee->format('d/m/Y');
} else {
    $libellePeriode = ucfirst(MOIS[$debut->format('n')]) . ' ' . $debut->format('Y');
}

// Résumé rapide : aujourd'hui, cette semaine, ce mois
$resume = [];
foreach (['jour' => "Aujourd'hui", 'semaine' => 'Cette semaine', 'mois' => 'Ce mois'] as $p => $nom) {
    [$d, $f] = bornes($p, new DateTime());
    $resume[$p] = ['nom' => $nom, 't' => totaux(missions_entre($d, $f))];
}

// Détail par jour (semaine et mois)
$parJour = [];
if ($periode !== 'jour') {
    for ($j = clone $debut; $j < $fin; $j->modify('+1 day')) {
        $parJour[$j->format('Y-m-d')] = ['date' => clone $j, 'missions' => []];
    }
    foreach ($missions as $m) {
        $parJour[substr($m['depart'], 0, 10)]['missions'][] = $m;
    }
    foreach ($parJour as &$jour) {
        $jour['t'] = totaux($jour['missions']);
    }
    unset($jour);
    $maxLitres = max(1, max(array_map(function ($j) { return $j['t']['litres']; }, $parJour)));
}

// Détail par camion
$parCamion = [];
foreach ($missions as $m) {
    $parCamion[$m['immatriculation']][] = $m;
}
ksort($parCamion);

$titre = 'Rapports';
$page  = 'rapports';
require __DIR__ . '/includes/entete.php';
?>

<section class="stats stats-resume">
    <?php foreach ($resume as $p => $r): ?>
        <a class="stat" href="?periode=<?= $p ?>">
            <small><?= $r['nom'] ?></small>
            <span><?= litres($r['t']['litres']) ?></span>
            Bénéfice : <b class="<?= classe_benefice($r['t']['benefice']) ?>"><?= montant($r['t']['benefice'], $devise) ?></b>
        </a>
    <?php endforeach; ?>
</section>

<section class="panneau">
    <div class="titre-recherche">
        <div class="onglets">
            <?php foreach (['jour' => 'Jour', 'semaine' => 'Semaine', 'mois' => 'Mois'] as $p => $nom): ?>
                <a href="?periode=<?= $p ?>&date=<?= $debut->format('Y-m-d') ?>" class="<?= $p === $periode ? 'actif' : '' ?>"><?= $nom ?></a>
            <?php endforeach; ?>
        </div>
        <div class="navigation-periode">
            <a href="?periode=<?= $periode ?>&date=<?= $precedent ?>" class="btn btn-auto btn-gris">◀</a>
            <b><?= e($libellePeriode) ?></b>
            <a href="?periode=<?= $periode ?>&date=<?= $suivant ?>" class="btn btn-auto btn-gris">▶</a>
            <form method="get">
                <input type="hidden" name="periode" value="<?= $periode ?>">
                <input type="date" name="date" value="<?= $debut->format('Y-m-d') ?>" onchange="this.form.submit()">
            </form>
        </div>
    </div>

    <div class="chiffres">
        <div><small>Missions</small><b><?= $total['missions'] ?></b></div>
        <div><small>Distance</small><b><?= number_format($total['km'], 0, ',', ' ') ?> km</b></div>
        <div><small>Carburant</small><b><?= litres($total['litres']) ?></b></div>
        <div><small>Coût carburant</small><b><?= montant($total['cout'], $devise) ?></b></div>
        <div><small>Autres frais</small><b><?= montant($total['frais'], $devise) ?></b></div>
        <div><small>Recettes</small><b><?= montant($total['montant'], $devise) ?></b></div>
        <div class="chiffre-benefice"><small>Bénéfice</small>
            <b class="<?= classe_benefice($total['benefice']) ?>"><?= montant($total['benefice'], $devise) ?></b></div>
    </div>
    <p class="petit">Bénéfice = recettes − coût du carburant − autres frais. Les missions sont comptées à leur date de départ.</p>
</section>

<?php if ($periode !== 'jour'): ?>
    <section class="panneau">
        <h2>Consommation par jour</h2>
        <table>
            <thead>
                <tr><th>Jour</th><th>Missions</th><th class="col-barre">Carburant</th><th>Coût</th><th>Recettes</th><th>Bénéfice</th></tr>
            </thead>
            <tbody>
                <?php foreach ($parJour as $cle => $j): ?>
                    <tr class="<?= $j['t']['missions'] ? '' : 'ligne-vide' ?>">
                        <td><a href="?periode=jour&date=<?= $cle ?>" class="lien"><?= JOURS[$j['date']->format('N')] ?> <?= $j['date']->format('d/m') ?></a></td>
                        <td><?= $j['t']['missions'] ?: '—' ?></td>
                        <td class="col-barre">
                            <div class="barre"><div style="width:<?= round($j['t']['litres'] / $maxLitres * 100) ?>%"></div></div>
                            <?= $j['t']['litres'] ? litres($j['t']['litres']) : '' ?>
                        </td>
                        <td><?= $j['t']['missions'] ? montant($j['t']['cout'], $devise) : '' ?></td>
                        <td><?= $j['t']['missions'] ? montant($j['t']['montant'], $devise) : '' ?></td>
                        <td class="<?= classe_benefice($j['t']['benefice']) ?>"><?= $j['t']['missions'] ? montant($j['t']['benefice'], $devise) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<?php if ($parCamion): ?>
    <section class="panneau">
        <h2>Par camion</h2>
        <table>
            <thead>
                <tr><th>Camion</th><th>Missions</th><th>Distance</th><th>Carburant</th><th>Coût</th><th>Recettes</th><th>Bénéfice</th></tr>
            </thead>
            <tbody>
                <?php foreach ($parCamion as $immat => $liste): $t = totaux($liste); ?>
                    <tr>
                        <td><b><?= e($immat) ?></b></td>
                        <td><?= $t['missions'] ?></td>
                        <td><?= number_format($t['km'], 0, ',', ' ') ?> km</td>
                        <td><?= litres($t['litres']) ?></td>
                        <td><?= montant($t['cout'], $devise) ?></td>
                        <td><?= montant($t['montant'], $devise) ?></td>
                        <td class="<?= classe_benefice($t['benefice']) ?>"><b><?= montant($t['benefice'], $devise) ?></b></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<section class="panneau">
    <h2>Missions de la période</h2>
    <table>
        <thead>
            <tr><th>Départ</th><th>Camion</th><th>Trajet</th><th>Carburant</th><th>Coût</th><th>Facturé</th><th>Frais</th><th>Bénéfice</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($missions as $m): ?>
                <tr>
                    <td><?= date('d/m H:i', strtotime($m['depart'])) ?>
                        <?php if ($m['cree_par']): ?><br><span class="petit">par <?= e($m['cree_par']) ?></span><?php endif; ?></td>
                    <td><?= e($m['immatriculation']) ?><br><span class="petit"><?= e($m['chauffeur']) ?></span></td>
                    <td>
                        <?= e($m['destination'] ?? '?') ?>
                        <br><span class="petit">
                            aller <?= $m['aller_etat'] === 'vide' ? 'à vide' : 'chargé' ?><?php if ($m['retour_etat'] !== 'aucun'): ?>,
                            retour <?= $m['retour_etat'] === 'vide' ? 'à vide' : 'chargé' ?><?php endif; ?>
                            <?= $m['distance_km'] ? '· ' . $m['distance_km'] . ' km' : '' ?>
                        </span>
                    </td>
                    <td><?= $m['carburant_l'] !== null ? litres($m['litres']) : '<span class="petit">non renseigné</span>' ?></td>
                    <td><?= montant($m['cout'], $devise) ?></td>
                    <td><?= $m['montant'] ? montant($m['montant'], $devise) : '<span class="alerte-texte">à saisir</span>' ?></td>
                    <td><?= montant($m['frais'], $devise) ?></td>
                    <td class="<?= classe_benefice($m['benefice']) ?>"><b><?= montant($m['benefice'], $devise) ?></b></td>
                    <td class="actions">
                        <a href="bon_mission.php?id=<?= $m['id'] ?>" class="lien">Bon</a>
                        <a href="mission.php?id=<?= $m['id'] ?>" class="lien">Modifier</a>
                        <?php if ($m['terminee_le']): ?>
                            <form method="post" action="?<?= e(http_build_query(['periode' => $periode, 'date' => $debut->format('Y-m-d')])) ?>"
                                  onsubmit="return confirm('Supprimer définitivement cette mission ?\nElle disparaîtra de l\'historique et des rapports.');">
                                <?= csrf_champ() ?>
                                <input type="hidden" name="action" value="supprimer_mission">
                                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                <button type="submit" class="lien lien-rouge">Supprimer</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-mission">En cours</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$missions): ?>
                <tr><td colspan="9" class="vide">Aucune mission sur cette période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
