<?php
/**
 * File: 601_import_flagg_games.php
 * Purpose: Upload & import a CSV file into the raw table `flagg_games`.
 * Why: We ingest "as-is" first; we will normalize/clean later.
 *
 * How it works:
 *  - Presents an upload form (file + delimiter).
 *  - Autodetects delimiter if "auto" (tries ; , and \t).
 *  - Reads the first row as header and maps to known DB columns (case-insensitive).
 *  - Ignores unknown columns (logged in the summary so you can extend the table later).
 *  - Inserts using PDO prepared statements. Sets create_date/update_date = NOW().
 *
 * Includes:
 *  - session.php: session/bootstrap
 *  - db.php: provides getDatabaseConnection() returning PDO (utf8mb4)
 *  - header.php / footer.php: shared layout
 *  - file_register.php: registers this page in web_files (menu/metadata)
 */

require_once __DIR__ . '/php/session.php';
require_once __DIR__ . '/php/db.php';

// Optional: register page in your web_files/menu system
$WEB_PAGE = [
  'system'       => 'my_football',
  'description'  => 'Import CSV → flagg_games',
  'show_in_menu' => 1,
  'menu_order'   => 20
];
require_once __DIR__ . '/php/file_register.php';

// Shared header (Bootstrap, etc.)
require_once __DIR__ . '/header.php';

// ---------- CONFIG ----------

// Allowed DB columns in `flagg_games`. Adjust this list if you add/remove fields in the table.
$ALLOWED_COLUMNS = [
    'year',
    'division',
    'class',
    'group_name',
    'home_team_id',
    'home_score',
    'away_team_id',
    'away_score',
    'match_type',
    'match_date',
    'competition',
    'typ',        // if you have a column named "typ"
    'location',
    'city'
    // create_date / update_date are set via NOW() in SQL, not from CSV
];

// Header aliases → db column (case-insensitive matching after trimming and normalizing)
$HEADER_ALIASES = [
    // date
    'date'          => 'match_date',
    'matchdate'     => 'match_date',
    'game_date'     => 'match_date',
    // division/class/group
    'serie'         => 'division',
    'division'      => 'division',
    'klass'         => 'class',
    'class'         => 'class',
    'grupp'         => 'group_name',
    'group'         => 'group_name',
    'group_name'    => 'group_name',
    // competition/tournament
    'competition'   => 'competition',
    'tournament'    => 'competition',
    // type
    'type'          => 'typ',
    'typ'           => 'typ',
    'match_type'    => 'match_type',
    // teams & scores
    'home'          => 'home_team_id',
    'home_team'     => 'home_team_id',
    'home_team_id'  => 'home_team_id',
    'home_score'    => 'home_score',
    'away'          => 'away_team_id',
    'away_team'     => 'away_team_id',
    'away_team_id'  => 'away_team_id',
    'away_score'    => 'away_score',
    // place
    'place'         => 'location',
    'arena'         => 'location',
    'location'      => 'location',
    'city'          => 'city',
];

// ---------- HELPERS ----------

/**
 * Normalize a header string:
 * - trim, lowercase, remove BOM
 * - replace multiple spaces/underscores with single underscore
 * - remove non-alphanum except underscore
 */
function normalize_header(string $h): string {
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // strip UTF-8 BOM
    $h = mb_strtolower(trim($h), 'UTF-8');
    $h = preg_replace('/[^\p{L}\p{N}\s_]/u', ' ', $h);
    $h = preg_replace('/[\s]+/u', '_', $h);
    return $h;
}

/**
 * Try to autodetect delimiter by counting occurrences in the first non-empty line.
 */
function detect_delimiter(string $line): string {
    $candidates = [';', ',', "\t"];
    $best = ';'; $bestCount = -1;
    foreach ($candidates as $d) {
        $count = substr_count($line, $d);
        if ($count > $bestCount) { $best = $d; $bestCount = $count; }
    }
    return $best;
}

// ---------- CONTROLLER ----------

$pdo = getDatabaseConnection();

$summary = [
    'inserted'       => 0,
    'skipped'        => 0,
    'unknown_cols'   => [],
    'used_mapping'   => [],
    'detected_delim' => null,
    'errors'         => []
];

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

?>
<div class="container my-4">
  <h1 class="h3">Import CSV → <code>flagg_games</code></h1>
  <p class="text-muted">Upload your CSV “as-is”. The importer maps headers to known columns and inserts rows.</p>

  <form method="post" enctype="multipart/form-data" class="mb-4">
    <div class="form-row">
      <div class="form-group col-md-6">
        <label>CSV file</label>
        <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
      </div>
      <div class="form-group col-md-3">
        <label>Delimiter</label>
        <select name="delim" class="form-control">
          <option value="auto">Auto-detect (; , or tab)</option>
          <option value=";">Semicolon (;)</option>
          <option value=",">Comma (,)</option>
          <option value="\t">Tab</option>
        </select>
      </div>
      <div class="form-group col-md-3">
        <label>Has header row?</label>
        <select name="has_header" class="form-control">
          <option value="1" selected>Yes</option>
          <option value="0">No</option>
        </select>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Import</button>
  </form>

<?php
if ($isPost && isset($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
    $tmp = $_FILES['csv']['tmp_name'];
    $raw = file($tmp, FILE_IGNORE_NEW_LINES);

    if ($raw === false || count($raw) === 0) {
        $summary['errors'][] = 'Could not read the uploaded file or it is empty.';
    } else {
        // Detect delimiter from first non-empty line if "auto"
        $delim = $_POST['delim'] ?? 'auto';
        if ($delim === 'auto') {
            $firstLine = '';
            foreach ($raw as $line) { if (trim($line) !== '') { $firstLine = $line; break; } }
            $delim = detect_delimiter($firstLine);
        } elseif ($delim === '\\t') {
            $delim = "\t";
        }
        $summary['detected_delim'] = $delim;

        // Open again via SplFileObject + fgetcsv for robust parsing
        $fh = fopen($tmp, 'r');
        if (!$fh) {
            $summary['errors'][] = 'Failed to open uploaded file for reading.';
        } else {
            // Read header (or synthesize if no header)
            $hasHeader = ($_POST['has_header'] ?? '1') === '1';
            $header = [];
            if ($hasHeader) {
                $header = fgetcsv($fh, 0, $delim);
                if (!$header) { $summary['errors'][] = 'Could not read header row.'; }
            }
            if (!$header) {
                // Synthesize header: col1, col2, ...
                $peek = fgetcsv($fh, 0, $delim);
                if ($peek === false) { $summary['errors'][] = 'File appears to be empty.'; }
                $cols = $peek ? count($peek) : 0;
                $header = array_map(fn($i) => "col{$i}", range(1, $cols));
                // Rewind to include the peeked row in processing
                rewind($fh);
            }

            // Build mapping: CSV index → DB column (or null)
            $normHeaders = array_map('normalize_header', $header);
            $mapping = [];  // idx => dbcol|null

            foreach ($normHeaders as $idx => $h) {
                $dbcol = null;
                if (isset($HEADER_ALIASES[$h])) {
                    $dbcol = $HEADER_ALIASES[$h];
                } elseif (in_array($h, $ALLOWED_COLUMNS, true)) {
                    $dbcol = $h;
                }
                // Only keep allowed columns
                if ($dbcol !== null && !in_array($dbcol, $ALLOWED_COLUMNS, true)) {
                    $dbcol = null;
                }
                $mapping[$idx] = $dbcol;
                if ($dbcol) {
                    $summary['used_mapping'][$header[$idx]] = $dbcol;
                } else {
                    $summary['unknown_cols'][] = $header[$idx];
                }
            }

            // Prepare INSERT for the mapped columns
            $insertCols = array_values(array_unique(array_filter($mapping)));
            if (count($insertCols) === 0) {
                $summary['errors'][] = 'No known columns found in header. Please adjust aliases or table columns.';
            } else {
                // Build SQL: INSERT INTO flagg_games (mapped..., create_date, update_date) VALUES (:col..., NOW(), NOW())
                $placeholders = [];
                foreach ($insertCols as $c) { $placeholders[] = ':' . $c; }
                $sql = 'INSERT INTO flagg_games (' . implode(',', $insertCols) . ', create_date, update_date) '
                     . 'VALUES (' . implode(',', $placeholders) . ', NOW(), NOW())';
                $stmt = $pdo->prepare($sql);

                // If we had a true header, we already consumed it above; otherwise we rewound.
                if ($hasHeader) {
                    // nothing extra to do
                }

                // Read data rows
                while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                    if (count($row) === 1 && trim($row[0]) === '') { continue; } // skip empty lines

                    // Bind values by mapped columns
                    $params = [];
                    foreach ($mapping as $idx => $dbcol) {
                        if (!$dbcol) { continue; }
                        $val = isset($row[$idx]) ? trim($row[$idx]) : null;

                        // Optional: quick normalization examples
                        if ($dbcol === 'home_score' || $dbcol === 'away_score') {
                            $val = ($val === '' ? null : (int)$val);
                        }
                        if ($dbcol === 'match_date') {
                            // Accept YYYY-MM-DD, DD/MM/YY, etc.; leave as-is for now (we’ll normalize later)
                            if ($val === '') { $val = null; }
                        }
                        $params[':' . $dbcol] = ($val === '' ? null : $val);
                    }

                    try {
                        $stmt->execute($params);
                        $summary['inserted']++;
                    } catch (Throwable $e) {
                        // On error, skip and log
                        $summary['skipped']++;
                        $summary['errors'][] = 'Row skipped: ' . $e->getMessage();
                    }
                }
                fclose($fh);
            }
        }
    }

    // ---- Summary UI ----
    ?>
    <div class="card mb-4">
      <div class="card-header">Import summary</div>
      <div class="card-body">
        <p><strong>Inserted:</strong> <?= (int)$summary['inserted'] ?> &nbsp; | &nbsp;
           <strong>Skipped:</strong> <?= (int)$summary['skipped'] ?></p>
        <p><strong>Detected delimiter:</strong> <code><?= htmlspecialchars($summary['detected_delim'] ?? 'n/a') ?></code></p>

        <?php if (!empty($summary['used_mapping'])): ?>
          <h6>Header mapping (CSV → DB):</h6>
          <ul class="mb-3">
            <?php foreach ($summary['used_mapping'] as $src => $dst): ?>
              <li><code><?= htmlspecialchars($src) ?></code> → <code><?= htmlspecialchars($dst) ?></code></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (!empty($summary['unknown_cols'])): ?>
          <h6>Unknown columns ignored:</h6>
          <p class="text-muted">
            <?php foreach ($summary['unknown_cols'] as $uc): ?>
              <code><?= htmlspecialchars($uc) ?></code>
            <?php endforeach; ?>
          </p>
          <p class="small text-muted">You can extend the table or alias map later if you want to store these.</p>
        <?php endif; ?>

        <?php if (!empty($summary['errors'])): ?>
          <h6>Errors / notes:</h6>
          <ul class="text-danger">
            <?php foreach ($summary['errors'] as $err): ?>
              <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
    <?php
}
?>
</div>
<?php
// Shared footer (version, JS, Matomo slot)
require_once __DIR__ . '/footer.php';
