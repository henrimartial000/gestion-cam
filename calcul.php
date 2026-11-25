<?php
// Renvoie en JSON le carburant nécessaire pour une destination (le camion est facultatif).
// Carburant = distance × taux (chargé ou à vide), calculé séparément pour l'aller et le retour.
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['erreur' => 'Non connecté']);
    exit;
}

$camionId      = (int) ($_GET['camion_id'] ?? 0);
$destinationId = (int) ($_GET['destination_id'] ?? 0);
$aller         = ($_GET['aller'] ?? 'charge') === 'vide' ? 'vide' : 'charge';
$retour        = in_array($_GET['retour'] ?? '', ['charge', 'vide'], true) ? $_GET['retour'] : 'aucun';
$marge         = max(0, min(100, (float) ($_GET['marge'] ?? 0)));

$camion = null;
foreach (camions_avec_statut() as $c) {
    if ((int) $c['id'] === $camionId) $camion = $c;
}

$stmt = db()->prepare('SELECT * FROM destinations WHERE id = ?');
$stmt->execute([$destinationId]);
$destination = $stmt->fetch();

if (!$destination) {
    http_response_code(400);
    echo json_encode(['erreur' => 'Destination invalide']);
    exit;
}

$params    = parametres();
$calcul    = calcul_carburant((int) $destination['distance_km'], $aller, $retour);
$carburant = $calcul['litres'];
$avecMarge = $carburant * (1 + $marge / 100);
$cout      = $avecMarge * $params['prix_litre'];

$reponse = [
    'destination_id' => $destinationId,
    'destination'    => $destination['nom'],
    'trajets'        => $calcul['trajets'],
    'aller'          => $aller,
    'retour'         => $retour,
    'distance_km'    => $calcul['km'],
    'carburant_l'    => round($carburant, 1),
    'marge_pct'      => $marge,
    'total_l'        => round($avecMarge, 1),
    'cout_valeur'    => round($cout),
    'cout'           => montant($cout, $params['devise']),
    'prix_litre'     => $params['prix_litre'],
    'devise'         => $params['devise'],
    'camion_id'      => null,
];

// Infos du camion seulement s'il est choisi
if ($camion) {
    $reservoir = (int) $camion['reservoir'];
    $reponse += [
        'statut'         => $camion['statut'],
        'statut_libelle' => LIBELLES_STATUT[$camion['statut']],
        'remorque'       => $camion['remorque'] ? $camion['remorque'] . ' (' . $camion['remorque_type'] . ')' : null,
        'camion'         => $camion['immatriculation'] . ' – ' . $camion['modele'],
        'reservoir_l'    => $reservoir,
        'pct_reservoir'  => round($avecMarge / $reservoir * 100),
        'nb_pleins'      => (int) ceil($avecMarge / $reservoir),
    ];
    $reponse['camion_id'] = $camionId;
}

echo json_encode($reponse);
