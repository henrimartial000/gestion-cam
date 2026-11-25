<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/stats.php';
exiger_connexion();

$devise     = parametres()['devise'];
$aujourdhui = new DateTime('today');
$auj        = $aujourdhui->format('Y-m-d');

// ---------- Flotte ----------
$camions    = camions_avec_statut();
$chauffeurs = chauffeurs_avec_statut();
$remorques  = db()->query('SELECT camion_id FROM remorques')->fetchAll();

$compteurs = ['disponible' => 0, 'mission' => 0, 'maintenance' => 0];
foreach ($camions as $c) $compteurs[$c['statut']]++;
$nbCamions   = count($camions);
$utilisation = $nbCamions ? round($compteurs['mission'] / $nbCamions * 100) : 0;

$chauffeursLibres = count(array_filter($chauffeurs, function ($ch) { return !$ch['mission_id']; }));
$remorquesDepot   = count(array_filter($remorques, function ($r) { return !$r['camion_id']; }));

// Anneau de répartition (conic-gradient) : mission, maintenance, disponible
$anneau = '#e5e7eb 0 100%';
if ($nbCamions) {
    $pMission = $compteurs['mission'] / $nbCamions * 100;
    $pMaint   = $pMission + $compteurs['maintenance'] / $nbCamions * 100;
    $anneau   = sprintf('var(--primaire) 0 %.2f%%, var(--orange) %.2f%% %.2f%%, var(--vert) %.2f%% 100%%',
        $pMission, $pMission, $pMaint, $pMaint);
}

// ---------- Finances, avec comparaison à la période précédente ----------
function variation(float $actuel, float $precedent): ?float
{
    if ($precedent == 0) return null;
    return ($actuel - $precedent) / abs($precedent) * 100;
}

$libellesPrecedent = ['jour' => 'hier', 'semaine' => 'sem. dernière', 'mois' => 'mois dernier'];
$periodes = [];
foreach (['jour' => "Aujourd'hui", 'semaine' => 'Cette semaine', 'mois' => 'Ce mois'] as $p => $nom) {
    [$d, $f] = bornes($p, new DateTime());
    [$dp, $fp] = bornes($p, (clone $d)->modify('-1 day'));
    $t  = totaux(missions_entre($d, $f));
    $tp = totaux(missions_entre($dp, $fp));
    $periodes[$p] = [
        'nom' => $nom, 't' => $t,
        'var_litres'   => variation($t['litres'], $tp['litres']),
        'var_benefice' => variation($t['benefice'], $tp['benefice']),
        'precedent'    => $libellesPrecedent[$p],
    ];
}

function badge_variation(?float $v, string $precedent, bool $hausseEstBonne = true): string
{
    if ($v === null) return '<span class="tendance neutre">— vs ' . $precedent . '</span>';
    $hausse = $v >= 0;
    $classe = ($hausse === $hausseEstBonne) ? 'bonne' : 'mauvaise';
    return '<span class="tendance ' . $classe . '">' . ($hausse ? '▲' : '▼') . ' '
        . number_format(abs($v), 0, ',', ' ') . ' % vs ' . $precedent . '</span>';
}

// ---------- 14 derniers jours ----------
$debut14 = (clone $aujourdhui)->modify('-13 days');
$jours = [];
for ($j = clone $debut14; $j <= $aujourdhui; $j->modify('+1 day')) {
    $jours[$j->format('Y-m-d')] = ['date' => clone $j, 'litres' => 0, 'benefice' => 0, 'missions' => 0];
}
foreach (missions_entre($debut14, (clone $aujourdhui)->modify('+1 day')) as $m) {
    $cle = substr($m['depart'], 0, 10);
    $jours[$cle]['litres']   += $m['litres'];
    $jours[$cle]['benefice'] += $m['benefice'];
    $jours[$cle]['missions']++;
}
$maxLitres     = max(1, max(array_column($jours, 'litres')));
$total14       = array_sum(array_column($jours, 'litres'));
$benefice14    = array_sum(array_column($jours, 'benefice'));
$joursActifs   = count(array_filter($jours, function ($j) { return $j['missions'] > 0; }));
$moyenneJour   = $joursActifs ? $total14 / $joursActifs : 0;

// ---------- Alertes ----------
$alertes = [];
foreach ($camions as $c) {
    if ($c['statut'] === 'mission' && $c['retour_prevu'] && $c['retour_prevu'] < $auj) {
        $alertes[] = ['rouge', "{$c['immatriculation']} en retard",
            'Retour prévu le ' . date('d/m', strtotime($c['retour_prevu'])) . ' de ' . ($c['destination'] ?? '?') . " · {$c['chauffeur']}",
            'disponibilite.php?filtre=mission'];
    }
    if ($c['statut'] === 'maintenance' && $c['fin_prevue'] && $c['fin_prevue'] < $auj) {
        $alertes[] = ['orange', "Maintenance de {$c['immatriculation']} en retard",
            'Prévue le ' . date('d/m', strtotime($c['fin_prevue'])) . " · {$c['motif']}", 'disponibilite.php?filtre=maintenance'];
    }
}
$sansMontant = (int) db()->query('SELECT COUNT(*) FROM missions WHERE montant = 0')->fetchColumn();
if ($sansMontant) {
    $alertes[] = ['orange', "$sansMontant mission(s) sans montant facturé", 'Le bénéfice affiché est faussé', 'rapports.php?periode=mois'];
}
if (!$chauffeursLibres && $chauffeurs) {
    $alertes[] = ['orange', 'Aucun chauffeur disponible', 'Tous les chauffeurs sont en mission', 'chauffeurs.php'];
}
if (!$compteurs['disponible'] && $camions) {
    $alertes[] = ['orange', 'Aucun camion disponible', 'Toute la flotte est occupée', 'disponibilite.php'];
}

// ---------- Missions en cours, avec progression jusqu'au retour prévu ----------
$enCours = [];
foreach ($camions as $c) {
    if ($c['statut'] !== 'mission') continue;
    $c['progression'] = null;
    if ($c['retour_prevu']) {
        $debut = strtotime($c['depart']);
        $fin   = strtotime($c['retour_prevu'] . ' 23:59:59');
        $c['progression'] = $fin > $debut ? max(0, min(100, round((time() - $debut) / ($fin - $debut) * 100))) : 100;
    }
    $c['retard'] = $c['retour_prevu'] && $c['retour_prevu'] < $auj;
    $enCours[] = $c;
}

// ---------- Classement des camions du mois ----------
[$dMois, $fMois] = bornes('mois', new DateTime());
$parCamion = [];
foreach (missions_entre($dMois, $fMois) as $m) {
    $parCamion[$m['immatriculation']][] = $m;
}
$classement = [];
foreach ($parCamion as $immat => $liste) {
    $classement[$immat] = totaux($liste);
}
uasort($classement, function ($a, $b) { return $b['benefice'] <=> $a['benefice']; });
$classement  = array_slice($classement, 0, 5, true);
$maxBenefice = max(1, max(array_map(function ($t) { return abs($t['benefice']); }, $classement ?: [['benefice' => 1]])));

// ---------- Bandeau ----------
$heure     = (int) date('G');
$salut     = $heure < 12 ? 'Bonjour' : ($heure < 18 ? 'Bon après-midi' : 'Bonsoir');
$dateDuJour = ucfirst(['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][$aujourdhui->format('N')])
    . ' ' . $aujourdhui->format('j') . ' ' . MOIS[$aujourdhui->format('n')] . ' ' . $aujourdhui->format('Y');

$titre = 'Tableau de bord';
$page  = 'dashboard';
require __DIR__ . '/includes/entete.php';
?>

<div class="tdb">

<!-- Bandeau d'accueil -->
<section class="hero">
    <div class="hero-texte">
        <p class="hero-date"><?= $dateDuJour ?></p>
        <h1><?= $salut ?>, <?= e(ucfirst($_SESSION['identifiant'])) ?></h1>
        <p class="hero-resume">
            <b><?= $compteurs['mission'] ?></b> camion(s) sur la route ·
            <b><?= $compteurs['disponible'] ?></b> prêt(s) à partir ·
            <b><?= litres($periodes['jour']['t']['litres']) ?></b> de carburant aujourd'hui
        </p>
        <div class="hero-actions">
            <a href="disponibilite.php#m-camion" class="btn btn-auto btn-blanc">Nouvelle mission</a>
            <a href="carburant.php" class="btn btn-auto btn-transparent">Calcul carburant</a>
            <a href="rapports.php" class="btn btn-auto btn-transparent">Rapports</a>
        </div>
    </div>
</section>

<!-- Indicateurs de la flotte -->
<section class="kpis">
    <a class="kpi kpi-vert" href="disponibilite.php?filtre=disponible">
        <span class="kpi-valeur"><?= $compteurs['disponible'] ?></span>
        <span class="kpi-libelle">Camions disponibles</span>
    </a>
    <a class="kpi kpi-bleu" href="disponibilite.php?filtre=mission">
        <span class="kpi-valeur"><?= $compteurs['mission'] ?></span>
        <span class="kpi-libelle">En mission</span>
    </a>
    <a class="kpi kpi-orange" href="disponibilite.php?filtre=maintenance">
        <span class="kpi-valeur"><?= $compteurs['maintenance'] ?></span>
        <span class="kpi-libelle">En maintenance</span>
    </a>
    <a class="kpi kpi-violet" href="chauffeurs.php">
        <span class="kpi-valeur"><?= $chauffeursLibres ?><small>/<?= count($chauffeurs) ?></small></span>
        <span class="kpi-libelle">Chauffeurs disponibles</span>
    </a>
    <a class="kpi kpi-gris" href="remorques.php">
        <span class="kpi-valeur"><?= $remorquesDepot ?><small>/<?= count($remorques) ?></small></span>
        <span class="kpi-libelle">Remorques au dépôt</span>
    </a>
</section>

<!-- Finances -->
<section class="periodes">
    <?php foreach ($periodes as $p => $per): $t = $per['t']; ?>
        <a class="periode periode-<?= $p ?>" href="rapports.php?periode=<?= $p ?>">
            <div class="periode-tete">
                <span><?= $per['nom'] ?></span>
                <span class="periode-missions"><?= $t['missions'] ?> mission(s)</span>
            </div>
            <div class="periode-corps">
                <div>
                    <small>Carburant</small>
                    <div class="periode-litres"><?= litres($t['litres']) ?></div>
                    <?= badge_variation($per['var_litres'], $per['precedent'], false) ?>
                </div>
                <div class="periode-droite">
                    <small>Bénéfice</small>
                    <div class="periode-benefice <?= classe_benefice($t['benefice']) ?>"><?= montant($t['benefice'], $devise) ?></div>
                    <?= badge_variation($per['var_benefice'], $per['precedent']) ?>
                </div>
            </div>
            <div class="periode-pied">
                <span>Carburant : <?= montant($t['cout'], $devise) ?></span>
                <span>Recettes : <?= montant($t['montant'], $devise) ?></span>
            </div>
        </a>
    <?php endforeach; ?>
</section>

<div class="grille-tdb">
    <!-- Graphique -->
    <section class="panneau zone-graphique">
        <div class="panneau-tete">
            <div>
                <h2>Carburant – 14 derniers jours</h2>
                <p class="petit">Moyenne <?= litres($moyenneJour) ?> par jour actif · bénéfice
                    <b class="<?= classe_benefice($benefice14) ?>"><?= montant($benefice14, $devise) ?></b></p>
            </div>
            <div class="total-graphique"><?= litres($total14) ?></div>
        </div>
        <div class="graphique2">
            <div class="grille-fond"><span></span><span></span><span></span><span></span></div>
            <?php foreach ($jours as $cle => $j): $h = round($j['litres'] / $maxLitres * 100); ?>
                <a href="rapports.php?periode=jour&date=<?= $cle ?>" class="col2 <?= $cle === $auj ? 'col-auj' : '' ?>">
                    <span class="bulle"><b><?= $j['date']->format('d/m') ?></b><br><?= litres($j['litres']) ?><br><?= $j['missions'] ?> mission(s)<br><?= montant($j['benefice'], $devise) ?></span>
                    <span class="col-valeur"><?= $j['litres'] ? round($j['litres']) : '' ?></span>
                    <span class="col-baton" style="--h:<?= max($j['litres'] ? 4 : 1, $h) ?>"></span>
                    <span class="col-jour"><?= substr(JOURS[$j['date']->format('N')], 0, 1) ?><br><b><?= $j['date']->format('d') ?></b></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Répartition de la flotte -->
    <section class="panneau zone-anneau">
        <h2>Flotte</h2>
        <div class="anneau" style="background: conic-gradient(<?= $anneau ?>)">
            <div class="anneau-centre">
                <b><?= $utilisation ?> %</b>
                <span>en mission</span>
            </div>
        </div>
        <ul class="legende">
            <li><i style="background:var(--primaire)"></i>En mission <b><?= $compteurs['mission'] ?></b></li>
            <li><i style="background:var(--orange)"></i>Maintenance <b><?= $compteurs['maintenance'] ?></b></li>
            <li><i style="background:var(--vert)"></i>Disponibles <b><?= $compteurs['disponible'] ?></b></li>
        </ul>
    </section>

    <!-- Alertes -->
    <section class="panneau zone-alertes">
        <h2>Alertes <?php if ($alertes): ?><span class="pastille"><?= count($alertes) ?></span><?php endif; ?></h2>
        <?php if (!$alertes): ?>
            <div class="tout-va-bien">
                <b>Tout est en ordre</b>
                <p class="petit">Aucun retard ni anomalie.</p>
            </div>
        <?php endif; ?>
        <?php foreach ($alertes as [$niveau, $titreAlerte, $detail, $lien]): ?>
            <a class="alerte2 alerte2-<?= $niveau ?>" href="<?= $lien ?>">
                <span><b><?= e($titreAlerte) ?></b><br><span class="petit"><?= e($detail) ?></span></span>
            </a>
        <?php endforeach; ?>
    </section>
</div>

<div class="deux-colonnes">
    <!-- Missions en cours -->
    <section class="panneau">
        <div class="panneau-tete">
            <h2>Sur la route (<?= count($enCours) ?>)</h2>
            <a href="disponibilite.php?filtre=mission" class="lien petit">Tout voir →</a>
        </div>
        <?php foreach ($enCours as $c): ?>
            <div class="trajet <?= $c['retard'] ? 'trajet-retard' : '' ?>">
                <div class="trajet-tete">
                    <b><?= e($c['immatriculation']) ?></b>
                    <span class="trajet-dest"><?= e($c['destination'] ?? '?') ?>
                        <a href="bon_mission.php?id=<?= $c['mission_id'] ?>" class="lien petit ne-pas-imprimer">Bon de mission</a></span>
                </div>
                <div class="trajet-info petit">
                    Chauffeur : <?= e($c['chauffeur']) ?>
                    <?php if ($c['chauffeur_tel']): ?>
                        · <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['chauffeur_tel'])) ?>" class="lien"><?= e($c['chauffeur_tel']) ?></a>
                    <?php endif; ?>
                    <?php if ($c['remorque']): ?> · Remorque <?= e($c['remorque']) ?><?php endif; ?>
                </div>
                <?php if ($c['progression'] !== null): ?>
                    <div class="trajet-barre"><div style="width:<?= $c['progression'] ?>%"></div></div>
                <?php endif; ?>
                <div class="trajet-dates petit">
                    <span>Parti le <?= date('d/m H:i', strtotime($c['depart'])) ?></span>
                    <span><?= $c['retour_prevu'] ? ($c['retard'] ? '<b class="negatif">En retard · ' : 'Retour ') . date('d/m', strtotime($c['retour_prevu'])) . ($c['retard'] ? '</b>' : '') : 'Retour non prévu' ?></span>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$enCours): ?>
            <div class="tout-va-bien">
                <b>Aucun camion sur la route</b>
                <p class="petit"><a href="disponibilite.php#m-camion" class="lien">Lancer une mission</a></p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Classement -->
    <section class="panneau">
        <div class="panneau-tete">
            <h2>Meilleurs camions du mois</h2>
            <a href="rapports.php?periode=mois" class="lien petit">Rapport →</a>
        </div>
        <?php $rang = 0; foreach ($classement as $immat => $t): ?>
            <div class="podium">
                <span class="podium-rang"><?= $rang + 1 ?></span>
                <div class="podium-corps">
                    <div class="podium-tete">
                        <b><?= e($immat) ?></b>
                        <b class="<?= classe_benefice($t['benefice']) ?>"><?= montant($t['benefice'], $devise) ?></b>
                    </div>
                    <div class="podium-barre"><div class="<?= classe_benefice($t['benefice']) ?>" style="width:<?= round(abs($t['benefice']) / $maxBenefice * 100) ?>%"></div></div>
                    <div class="petit"><?= $t['missions'] ?> mission(s) · <?= number_format($t['km'], 0, ',', ' ') ?> km · <?= litres($t['litres']) ?></div>
                </div>
            </div>
        <?php $rang++; endforeach; ?>
        <?php if (!$classement): ?>
            <div class="tout-va-bien">
                <b>Aucune mission ce mois-ci</b>
            </div>
        <?php endif; ?>
    </section>
</div>

</div>

<?php require __DIR__ . '/includes/pied.php'; ?>
