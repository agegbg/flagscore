<?php
/**
 * File: 11_import_flagg_games.php
 * Purpose: Bootstrap for CSV import of flagg games.
 * Status: Step 1 – just wiring, error visibility, and environment checks.
 *
 * How to use now:
 *  - Load this page in the browser.
 *  - You should see a green "Environment OK" box. If not, errors will be visible (no more HTTP 500 black box).
 */

// ---- 1) Maximum error visibility during development (remove or relax in production) ----
ini_set('display_errors', '1');            // show errors in the browser
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);                    // report everything
set_exception_handler(function(Throwable $e){
    http_response_code(500);
    echo "<pre style='color:#b00;background:#fee;padding:12px;border:1px solid #f88'>";
    echo "Uncaught exception: " . htmlspecialchars($e->getMessage()) . "\n\n";
    echo htmlspecialchars($e->getFile() . ':' . $e->getLine()) . "\n\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo "</pre>";
    exit;
});

// ---- 2) Include shared plumbing (sessions, DB, file register, header/footer) ----
// NOTE: These are in the php/ subfolder per your project structure.
require_once __DIR__ . '/php/session.php';     // session bootstrap
require_once __DIR__ . '/php/db.php';          // provides $pdo (PDO) or a DB helper (adjust if needed)

// Describe page for web_files registration (menu visibility etc.)
$WEB_PAGE = [
  'system'       => 'my_football',
  'description'  => 'CSV import – raw loader for flagg_games',
  'show_in_menu' => 1,   // show in menu for now so we can click it
  'menu_order'   => 11
];
require_once __DIR__ . '/php/file_register.php'; // register this file in web_files

// Optional shared header (if you have one)
if (file_exists(__DIR__ . '/header.php')) {
    require_once __DIR__ . '/header.php';
}

// ---- 3) Quick environment checks: DB connection, table existence ----
$checks = [
    'db_connected' => false,
    'table_exists' => null,
    'php_version'  => PHP_VERSION
];

try {
    // If db.php exposes $pdo (PDO) – adjust if you use mysqli.
    if (!isset($pdo)) {
        throw new RuntimeException("\$pdo is not defined by php/db.php. Please expose a PDO instance as \$pdo.");
    }

    // Simple SELECT 1
    $pdo->query("SELECT 1");
    $checks['db_connected'] = true;

    // Check if flagg_games exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'flagg_games'");
    $checks['table_exists'] = $stmt->fetchColumn() !== false;

} catch (Throwable $e) {
    // Re-throw to our global handler for a nice formatted error box
    throw $e;
}

// ---- 4) Render a tiny status view so we know wiring works ----
?>
<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:920px;margin:24px auto;padding:16px;">
  <h1 style="margin:0 0 12px;">CSV Import – <code>flagg_games</code></h1>

  <?php if ($checks['db_connected']): ?>
    <div style="background:#e7f7ee;border:1px solid #9ad7b2;padding:12px;border-radius:8px;margin-bottom:12px;">
      ✅ <strong>Environment OK.</strong> PHP <?= htmlspecialchars($checks['php_version']) ?>, DB connection is alive.
    </div>
  <?php else: ?>
    <div style="background:#fee;border:1px solid #f99;padding:12px;border-radius:8px;margin-bottom:12px;">
      ❌ <strong>DB connection failed.</strong> See error details above.
    </div>
  <?php endif; ?>

  <ul style="line-height:1.6">
    <li>Table <code>flagg_games</code> exists: 
      <strong><?= $checks['table_exists'] ? 'YES' : 'NO' ?></strong>
    </li>
    <li>This page is registered in <code>web_files</code> (system <code>my_football</code>).</li>
    <li>Next step will add: table creator (IF NOT EXISTS) and a dry‑run CSV header preview.</li>
  </ul>

  <p style="color:#666;margin-top:16px;">
    <!-- Keep comments verbose to make maintenance easy -->
    <!-- We purposely do not import anything yet. This is only the safe bootstrap. -->
  </p>
</div>

<?php
// Optional shared footer
if (file_exists(__DIR__ . '/footer.php')) {
    require_once __DIR__ . '/footer.php';
}
