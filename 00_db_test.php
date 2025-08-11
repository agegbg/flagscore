<?php
/**
 * File: 00_db_test.php
 * Description: Quick diagnostic page to verify DB connection and list all tables/columns.
 * Includes session, header/footer, and registers the file in web_files.
 */

require_once __DIR__ . '/php/session.php';   // starta session (ingen inloggning krävs just nu)
require_once __DIR__ . '/php/db.php';        // PDO/MySQLi
// Beskrivning för menyn/registrering (kan ändras per sida)
$WEB_PAGE = [
  'system'       => 'my_football',
  'description'  => 'DB-diagnostik',
  'show_in_menu' => 0,
  'menu_order'   => 0
];
require_once __DIR__ . '/php/file_register.php'; // registrera sidan i web_files

// Titel och extra HEAD (Bootstrap för snygg snabbtabell)
$PAGE_TITLE = 'Database Test';
$EXTRA_HEAD = <<<HTML
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
<style>
  body { padding: 20px; background: #f8f9fa; }
  h1 { font-size: 1.5rem; }
  pre { background: #eee; padding: 10px; }
  .table-name { font-weight: bold; margin-top: 20px; }
</style>
HTML;

require_once __DIR__ . '/php/header.php';

// === DB-anslutning ===
try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    echo "<div class='container'><h2 class='text-danger'>DB-anslutning misslyckades</h2><pre>"
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre></div>";
    require_once __DIR__ . '/php/footer.php';
    exit;
}

// Ta fram aktivt DB-namn säkert (lita inte på variabelnamn)
$dbName = null;
try {
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
} catch (Throwable $e) {
    $dbName = '(okänt)';
}

// Hämta tabeller
$tables = [];
$error = null;
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_NUM);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<div class="container">
  <h1>Database Connection Test</h1>
  <p><strong>Database:</strong> <?= htmlspecialchars((string)$dbName, ENT_QUOTES, 'UTF-8') ?></p>

  <?php if ($error): ?>
    <div class="alert alert-danger">Fel vid listning av tabeller: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php elseif (empty($tables)): ?>
    <div class="alert alert-warning">Inga tabeller hittades i databasen.</div>
  <?php else: ?>
    <?php foreach ($tables as $trow):
        $table = $trow[0];
        ?>
        <div class="table-name">Table: <?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?></div>
        <?php
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            echo "<table class='table table-sm table-bordered'><thead><tr>
                    <th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th>
                  </tr></thead><tbody>";
            foreach ($cols as $col) {
                $def = $col['Default'];
                echo "<tr>
                        <td>".htmlspecialchars($col['Field'], ENT_QUOTES, 'UTF-8')."</td>
                        <td>".htmlspecialchars($col['Type'], ENT_QUOTES, 'UTF-8')."</td>
                        <td>".htmlspecialchars($col['Null'], ENT_QUOTES, 'UTF-8')."</td>
                        <td>".htmlspecialchars($col['Key'], ENT_QUOTES, 'UTF-8')."</td>
                        <td>".htmlspecialchars((string)$def, ENT_QUOTES, 'UTF-8')."</td>
                        <td>".htmlspecialchars($col['Extra'], ENT_QUOTES, 'UTF-8')."</td>
                      </tr>";
            }
            echo "</tbody></table>";
        } catch (Throwable $e) {
            echo "<div class='alert alert-danger'>Fel vid hämtning av kolumner: "
               . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
               . "</div>";
        }
    endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/php/footer.php'; ?>
