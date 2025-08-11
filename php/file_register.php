<?php
// php/file_register.php
declare(strict_types=1);

/**
 * Registrerar aktuell sida i tabellen `web_files`.
 * Kräver: php/db.php (PDO) och tabellen `web_files`.
 *
 * Hur man använder:
 *   // valfritt per sida före include:
 *   $WEB_PAGE = [
 *     'system'       => 'my_football',      // grupp/systemnamn
 *     'description'  => 'Maratontabell',    // visas i admin/meny
 *     'roles'        => null,               // t.ex. "admin,editor"
 *     'show_in_menu' => 1,                  // 1=visa i meny, 0=dölj
 *     'menu_order'   => 10                  // sorteringsordning
 *   ];
 *   require_once __DIR__ . '/db.php';
 *   require_once __DIR__ . '/file_register.php';
 *
 * Resultat:
 *   - Upsert i `web_files` på (system, filename)
 *   - Uppdaterar last_access varje gång
 *   - Lägger id i $GLOBALS['WEB_FILE_ID'] för vidare bruk
 */

const REGISTER_FILES = true; // sätt false om du vill stänga av snabbt

if (!REGISTER_FILES) { return; }

if (!function_exists('getDatabaseConnection')) {
    require_once __DIR__ . '/db.php';
}

function web_register(array $overrides = []): void {
    $pdo = getDatabaseConnection();

    // Hämta standardvärden och ev. överskrivningar
    $script = $_SERVER['SCRIPT_NAME'] ?? basename($_SERVER['PHP_SELF'] ?? 'unknown.php');
    // Normalisera till något likt "/dir/file.php"
    $filename = '/' . ltrim(str_replace('\\', '/', $script), '/');

    $defaults = [
        'system'       => 'my_football',
        'filename'     => mb_substr($filename, 0, 200, 'UTF-8'),
        'description'  => null,
        'roles'        => null,
        'show_in_menu' => 1,
        'menu_order'   => 0,
    ];

    // Stöd för global $WEB_PAGE från sidan
    if (isset($GLOBALS['WEB_PAGE']) && is_array($GLOBALS['WEB_PAGE'])) {
        $overrides = array_merge($GLOBALS['WEB_PAGE'], $overrides);
    }
    $data = array_merge($defaults, $overrides);

    // Upsert (unik nyckel på system+filename)
    $sql = "
        INSERT INTO `web_files`
          (system, filename, description, roles, show_in_menu, menu_order, last_access)
        VALUES
          (:system, :filename, :description, :roles, :show_in_menu, :menu_order, NOW())
        ON DUPLICATE KEY UPDATE
          description  = COALESCE(VALUES(description), description),
          roles        = COALESCE(VALUES(roles), roles),
          show_in_menu = VALUES(show_in_menu),
          menu_order   = VALUES(menu_order),
          last_access  = NOW();
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':system'       => $data['system'],
        ':filename'     => $data['filename'],
        ':description'  => $data['description'],
        ':roles'        => $data['roles'],
        ':show_in_menu' => (int)$data['show_in_menu'],
        ':menu_order'   => (int)$data['menu_order'],
    ]);

    // Hämta id (för ev. loggning/menybyggnad)
    $idStmt = $pdo->prepare("SELECT id FROM `web_files` WHERE system = :system AND filename = :filename");
    $idStmt->execute([':system' => $data['system'], ':filename' => $data['filename']]);
    $row = $idStmt->fetch();
    $GLOBALS['WEB_FILE_ID'] = $row ? (int)$row['id'] : null;
}

// Kör registreringen direkt
web_register();
