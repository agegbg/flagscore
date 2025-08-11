<?php
/**
 * File: php/db.php
 * Purpose: Central DB connection layer (PDO + MySQLi) for the project.
 *
 * Design goals
 *  - UTF‑8 everywhere (utf8mb4).
 *  - PDO for new code (preferred), MySQLi kept for legacy compatibility.
 *  - Optional table prefix helper.
 *  - Safe defaults (strict SQL modes) without leaking secrets on errors.
 *  - Environment overrides so credentials can live outside the repo.
 *
 * Public API (backwards compatible with your code):
 *  - function tbl(string $name): string
 *  - function getDatabaseConnection(bool $forceNew = false): PDO
 *  - function getMysqli(bool $forceNew = false): mysqli
 *  - function getDbName(): string
 *  - function dbBegin(): void
 *  - function dbCommit(): void
 *  - function dbRollback(): void
 *  - function dbInTransaction(): bool
 */

/* =========================================================
   Configuration (can be overridden by environment variables)
   ========================================================= */

// IMPORTANT: These are defaults for local dev or fallback.
// On server, prefer setting environment variables:
//   DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_SOCKET, DB_PREFIX
$DB_HOST_DEFAULT   = 'localhost';
$DB_NAME_DEFAULT   = 's57703_my_football';
$DB_USER_DEFAULT   = 's57703_my_football';
$DB_PASS_DEFAULT   = '49TnCNPzgPCSNBPCQ3rz';
$DB_SOCKET_DEFAULT = '';           // e.g. '/var/run/mysqld/mysqld.sock' if your host uses sockets
$TABLE_PREFIX      = '';           // e.g. 'flagg_' if you want a global prefix

// Resolve from env first (so you can keep secrets out of git)
$DB_HOST   = getenv('DB_HOST')   ?: $DB_HOST_DEFAULT;
$DB_NAME   = getenv('DB_NAME')   ?: $DB_NAME_DEFAULT;
$DB_USER   = getenv('DB_USER')   ?: $DB_USER_DEFAULT;
$DB_PASS   = getenv('DB_PASS')   ?: $DB_PASS_DEFAULT;
$DB_SOCKET = getenv('DB_SOCKET') ?: $DB_SOCKET_DEFAULT;
$TABLE_PREFIX = getenv('DB_PREFIX') ?: $TABLE_PREFIX;

/* =============================
   Table-name prefix helper
   ============================= */
function tbl(string $name): string {
    // Build table names with a global prefix when desired.
    // Keep as a function (not constant) so tests can swap prefix.
    global $TABLE_PREFIX;
    return $TABLE_PREFIX . $name;
}

/* =========================================================
   PDO (preferred) — singleton with optional refresh
   ========================================================= */
function getDatabaseConnection(bool $forceNew = false): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO && !$forceNew) {
        return $pdo;
    }

    // Pull current config
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_SOCKET;

    // Build DSN — use UNIX socket if provided, otherwise TCP host
    if (!empty($DB_SOCKET)) {
        $dsn = "mysql:unix_socket={$DB_SOCKET};dbname={$DB_NAME};charset=utf8mb4";
    } else {
        $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";
    }

    // Reasonable defaults; avoid emulated prepares; throw exceptions
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // PDO::ATTR_PERSISTENT      => true, // Uncomment if your host benefits from it
    ];

    try {
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);

        // Enforce utf8mb4 for this connection as well (defensive)
        $pdo->exec("SET NAMES utf8mb4");

        // Strict SQL mode without being too destructive on shared hosts.
        // You can tailor this if your CSV import needs to relax something temporarily.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");

        // Optional: set time_zone if your app needs predictable timestamps
        // $pdo->exec(\"SET time_zone = '+00:00'\");
    } catch (PDOException $e) {
        // Never leak credentials; log minimal info for debugging
        error_log('[PDO] Connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Could not connect to the database (PDO).'); // Generic message to the browser
    }

    return $pdo;
}

/* =========================================================
   MySQLi (legacy) — singleton with optional refresh
   ========================================================= */
function getMysqli(bool $forceNew = false): mysqli {
    static $mysqli = null;
    if ($mysqli instanceof mysqli && !$forceNew) {
        return $mysqli;
    }

    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_SOCKET;

    // If a socket is provided, use it; otherwise use host/port defaults.
    $mysqli = @mysqli_init();

    if (!$mysqli) {
        error_log('[MySQLi] mysqli_init failed');
        http_response_code(500);
        exit('Database connection failed (MySQLi init).');
    }

    // Use real_connect to support sockets if set.
    $host = !empty($DB_SOCKET) ? null : $DB_HOST;
    $socket = !empty($DB_SOCKET) ? $DB_SOCKET : null;

    $connected = @$mysqli->real_connect(
        $host,
        $DB_USER,
        $DB_PASS,
        $DB_NAME,
        3306,      // port (ignored when socket is used)
        $socket    // socket path or null
    );

    if (!$connected) {
        error_log('[MySQLi] Connection failed: ' . mysqli_connect_error());
        http_response_code(500);
        exit('Database connection failed (MySQLi).');
    }

    if (!$mysqli->set_charset('utf8mb4')) {
        error_log('[MySQLi] Failed setting charset: ' . $mysqli->error);
        http_response_code(500);
        exit('Failed setting character set (MySQLi).');
    }

    // Mirror the PDO strict mode for consistency
    if (!$mysqli->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'")) {
        error_log('[MySQLi] Failed setting sql_mode: ' . $mysqli->error);
        // Non-fatal — continue, but we logged it.
    }

    return $mysqli;
}

/* =========================================================
   Small PDO helpers for transactions & diagnostics
   ========================================================= */

/** Return current database name (or empty string on failure). */
function getDbName(): string {
    try {
        $pdo = getDatabaseConnection();
        $name = $pdo->query("SELECT DATABASE()")->fetchColumn();
        return $name ?: '';
    } catch (Throwable $e) {
        error_log('[PDO] getDbName failed: ' . $e->getMessage());
        return '';
    }
}

/** Begin transaction (no-op if already in transaction). */
function dbBegin(): void {
    $pdo = getDatabaseConnection();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }
}

/** Commit transaction if in progress. */
function dbCommit(): void {
    $pdo = getDatabaseConnection();
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
}

/** Roll back transaction if in progress. */
function dbRollback(): void {
    $pdo = getDatabaseConnection();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

/** True if a transaction is active. */
function dbInTransaction(): bool {
    return getDatabaseConnection()->inTransaction();
}

/* =========================================================
   Optional: quick self-test (enable while debugging only)
   ========================================================= */
// try {
//     $pdo = getDatabaseConnection();
//     $pdo->query('SELECT 1');
//     // $mysqli = getMysqli();
//     // $mysqli->query('SELECT 1');
// } catch (Throwable $e) {
//     // Already logged above. Intentionally silent to the browser.
// }
/* =========================================================
   Legacy globals (for older files expecting $pdo / $mysqli)
   ========================================================= */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = getDatabaseConnection();   // expose PDO as $pdo
}
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $mysqli = getMysqli();            // expose MySQLi as $mysqli (optional)
}