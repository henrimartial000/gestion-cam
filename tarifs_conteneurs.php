<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs    = [];
$devise     = parametres()['devise'];
$commission = commission_conteneur();
$coef       = coef_autochargeuse_hors_zone();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !est_admin()) {
    flash('Seul un administrateur peut modifier ces réglages.', 'erreur');
    header('Location: tarifs_conteneurs.php');
    exit;
}

// La grille des zones est fixe (GRILLE_ZONES dans config.php) : seuls la commission
// et le coefficient auto-chargeuse hors zone se règlent ici
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $commissionSaisie = str_replace(',', '.', trim($_POST['commission'] ?? ''));
    if (!is_numeric($commissionSaisie) || $commissionSaisie < 0 || $commissionSaisie >= 100) {
        $erreurs[] = 'La commission doit être un pourcentage entre 0 et 99.';
    }
    $coefSaisi = str_replace(',', '.', trim($_POST['coef_autochargeuse'] ?? ''));
    if (!is_numeric($coefSaisi) || $coefSaisi <= 0 || $coefSaisi > 10) {
        $erreurs[] = 'Le coefficient AC doit être un nombre entre 0 et 10.';
    }

    if (!$erreurs) {
        $stmt = db()->prepare('REPLACE INTO parametres (cle, valeur) VALUES (?, ?)');
        $stmt->execute(['commission_conteneur', $commissionSaisie]);
        $stmt->execute(['coef_autochargeuse_hors_zone', $coefSaisi]);
        flash('Réglages enregistrés.');
        header('Location: tarifs_conteneurs.php');
        exit;
    }
    $commission = $commissionSaisie;
    $coef       = $coefSaisi;
}

$titre = 'Tarifs conteneurs';
$page  = 'tarifs_conteneurs';
require __DIR__ . '/includes/entete.php';
?>

<section class="panneau ne-pas-imprimer">
    <h2>Réglages</h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>
    <?php if (!est_admin()): ?>
        <p class="alerte">Seul un administrateur peut modifier ces réglages.</p>
    <?php endif; ?>

    <form method="post"><fieldset class="sans-cadre grille-form" <?= est_admin() ? '' : 'disabled' ?>>
        <?= csrf_champ() ?>
        <div>
            <label for="commission">Commission reversée au client (%)</label>
            <input type="number" id="commission" name="commission" required min="0" max="99" step="0.01"
                   value="<?= e((string) $commission) ?>">
        </div>
        <div>
            <label for="coef_autochargeuse">Hors zone : AC = semi-remorque ×</label>
            <input type="number" id="coef_autochargeuse" name="coef_autochargeuse" required min="0.01" max="10" step="0.01"
                   value="<?= e((string) $coef) ?>">
        </div>
        <div class="actions-form">
            <button type="submit" class="btn btn-auto">Enregistrer</button>
        </div>
    </fieldset></form>
    <p class="petit">La commission est déduite du prix de chaque prestation au moment de la facture.
        Prix et commission sont enregistrés dans chaque conteneur : les changer ici ne modifie pas les conteneurs déjà saisis.</p>
</section>

<?php foreach (GRILLE_ZONES as $operation => $parMateriel): ?>
    <section class="panneau">
        <h2><?= OPERATIONS_CONTENEUR[$operation] ?></h2>
        <div class="grille-tarifs">
            <?php foreach (array_intersect_key(MATERIELS_CONTENEUR, $parMateriel) as $materiel => $nomMateriel): ?>
                <table class="table-tarifs">
                    <thead>
                        <tr><th><?= e($nomMateriel) ?></th>
                            <?php foreach (TAILLES_CONTENEUR as $nomTaille): ?><th><?= $nomTaille ?></th><?php endforeach; ?></tr>
                    </thead>
                    <tbody>
                        <?php foreach (ZONES as $zone => $nomZone): ?>
                            <tr>
                                <td><?= e($nomZone) ?></td>
                                <?php foreach (array_keys(array_values(TAILLES_CONTENEUR)) as $i): ?>
                                    <td><?= isset($parMateriel[$materiel][$zone]) ? montant($parMateriel[$materiel][$zone][$i], $devise) : '—' ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<section class="panneau">
    <h2>Option scanner</h2>
    <p>S'ajoute au prix de la remorque ou de l'AC, en zone comme hors zone :
        <?php foreach (array_values(TAILLES_CONTENEUR) as $i => $nomTaille): ?>
            <?= $i ? ' · ' : '' ?><b>+ <?= montant(SUPPLEMENT_SCANNER[$i], $devise) ?></b> / <?= $nomTaille ?>
        <?php endforeach; ?>
    </p>
</section>

<p class="petit">Grille de l'annexe 2 du contrat APMT : ces montants sont fixes. « — » : prestation non proposée.</p>

<?php require __DIR__ . '/includes/pied.php'; ?>
