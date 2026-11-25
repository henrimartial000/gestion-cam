<?php
// Fonctions de statistiques partagées par le tableau de bord et les rapports

const MOIS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet',
              'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const JOURS = ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];

// Début (inclus) et fin (exclue) de la période contenant $date
function bornes(string $periode, DateTime $date): array
{
    $debut = clone $date;
    $debut->setTime(0, 0);
    if ($periode === 'semaine') {
        $debut->modify('monday this week');
        $fin = (clone $debut)->modify('+7 days');
    } elseif ($periode === 'mois') {
        $debut->modify('first day of this month');
        $fin = (clone $debut)->modify('+1 month');
    } else {
        $fin = (clone $debut)->modify('+1 day');
    }
    return [$debut, $fin];
}

function missions_entre(DateTime $debut, DateTime $fin): array
{
    $stmt = db()->prepare(
        'SELECT m.*, c.immatriculation, d.nom AS destination
         FROM missions m
         JOIN camions c ON c.id = m.camion_id
         LEFT JOIN destinations d ON d.id = m.destination_id
         WHERE m.depart >= ? AND m.depart < ?
         ORDER BY m.depart'
    );
    $stmt->execute([$debut->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')]);

    $prixActuel = parametres()['prix_litre'];
    $missions = [];
    foreach ($stmt->fetchAll() as $m) {
        $m['litres']   = (float) $m['carburant_l'];
        $m['cout']     = $m['litres'] * (float) ($m['prix_litre'] ?? $prixActuel);
        $m['montant']  = (float) $m['montant'];
        $m['frais']    = (float) $m['frais'];
        $m['benefice'] = $m['montant'] - $m['cout'] - $m['frais'];
        $missions[] = $m;
    }
    return $missions;
}

function totaux(array $missions): array
{
    $t = ['missions' => count($missions), 'km' => 0, 'litres' => 0, 'cout' => 0, 'montant' => 0, 'frais' => 0, 'benefice' => 0];
    foreach ($missions as $m) {
        $t['km']       += (int) $m['distance_km'];
        $t['litres']   += $m['litres'];
        $t['cout']     += $m['cout'];
        $t['montant']  += $m['montant'];
        $t['frais']    += $m['frais'];
        $t['benefice'] += $m['benefice'];
    }
    return $t;
}

function classe_benefice(float $b): string
{
    return $b < 0 ? 'negatif' : 'positif';
}
