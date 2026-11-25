<?php
// Modèle de réglages pour un serveur en ligne.
// 1. Copiez ce fichier sous le nom config.local.php (dans le même dossier que config.php).
// 2. Remplacez les valeurs par celles données par votre hébergeur (panneau > Bases de données MySQL).
// config.local.php ne doit pas être recopié d'un serveur à l'autre : chaque serveur garde le sien.

define('DB_HOST', 'localhost');          // souvent "localhost", parfois une adresse fournie par l'hébergeur
define('DB_NAME', 'nom_de_la_base');     // ex. u123456789_flotte
define('DB_USER', 'utilisateur_mysql');  // ex. u123456789_flotte
define('DB_PASS', 'mot_de_passe_mysql');

// Pour lancer install.php à distance : https://votre-site/install.php?cle=VOTRE_CLE
// Mettez une longue suite de caractères, puis videz-la ('') une fois l'installation terminée.
define('INSTALL_CLE', '');

// Fuseau horaire de l'entreprise (Côte d'Ivoire)
define('FUSEAU', 'Africa/Abidjan');
