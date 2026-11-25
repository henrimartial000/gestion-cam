<?php
// Réglages propres à chaque serveur (base de données de l'hébergeur…) : dans config.local.php,
// à créer à partir de config.local.exemple.php. Sans ce fichier, valeurs par défaut de WampServer.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
defined('DB_HOST')     || define('DB_HOST', 'localhost');
defined('DB_NAME')     || define('DB_NAME', 'flotte_camions');
defined('DB_USER')     || define('DB_USER', 'root');
defined('DB_PASS')     || define('DB_PASS', '');
// Clé pour lancer install.php à distance (chez un hébergeur) : install.php?cle=... ; vide = ordinateur du serveur seulement
defined('INSTALL_CLE') || define('INSTALL_CLE', '');
// Heure de la Côte d'Ivoire, quel que soit le pays du serveur
defined('FUSEAU')      || define('FUSEAU', 'Africa/Abidjan');

date_default_timezone_set(FUSEAU);

if (session_status() === PHP_SESSION_NONE) {
    // Cookie de session protégé : illisible en JavaScript, non envoyé depuis un autre site,
    // et réservé au HTTPS quand le site est servi en HTTPS (chez l'hébergeur)
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        // NOW() de MySQL à la même heure que date() de PHP (décalage du fuseau, ex. +00:00)
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

const ROLES = [
    'admin'        => 'Administrateur',
    'exploitation' => 'Exploitation',
    'consultation' => 'Consultation',
];

// Mot de passe du compte admin créé par install.php
const MDP_DEFAUT = 'admin123';

// Vrai seulement si la requête vient de l'ordinateur du serveur lui-même.
// Derrière un tunnel (ngrok…) ou un proxy, l'adresse vue est aussi 127.0.0.1 : ces en-têtes trahissent
// alors un visiteur venu d'Internet, qui ne doit pas profiter des droits "sur le serveur".
function est_sur_serveur(): bool
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_VIA'] as $entete) {
        if (!empty($_SERVER[$entete])) return false;
    }
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

// Pages où un compte "consultation" peut quand même envoyer un formulaire
const PAGES_LIBRES = ['compte.php'];

// Redirige vers la page de connexion si l'utilisateur n'est pas connecté.
// Le rôle est relu à chaque page : un compte désactivé ou modifié prend effet tout de suite.
function exiger_connexion(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }

    $stmt = db()->prepare('SELECT identifiant, nom_complet, role, actif FROM utilisateurs WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch();
    if (!$u || !$u['actif']) {
        $_SESSION = [];
        session_destroy();
        header('Location: index.php');
        exit;
    }
    $_SESSION['identifiant'] = $u['identifiant'];
    $_SESSION['nom']         = $u['nom_complet'] ?: $u['identifiant'];
    $_SESSION['role']        = $u['role'];

    // Lecture seule : tout formulaire envoyé est refusé, sauf sur les pages libres
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !peut_modifier()
        && !in_array(basename($_SERVER['SCRIPT_NAME']), PAGES_LIBRES, true)) {
        flash('Votre compte est en consultation seule : vous ne pouvez pas modifier les données.', 'erreur');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

function est_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'admin';
}

function peut_modifier(): bool
{
    return in_array($_SESSION['role'] ?? '', ['admin', 'exploitation'], true);
}

function exiger_admin(): void
{
    exiger_connexion();
    if (!est_admin()) {
        flash('Cette page est réservée aux administrateurs.', 'erreur');
        header('Location: dashboard.php');
        exit;
    }
}

function e(string $texte): string
{
    return htmlspecialchars($texte, ENT_QUOTES, 'UTF-8');
}

// Liste des camions avec leur statut : disponible, mission ou maintenance
function camions_avec_statut(): array
{
    $camions = db()->query(
        'SELECT c.*,
                m.id AS mission_id, m.chauffeur, m.depart, m.retour_prevu, d.nom AS destination,
                ch.telephone AS chauffeur_tel,
                mt.id AS maintenance_id, mt.motif, mt.debut, mt.fin_prevue,
                r.id AS remorque_id, r.immatriculation AS remorque, r.type AS remorque_type
         FROM camions c
         LEFT JOIN remorques r     ON r.camion_id = c.id
         LEFT JOIN missions m      ON m.camion_id = c.id AND m.terminee_le IS NULL
         LEFT JOIN destinations d  ON d.id = m.destination_id
         LEFT JOIN chauffeurs ch   ON ch.id = m.chauffeur_id
         LEFT JOIN maintenances mt ON mt.camion_id = c.id AND mt.terminee_le IS NULL
         ORDER BY c.immatriculation'
    )->fetchAll();

    foreach ($camions as &$c) {
        $c['statut'] = $c['maintenance_id'] ? 'maintenance' : ($c['mission_id'] ? 'mission' : 'disponible');
    }
    return $camions;
}

// Tracteurs et remorques pris par un conteneur pas encore livré : [id => n° de conteneur].
// $sauf = conteneur à ignorer (celui qu'on modifie garde son propre attelage)
function engins_sur_conteneur(string $colonne, int $sauf = 0): array
{
    if (!in_array($colonne, ['camion_id', 'remorque_id'], true)) return [];
    $stmt = db()->prepare("SELECT $colonne, numero FROM conteneurs
                           WHERE $colonne IS NOT NULL AND livre_le IS NULL AND id <> ?");
    $stmt->execute([$sauf]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Règle d'attelage : un tracteur déjà attelé est occupé par sa semi (il ne peut pas en prendre une autre),
// et une semi déjà attelée ne part qu'avec son tracteur. Tracteur et semi libres : l'attelage sera créé.
// Renvoie le message d'erreur, ou null si le couple tracteur + semi est possible.
function erreur_attelage(int $camionId, int $remorqueId): ?string
{
    $stmt = db()->prepare('SELECT id, immatriculation, camion_id FROM remorques WHERE camion_id = ? OR id = ?');
    $stmt->execute([$camionId, $remorqueId]);
    foreach ($stmt->fetchAll() as $r) {
        if ((int) $r['camion_id'] === $camionId && (int) $r['id'] !== $remorqueId) {
            return 'Ce tracteur est déjà attelé à la semi-remorque ' . $r['immatriculation']
                . ' : il est occupé. Choisissez cette semi, ou dételez-la d\'abord dans « Remorques ».';
        }
        if ((int) $r['id'] === $remorqueId && $r['camion_id'] && (int) $r['camion_id'] !== $camionId) {
            $stmt = db()->prepare('SELECT immatriculation FROM camions WHERE id = ?');
            $stmt->execute([$r['camion_id']]);
            return 'Cette semi-remorque est déjà attelée au tracteur ' . $stmt->fetchColumn()
                . ' : choisissez ce tracteur, ou dételez-la d\'abord dans « Remorques ».';
        }
    }
    return null;
}

// Liste des chauffeurs, avec la mission en cours s'il y en a une
function chauffeurs_avec_statut(): array
{
    return db()->query(
        'SELECT ch.*, m.id AS mission_id, c.immatriculation AS camion, d.nom AS destination
         FROM chauffeurs ch
         LEFT JOIN missions m     ON m.chauffeur_id = ch.id AND m.terminee_le IS NULL
         LEFT JOIN camions c      ON c.id = m.camion_id
         LEFT JOIN destinations d ON d.id = m.destination_id
         ORDER BY ch.nom, ch.prenom'
    )->fetchAll();
}

// Taux de consommation communs à tous les camions, en litres par km
function taux_consommation(): array
{
    $p = db()->query('SELECT cle, valeur FROM parametres')->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'charge' => (float) ($p['taux_charge'] ?? 0.5),
        'vide'   => (float) ($p['taux_vide'] ?? 0.4),
    ];
}

function parametres(): array
{
    $p = db()->query('SELECT cle, valeur FROM parametres')->fetchAll(PDO::FETCH_KEY_PAIR);
    return [
        'prix_litre' => (float) ($p['prix_litre'] ?? 0),
        'devise'     => $p['devise'] ?? 'FCFA',
    ];
}

// Carburant pour un trajet : $aller = 'charge' | 'vide', $retour = 'charge' | 'vide' | 'aucun'
function calcul_carburant(int $distance, string $aller, string $retour): array
{
    $taux     = taux_consommation();
    $libelles = ['charge' => 'chargé', 'vide' => 'à vide'];
    $trajets  = [];
    foreach (['Aller' => $aller, 'Retour' => $retour] as $sens => $etat) {
        if (!isset($taux[$etat])) continue;
        $trajets[] = [
            'sens'   => $sens,
            'etat'   => $libelles[$etat],
            'km'     => $distance,
            'taux'   => $taux[$etat],
            'litres' => round($distance * $taux[$etat], 1),
        ];
    }
    return [
        'trajets'  => $trajets,
        'km'       => $distance * count($trajets),
        'litres'   => array_sum(array_column($trajets, 'litres')),
    ];
}

function montant(float $valeur, string $devise): string
{
    return number_format($valeur, 0, ',', ' ') . ' ' . $devise;
}

function litres(float $valeur): string
{
    return number_format($valeur, 1, ',', ' ') . ' L';
}

const LIBELLES_STATUT = [
    'disponible'  => 'Disponible',
    'mission'     => 'En mission',
    'maintenance' => 'En maintenance',
];

// Zones de livraison des conteneurs (renommer ici si besoin)
const ZONES = [
    1 => 'Zone 1',
    2 => 'Zone 2',
    3 => 'Zone 3',
];

// Prestations facturées pour un conteneur
const OPERATIONS_CONTENEUR = [
    'import' => 'Livraison import',
];
const MATERIELS_CONTENEUR = [
    'remorque'      => 'Remorque',
    'autochargeuse' => 'AC',
];
const TAILLES_CONTENEUR = ['20' => "20'", '40' => "40'"];

// Chargement d'une semi-remorque : un 40' ou deux 20' au maximum.
// Place prise par chaque taille, en équivalents 20' (EVP), et capacité d'une semi
const EVP_CONTENEUR = ['20' => 1, '40' => 2];
const CAPACITE_SEMI_EVP = 2;

// Type de remorque à utiliser quand le matériel est l'AC, auto-chargeuse (les autres matériels prennent une semi-remorque standard)
const TYPE_AUTOCHARGEUSE = 'AC';

// Types de semi-remorque proposés : [valeur enregistrée => libellé affiché]
const TYPES_REMORQUE = [
    'Plateau'          => 'Plateau',
    TYPE_AUTOCHARGEUSE => 'Autochargeuse (AC)',
];
// Types pour lesquels la charge utile peut rester vide
const TYPES_CHARGE_FACULTATIVE = ['Plateau'];

// Option scanner : s'ajoute à la remorque ou à l'auto-chargeuse, en zone comme hors zone : [supplément 20', supplément 40']
const SUPPLEMENT_SCANNER = [99000, 198000];

// Supplément scanner pour une taille de conteneur
function supplement_scanner(string $taille): float
{
    $colonne = array_search($taille, array_keys(TAILLES_CONTENEUR));
    return $colonne === false ? 0.0 : (float) SUPPLEMENT_SCANNER[$colonne];
}

// Grille tarifaire APMT (annexe 2 du contrat) : montants fixes, non modifiables dans l'application.
// [opération][matériel][zone] = [prix 20', prix 40'] ; une case absente = prestation non proposée
const GRILLE_ZONES = [
    'import' => [
        'remorque'      => [1 => [66000, 132000], 2 => [100000, 200000], 3 => [134000, 268000]],
        'autochargeuse' => [1 => [99000, 198000], 2 => [150000, 300000], 3 => [201000, 402000]],
    ],
];
// Prix du transport d'un conteneur selon la grille tarifaire, ou null si la case est vide
function tarif_conteneur(string $operation, string $materiel, ?int $zone, string $taille): ?float
{
    $colonne = array_search($taille, array_keys(TAILLES_CONTENEUR));
    if ($colonne === false) return null;
    $prix = GRILLE_ZONES[$operation][$materiel][(int) $zone] ?? null;
    return $prix ? (float) $prix[$colonne] : null;
}

// Matériels possibles hors zone
const MATERIELS_HORS_ZONE = ['remorque' => 'Semi-remorque standard', 'autochargeuse' => 'AC'];

// Hors zone, l'auto-chargeuse coûte le prix semi-remorque × ce coefficient
function coef_autochargeuse_hors_zone(): float
{
    static $coef = null;
    if ($coef === null) {
        $stmt = db()->query("SELECT valeur FROM parametres WHERE cle = 'coef_autochargeuse_hors_zone'");
        $coef = (float) ($stmt->fetchColumn() ?: 1.5);
    }
    return $coef;
}

// Prix d'une destination hors zone (ligne de destinations_hors_zone) pour un matériel et une taille
function prix_hors_zone(array $destination, string $materiel, string $taille): ?float
{
    $prix = $destination["prix_remorque_$taille"] ?? null;
    if ($prix === null || !isset(MATERIELS_HORS_ZONE[$materiel])) return null;
    return $materiel === 'autochargeuse' ? round($prix * coef_autochargeuse_hors_zone()) : (float) $prix;
}

// Prix d'un conteneur vers une destination hors zone, ou null si non proposé
function tarif_hors_zone(int $destinationId, string $materiel, string $taille): ?float
{
    if (!isset(TAILLES_CONTENEUR[$taille])) return null;
    $stmt = db()->prepare('SELECT * FROM destinations_hors_zone WHERE id = ?');
    $stmt->execute([$destinationId]);
    $destination = $stmt->fetch();
    return $destination ? prix_hors_zone($destination, $materiel, $taille) : null;
}

// Commission reversée au client sur chaque prestation, en %
function commission_conteneur(): float
{
    $stmt = db()->query("SELECT valeur FROM parametres WHERE cle = 'commission_conteneur'");
    return (float) ($stmt->fetchColumn() ?: 0);
}

// Jeton anti-CSRF à placer dans chaque formulaire POST
function csrf_champ(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function verifier_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Requête invalide.');
    }
}

// Message affiché une seule fois après une redirection
function flash(?string $message = null, string $type = 'succes'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
