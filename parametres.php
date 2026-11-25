<?php
require_once __DIR__ . '/config.php';
exiger_connexion();

$erreurs = [];
$taux    = taux_consommation();
$params  = parametres();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !est_admin()) {
    flash('Seul un administrateur peut modifier les réglages.', 'erreur');
    header('Location: parametres.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $saisie = [
        'charge' => str_replace(',', '.', trim($_POST['taux_charge'] ?? '')),
        'vide'   => str_replace(',', '.', trim($_POST['taux_vide'] ?? '')),
    ];
    $prix   = str_replace([',', ' '], ['.', ''], trim($_POST['prix_litre'] ?? ''));
    $devise = trim($_POST['devise'] ?? '');

    foreach (['charge' => 'chargé', 'vide' => 'à vide'] as $cle => $libelle) {
        if (!is_numeric($saisie[$cle]) || $saisie[$cle] <= 0 || $saisie[$cle] > 5) {
            $erreurs[] = "Le taux $libelle doit être un nombre entre 0 et 5 litres par km.";
        }
    }
    if (!is_numeric($prix) || $prix <= 0) $erreurs[] = 'Le prix du litre doit être un nombre supérieur à 0.';
    if ($devise === '' || mb_strlen($devise, 'UTF-8') > 10) $erreurs[] = 'La devise est obligatoire (10 caractères maximum).';

    if (!$erreurs) {
        $stmt = db()->prepare('REPLACE INTO parametres (cle, valeur) VALUES (?, ?)');
        $stmt->execute(['taux_charge', $saisie['charge']]);
        $stmt->execute(['taux_vide', $saisie['vide']]);
        $stmt->execute(['prix_litre', $prix]);
        $stmt->execute(['devise', $devise]);
        flash('Réglages enregistrés.');
        header('Location: parametres.php');
        exit;
    }
    $taux   = $saisie;
    $params = ['prix_litre' => $prix, 'devise' => $devise];
}

$titre = 'Réglages';
$imprimable = false;
$page  = 'parametres';
require __DIR__ . '/includes/entete.php';
?>

<section class="panneau panneau-etroit">
    <h2>Réglages du carburant</h2>

    <?php foreach ($erreurs as $err): ?>
        <p class="erreur"><?= e($err) ?></p>
    <?php endforeach; ?>

    <?php if (!est_admin()): ?>
        <p class="alerte">Seul un administrateur peut modifier ces réglages.</p>
    <?php endif; ?>
    <form method="post"><fieldset class="sans-cadre" <?= est_admin() ? '' : 'disabled' ?>>
        <?= csrf_champ() ?>
        <h3 class="sous-section">Consommation (tous les camions)</h3>
        <p class="petit">Carburant nécessaire = distance (km) × taux.</p>

        <label for="taux_charge">Camion chargé (litres par km)</label>
        <input type="number" id="taux_charge" name="taux_charge" required step="0.01" min="0.01" max="5"
               value="<?= e((string) $taux['charge']) ?>">

        <label for="taux_vide">Camion à vide (litres par km)</label>
        <input type="number" id="taux_vide" name="taux_vide" required step="0.01" min="0.01" max="5"
               value="<?= e((string) $taux['vide']) ?>">

        <h3 class="sous-section">Prix</h3>
        <label for="prix_litre">Prix du litre de carburant</label>
        <input type="number" id="prix_litre" name="prix_litre" required step="0.01" min="0.01"
               value="<?= e((string) $params['prix_litre']) ?>">
        <p class="petit">Le prix est enregistré dans chaque mission à son départ : le changer ne modifie pas les missions passées.</p>

        <label for="devise">Devise</label>
        <input type="text" id="devise" name="devise" required maxlength="10" value="<?= e($params['devise']) ?>">

        <button type="submit" class="btn">Enregistrer</button>
    </fieldset></form>

    <?php if (!$erreurs): ?>
        <ul class="details" style="margin-top:20px">
            <li><b>Chargé :</b> 100 km × <?= $taux['charge'] ?> = <?= 100 * $taux['charge'] ?> L
                = <?= montant(100 * $taux['charge'] * $params['prix_litre'], $params['devise']) ?></li>
            <li><b>À vide :</b> 100 km × <?= $taux['vide'] ?> = <?= 100 * $taux['vide'] ?> L
                = <?= montant(100 * $taux['vide'] * $params['prix_litre'], $params['devise']) ?></li>
        </ul>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/pied.php'; ?>
