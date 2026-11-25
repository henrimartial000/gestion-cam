<?php
// Script d'installation : crée la base, les tables et des données d'exemple.
// À lancer une seule fois : http://localhost/flotte/install.php
// Chez un hébergeur : install.php?cle=... avec la clé INSTALL_CLE de config.local.php
require_once __DIR__ . '/config.php';

// Installation possible depuis l'ordinateur du serveur, ou à distance avec la clé d'installation
$cleValide = INSTALL_CLE !== '' && hash_equals(INSTALL_CLE, (string) ($_GET['cle'] ?? ''));
if (!est_sur_serveur() && !$cleValide) {
    http_response_code(403);
    exit('Installation autorisée uniquement depuis l\'ordinateur du serveur.');
}

// Ajoute une colonne si elle n'existe pas encore (pour mettre à jour une base déjà installée)
function ajouter_colonne(PDO $pdo, string $table, string $colonne, string $definition): void
{
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = '$table' AND COLUMN_NAME = '$colonne'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE $table ADD $colonne $definition");
    }
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Chez un hébergeur, la base est créée depuis son panneau et l'utilisateur n'a pas le droit
    // de créer ou modifier une base : on utilise alors simplement celle qui existe
    try {
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8 COLLATE utf8_general_ci');
        $pdo->exec('ALTER DATABASE `' . DB_NAME . '` CHARACTER SET utf8 COLLATE utf8_general_ci');
    } catch (PDOException $ex) {
        // pas de droit sur la base elle-même : sans conséquence si elle existe (USE ci-dessous)
    }
    $pdo->exec('USE `' . DB_NAME . '`');
    $pdo->exec("SET time_zone = '" . date('P') . "'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifiant VARCHAR(50) NOT NULL UNIQUE,
        mot_de_passe VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS camions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        immatriculation VARCHAR(20) NOT NULL UNIQUE,
        modele VARCHAR(100) NOT NULL,
        consommation DECIMAL(5,2) NOT NULL COMMENT 'Litres aux 100 km',
        reservoir INT NOT NULL COMMENT 'Capacité du réservoir en litres'
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS destinations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL UNIQUE,
        distance_km INT NOT NULL COMMENT 'Distance depuis le dépôt'
    ) ENGINE=InnoDB");

    // Une mission ou une maintenance est "en cours" tant que terminee_le est NULL
    $pdo->exec("CREATE TABLE IF NOT EXISTS missions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        camion_id INT NOT NULL,
        destination_id INT NULL,
        chauffeur VARCHAR(100) NOT NULL,
        depart DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        retour_prevu DATE NULL,
        terminee_le DATETIME NULL,
        FOREIGN KEY (camion_id) REFERENCES camions(id) ON DELETE CASCADE,
        FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS maintenances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        camion_id INT NOT NULL,
        motif VARCHAR(255) NOT NULL,
        debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fin_prevue DATE NULL,
        terminee_le DATETIME NULL,
        FOREIGN KEY (camion_id) REFERENCES camions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Semi-remorques : camion_id = tracteur auquel elle est attelée (NULL = au dépôt)
    $pdo->exec("CREATE TABLE IF NOT EXISTS remorques (
        id INT AUTO_INCREMENT PRIMARY KEY,
        immatriculation VARCHAR(20) NOT NULL UNIQUE,
        type VARCHAR(50) NOT NULL,
        charge_utile DECIMAL(5,1) NULL COMMENT 'En tonnes (facultative pour un plateau)',
        camion_id INT NULL UNIQUE,
        FOREIGN KEY (camion_id) REFERENCES camions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");

    // Charge utile facultative (plateau) : la colonne accepte une valeur vide
    $pdo->exec("ALTER TABLE remorques MODIFY charge_utile DECIMAL(5,1) NULL COMMENT 'En tonnes (facultative pour un plateau)'");

    // Ajoute la remorque utilisée à chaque mission (pour les bases déjà installées)
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'missions' AND COLUMN_NAME = 'remorque_id'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE missions ADD remorque_id INT NULL AFTER camion_id,
            ADD FOREIGN KEY (remorque_id) REFERENCES remorques(id) ON DELETE SET NULL");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS chauffeurs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL,
        prenom VARCHAR(100) NOT NULL,
        telephone VARCHAR(30) NOT NULL UNIQUE,
        telephone2 VARCHAR(30) NULL,
        email VARCHAR(150) NULL,
        ville VARCHAR(100) NOT NULL COMMENT 'Lieu d''habitation',
        adresse VARCHAR(255) NULL COMMENT 'Quartier, rue, repère',
        numero_permis VARCHAR(50) NULL
    ) ENGINE=InnoDB");

    // Relie chaque mission à un chauffeur de la liste (la colonne texte "chauffeur" garde le nom)
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'missions' AND COLUMN_NAME = 'chauffeur_id'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE missions ADD chauffeur_id INT NULL AFTER remorque_id,
            ADD FOREIGN KEY (chauffeur_id) REFERENCES chauffeurs(id) ON DELETE SET NULL");
    }

    // Réglages généraux : taux de consommation communs à tous les camions (litres par km)
    $pdo->exec("CREATE TABLE IF NOT EXISTS parametres (
        cle VARCHAR(50) PRIMARY KEY,
        valeur VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT IGNORE INTO parametres (cle, valeur) VALUES
        ('taux_charge', '0.5'), ('taux_vide', '0.4'), ('prix_litre', '725'), ('devise', 'FCFA')");

    // Chiffres de chaque mission, pour les rapports de consommation et de bénéfice.
    // Le prix du litre est copié au départ : un changement de prix ne modifie pas les missions passées.
    $colonnesMission = [
        'aller_etat'  => "VARCHAR(10) NOT NULL DEFAULT 'charge'",
        'retour_etat' => "VARCHAR(10) NOT NULL DEFAULT 'vide'",
        'distance_km' => 'INT NULL',
        'carburant_l' => 'DECIMAL(8,1) NULL',
        'prix_litre'  => 'DECIMAL(10,2) NULL',
        'montant'     => 'DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT \'Prix facturé au client\'',
        'frais'       => 'DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT \'Péages, frais de route, etc.\'',
    ];
    foreach ($colonnesMission as $colonne => $definition) {
        $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'missions' AND COLUMN_NAME = '$colonne'")->fetchColumn();
        if (!$existe) {
            $pdo->exec("ALTER TABLE missions ADD $colonne $definition");
        }
    }

    // La consommation n'est plus propre à chaque camion : la colonne devient facultative
    $pdo->exec("ALTER TABLE camions MODIFY consommation DECIMAL(5,2) NULL DEFAULT NULL");

    // Code de récupération (haché) pour "Mot de passe oublié"
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'utilisateurs' AND COLUMN_NAME = 'code_recuperation'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE utilisateurs ADD code_recuperation VARCHAR(255) NULL");
    }

    // Comptes par personne : rôle, nom affiché, activation
    ajouter_colonne($pdo, 'utilisateurs', 'nom_complet', 'VARCHAR(100) NULL AFTER identifiant');
    ajouter_colonne($pdo, 'utilisateurs', 'role', "VARCHAR(20) NOT NULL DEFAULT 'admin'");
    ajouter_colonne($pdo, 'utilisateurs', 'actif', 'TINYINT(1) NOT NULL DEFAULT 1');
    ajouter_colonne($pdo, 'utilisateurs', 'derniere_connexion', 'DATETIME NULL');

    // Traçabilité : qui a créé / terminé chaque mission et maintenance
    foreach (['missions', 'maintenances'] as $table) {
        ajouter_colonne($pdo, $table, 'cree_par', 'VARCHAR(50) NULL');
        ajouter_colonne($pdo, $table, 'terminee_par', 'VARCHAR(50) NULL');
    }

    // Essais de connexion ratés, pour bloquer les tentatives répétées (voir index.php)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tentatives_connexion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        identifiant VARCHAR(50) NOT NULL,
        moment DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (ip, moment)
    ) ENGINE=InnoDB");

    // Dossiers (un par connaissement BL) et leurs conteneurs à livrer.
    // Un conteneur est "à livrer" tant que livre_le est NULL ; zone = clé de ZONES (config.php)
    $pdo->exec("CREATE TABLE IF NOT EXISTS dossiers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero_bl VARCHAR(11) NOT NULL UNIQUE COMMENT 'Format 123-456-789',
        client VARCHAR(150) NOT NULL,
        transitaire VARCHAR(150) NOT NULL
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS conteneurs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dossier_id INT NOT NULL,
        numero CHAR(11) NOT NULL COMMENT '4 lettres + 7 chiffres',
        plomb VARCHAR(30) NULL,
        date_livraison DATE NOT NULL COMMENT 'DO : date de livraison prévue',
        zone TINYINT NOT NULL,
        livre_le DATETIME NULL,
        cree_par VARCHAR(50) NULL,
        livre_par VARCHAR(50) NULL,
        UNIQUE (dossier_id, numero),
        FOREIGN KEY (dossier_id) REFERENCES dossiers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Prix du conteneur : copié depuis la grille à l'enregistrement, comme le prix du litre des missions
    $pdo->exec("ALTER TABLE conteneurs MODIFY zone TINYINT NULL COMMENT 'NULL hors zone'");
    ajouter_colonne($pdo, 'conteneurs', 'taille', "CHAR(2) NOT NULL DEFAULT '20' AFTER numero");
    ajouter_colonne($pdo, 'conteneurs', 'operation', "VARCHAR(10) NOT NULL DEFAULT 'import' AFTER taille");
    ajouter_colonne($pdo, 'conteneurs', 'materiel', "VARCHAR(15) NULL DEFAULT 'remorque' AFTER operation");
    // Le scanner n'est plus un matériel mais une option qui s'ajoute à la remorque ou à l'auto-chargeuse
    ajouter_colonne($pdo, 'conteneurs', 'scanner', "TINYINT(1) NOT NULL DEFAULT 0 AFTER materiel");
    $pdo->exec("UPDATE conteneurs SET materiel = 'remorque', scanner = 1 WHERE materiel = 'scanner'");
    // L'auto-chargeuse s'appelle désormais AC (type de remorque enregistré en base)
    $pdo->exec("UPDATE remorques SET type = 'AC' WHERE type = 'Auto-chargeuse'");
    ajouter_colonne($pdo, 'conteneurs', 'prix', 'DECIMAL(12,2) NULL');
    ajouter_colonne($pdo, 'conteneurs', 'commission_pct', 'DECIMAL(5,2) NULL COMMENT \'% reversé au client\'');

    // La grille des zones est fixe : elle est dans config.php (GRILLE_ZONES), plus en base
    $pdo->exec('DROP TABLE IF EXISTS tarifs_conteneurs');
    $pdo->exec("INSERT IGNORE INTO parametres (cle, valeur) VALUES ('commission_conteneur', '0')");

    // Communes de livraison : chacune appartient à une zone, qui fixe le prix du conteneur
    $pdo->exec("CREATE TABLE IF NOT EXISTS communes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL UNIQUE,
        zone TINYINT NOT NULL
    ) ENGINE=InnoDB");
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'conteneurs' AND COLUMN_NAME = 'commune_id'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE conteneurs ADD commune_id INT NULL AFTER zone,
            ADD FOREIGN KEY (commune_id) REFERENCES communes(id) ON DELETE SET NULL");
    }

    // Destinations hors zone des conteneurs (liste distincte des destinations des missions) :
    // prix en semi-remorque standard ; l'auto-chargeuse = ce prix × coef_autochargeuse_hors_zone
    $pdo->exec("CREATE TABLE IF NOT EXISTS destinations_hors_zone (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom VARCHAR(100) NOT NULL UNIQUE,
        distance_km INT NULL COMMENT 'Depuis Abidjan',
        prix_remorque_20 DECIMAL(12,2) NULL,
        prix_remorque_40 DECIMAL(12,2) NULL
    ) ENGINE=InnoDB");
    $pdo->exec("ALTER TABLE destinations_hors_zone MODIFY distance_km INT NULL COMMENT 'Depuis Abidjan'");
    foreach (['prix_autochargeuse_20', 'prix_autochargeuse_40'] as $colonne) {
        $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'destinations_hors_zone' AND COLUMN_NAME = '$colonne'")->fetchColumn();
        if ($existe) $pdo->exec("ALTER TABLE destinations_hors_zone DROP $colonne");
    }
    $pdo->exec("INSERT IGNORE INTO parametres (cle, valeur) VALUES ('coef_autochargeuse_hors_zone', '1.5')");

    // Tracteur et semi-remorque affectés au conteneur : occupés jusqu'à sa livraison
    foreach (['camion_id' => 'camions', 'remorque_id' => 'remorques'] as $colonne => $table) {
        $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'conteneurs' AND COLUMN_NAME = '$colonne'")->fetchColumn();
        if (!$existe) {
            $pdo->exec("ALTER TABLE conteneurs ADD $colonne INT NULL,
                ADD FOREIGN KEY ($colonne) REFERENCES $table(id) ON DELETE SET NULL");
        }
    }
    // Suivi du conteneur : dates facultatives remplies au fil de l'opération, chauffeur et lieu de restitution
    ajouter_colonne($pdo, 'dossiers', 'date_reception', "DATE NULL COMMENT 'Réception du dossier'");
    ajouter_colonne($pdo, 'conteneurs', 'date_recuperation', "DATE NULL COMMENT 'Récupération du conteneur (port)'");
    ajouter_colonne($pdo, 'conteneurs', 'date_recuperation_client', "DATE NULL COMMENT 'Récupération chez le client'");
    ajouter_colonne($pdo, 'conteneurs', 'date_restitution', 'DATE NULL');
    ajouter_colonne($pdo, 'conteneurs', 'lieu_restitution', 'VARCHAR(150) NULL');
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'conteneurs' AND COLUMN_NAME = 'chauffeur_id'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE conteneurs ADD chauffeur_id INT NULL,
            ADD FOREIGN KEY (chauffeur_id) REFERENCES chauffeurs(id) ON DELETE SET NULL");
    }
    // Grille APMT (annexe du contrat) — INSERT IGNORE garde les destinations déjà modifiées
    $stmt = $pdo->prepare('INSERT IGNORE INTO destinations_hors_zone (nom, distance_km, prix_remorque_20, prix_remorque_40) VALUES (?, ?, ?, ?)');
    foreach (require __DIR__ . '/includes/destinations_hors_zone_apmt.php' as $d) {
        $stmt->execute($d);
    }
    $existe = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'conteneurs' AND COLUMN_NAME = 'destination_id'")->fetchColumn();
    if (!$existe) {
        $pdo->exec("ALTER TABLE conteneurs ADD destination_id INT NULL AFTER commune_id,
            ADD FOREIGN KEY (destination_id) REFERENCES destinations_hors_zone(id) ON DELETE SET NULL");
    }

    // Convertit aussi les tables déjà existantes en utf8_general_ci
    foreach (['utilisateurs', 'camions', 'destinations', 'missions', 'maintenances', 'remorques', 'chauffeurs', 'parametres', 'tentatives_connexion', 'dossiers', 'conteneurs', 'communes', 'destinations_hors_zone'] as $table) {
        $pdo->exec("ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci");
    }

    // Compte administrateur par défaut, seulement à la toute première installation
    // (relancer install.php ne recrée pas un compte admin/admin123 supprimé ou renommé)
    $premiereInstallation = !$pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
    if ($premiereInstallation) {
        $stmt = $pdo->prepare("INSERT INTO utilisateurs (identifiant, mot_de_passe, role) VALUES (?, ?, 'admin')");
        $stmt->execute(['admin', password_hash(MDP_DEFAUT, PASSWORD_DEFAULT)]);
    }

    $camions = [
        ['AB-123-CD', 'Renault T 480', 32.0, 400],
        ['EF-456-GH', 'Volvo FH 500', 30.5, 600],
        ['IJ-789-KL', 'Mercedes Actros', 29.0, 500],
        ['MN-012-OP', 'Iveco Daily (utilitaire)', 12.5, 90],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO camions (immatriculation, modele, consommation, reservoir) VALUES (?, ?, ?, ?)');
    foreach ($camions as $c) {
        $stmt->execute($c);
    }

    // Exemples : distances depuis un dépôt situé à Abidjan (à adapter)
    $destinations = [
        ['Grand-Bassam', 40],
        ['Yamoussoukro', 240],
        ['Bouaké', 350],
        ['San-Pédro', 340],
        ['Korhogo', 630],
        ['Bamako', 1150],
        ['Ouagadougou', 1200],
        ['Accra', 560],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO destinations (nom, distance_km) VALUES (?, ?)');
    // Distances LHCI (DISTANCE LH.XLSX) en premier, pour qu'elles priment sur les exemples ;
    // INSERT IGNORE garde les destinations déjà saisies
    foreach (require __DIR__ . '/includes/destinations_lh.php' as $d) {
        $stmt->execute($d);
    }
    foreach ($destinations as $d) {
        $stmt->execute($d);
    }

    // Remorques d'exemple, attelées aux trois premiers camions d'exemple
    $remorques = [
        ['RA-111-AA', 'Plateau', 25.0, 'AB-123-CD'],
        ['RB-222-BB', 'Citerne', 30.0, 'EF-456-GH'],
        ['RC-333-CC', 'Frigorifique', 22.0, 'IJ-789-KL'],
        ['RD-444-DD', 'Benne', 28.0, null],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO remorques (immatriculation, type, charge_utile, camion_id)
        VALUES (?, ?, ?, (SELECT id FROM camions WHERE immatriculation = ?))');
    foreach ($remorques as $r) {
        $stmt->execute($r);
    }

    $chauffeurs = [
        ['Kouassi', 'Jean', '+225 07 00 00 00 01', 'Abidjan', 'Yopougon, Selmer'],
        ['Traoré', 'Moussa', '+225 05 00 00 00 02', 'Abidjan', 'Abobo, Gare'],
        ['Koné', 'Awa', '+225 01 00 00 00 03', 'Bouaké', 'Quartier Commerce'],
    ];
    $stmt = $pdo->prepare('INSERT IGNORE INTO chauffeurs (nom, prenom, telephone, ville, adresse) VALUES (?, ?, ?, ?, ?)');
    foreach ($chauffeurs as $ch) {
        $stmt->execute($ch);
    }

    $message = $premiereInstallation
        ? 'Installation terminée. Identifiant : <b>admin</b> / mot de passe : <b>' . MDP_DEFAUT . '</b> (à changer dès la première connexion)'
        : 'Mise à jour de la base terminée. Les comptes existants n\'ont pas été modifiés.';
    $ok = true;
} catch (PDOException $ex) {
    $message = 'Erreur : ' . e($ex->getMessage());
    $ok = false;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Installation</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="page-connexion">
    <div class="carte">
        <h1>Installation</h1>
        <p class="<?= $ok ? 'succes' : 'erreur' ?>"><?= $message ?></p>
        <?php if ($ok): ?><a class="btn" href="index.php">Aller à la connexion</a><?php endif; ?>
    </div>
</body>
</html>
