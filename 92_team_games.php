<?php
/**
 * File: 92_team_games.php
 * Purpose: Show all games for a selected team, with per-game results and a summary.
 *
 * Usage examples:
 *   92_team_games.php?team_id=GGI
 *   92_team_games.php?team_id=42&year=2018
 *   92_team_games.php?team_id=NYJ&from=2015&to=2018
 *
 * Notes:
 *  - Team IDs are treated as strings to support codes (e.g. "GGI"). No intval.
 *  - Filters follow the same logic as 91_standings.php:
 *      * 'year' overrides 'from'/'to'
 *      * If only one bound is given in a range, it mirrors to the other.
 *  - Date filtering only applies if a recognized date column exists in `flagg_games`.
 *  - If `flagg_teams` exists, names are shown; otherwise raw IDs are used.
 */

require_once __DIR__ . '/php/session.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/header.php';

$pdo = getDatabaseConnection();

/* -----------------------------------------------------------------------------
   0) Read and validate team_id
   ----------------------------------------------------------------------------- */

if (!isset($_GET['team_id']) || $_GET['team_id'] === '') {
    ?>
    <div class="container mt-4">
        <div class="alert alert-danger">
            Missing required parameter: <code>team_id</code>.
        </div>
        <a class="btn btn-secondary" href="91_standings.php">Back to standings</a>
    </div>
    <?php
    require_once __DIR__ . '/php/footer.php';
    exit;
}

// Keep team_id as string to support non-numeric IDs
$TEAM_ID = (string)$_GET['team_id'];

/* -----------------------------------------------------------------------------
   1) Filter parsing (same behavior as 91_standings.php)
   ----------------------------------------------------------------------------- */

function yearBounds(int $y): array {
    return [$y . '-01-01', $y . '-12-31'];
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$from = isset($_GET['from']) ? (int)$_GET['from'] : null;
$to   = isset($_GET['to'])   ? (int)$_GET['to']   : null;

$startDate = null;
$endDate   = null;
$activeFilterLabel = 'All seasons';

if ($year) {
    [$startDate, $endDate] = yearBounds($year);
    $activeFilterLabel = "Season $year";
} elseif ($from || $to) {
    if ($from && !$to) { $to = $from; }
    if ($to && !$from) { $from = $to; }
    if ($from && $to) {
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        [$startDate, ] = yearBounds($from);
        [, $endDate]   = yearBounds($to);
        $activeFilterLabel = ($from === $to) ? "Season $from" : "Seasons $from–$to";
    }
}

/* -----------------------------------------------------------------------------
   2) Detect date column in flagg_games (for filtering)
   ----------------------------------------------------------------------------- */

$gamesTable = 'flagg_games';
$possibleDateCols = ['game_date', 'date', 'gamedate', 'played_at', 'played_on', 'match_date'];
$detectedDateCol = null;

try {
    $cols = $pdo->query("DESCRIBE {$gamesTable}")->fetchAll(PDO::FETCH_COLUMN, 0);
    if ($cols) {
        foreach ($possibleDateCols as $c) {
            if (in_array($c, $cols, true)) { $detectedDateCol = $c; break; }
        }
    }
} catch (Throwable $e) {
    $detectedDateCol = null;
}

/* -----------------------------------------------------------------------------
   3) Prepare team-name resolution (optional) from flagg_teams
   ----------------------------------------------------------------------------- */

$teamTable = 'flagg_teams';
$teamIdColCandidates = ['id', 'team_id', 'code', 'slug'];
$detectedTeamIdCol = null;

try {
    $tcols = $pdo->query("DESCRIBE {$teamTable}")->fetchAll(PDO::FETCH_COLUMN, 0);
    if ($tcols) {
        foreach ($teamIdColCandidates as $c) {
            if (in_array($c, $tcols, true)) { $detectedTeamIdCol = $c; break; }
        }
    }
} catch (Throwable $e) {
    $detectedTeamIdCol = null;
}

// Simple name cache
$teamNameById = [];

function getTeamLabel(PDO $pdo, string $teamId, array &$nameMap, ?string $teamTable, ?string $idCol): string {
    $key = (string)$teamId;
    if ($teamTable && $idCol) {
        if (!isset($nameMap[$key])) {
            $q = $pdo->prepare("SELECT name FROM {$teamTable} WHERE {$idCol} = ? LIMIT 1");
            $q->execute([$key]);
            $name = $q->fetchColumn();
            $nameMap[$key] = $name ? (string)$name : $key;
        }
        return $nameMap[$key];
    }
    return $key;
}

/* -----------------------------------------------------------------------------
   4) Fetch the team's games (with optional date filter)
   ----------------------------------------------------------------------------- */

$sql = "
    SELECT 
        home_team_id, home_score,
        away_team_id, away_score
        " . ($detectedDateCol ? ", {$detectedDateCol} AS game_date" : "") . "
    FROM {$gamesTable}
    WHERE 
        (home_team_id = :tid OR away_team_id = :tid)
        AND home_team_id IS NOT NULL
        AND away_team_id IS NOT NULL
        AND home_score   IS NOT NULL
        AND away_score   IS NOT NULL
";

$params = [':tid' => $TEAM_ID];

if ($detectedDateCol && $startDate && $endDate) {
    $sql .= " AND {$detectedDateCol} BETWEEN :start AND :end";
    $params[':start'] = $startDate;
    $params[':end']   = $endDate;
}

// Prefer chronological order if we have a date; otherwise leave as-is
if ($detectedDateCol) {
    $sql .= " ORDER BY {$detectedDateCol} ASC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------------------------------------------------------
   5) Compute team-centric summary (GP/W/L/T, PF/PA, diff) and build display rows
   ----------------------------------------------------------------------------- */

$summary = ['GP'=>0,'W'=>0,'L'=>0,'T'=>0,'PF'=>0,'PA'=>0];

$displayRows = []; // each row: date, homeName, homeScore, awayName, awayScore, result, diff

foreach ($rows as $r) {
    $homeId = (string)$r['home_team_id'];
    $awayId = (string)$r['away_team_id'];
    $hs     = (int)$r['home_score'];
    $as     = (int)$r['away_score'];
    $date   = $detectedDateCol && isset($r['game_date']) ? (string)$r['game_date'] : '';

    // Resolve names (cached)
    $homeName = getTeamLabel($pdo, $homeId, $teamNameById, $detectedTeamIdCol ? $teamTable : null, $detectedTeamIdCol);
    $awayName = getTeamLabel($pdo, $awayId, $teamNameById, $detectedTeamIdCol ? $teamTable : null, $detectedTeamIdCol);

    // Determine perspective of selected team
    $isHome = ($homeId === $TEAM_ID);

    $summary['GP']++;

    if ($isHome) {
        $summary['PF'] += $hs;
        $summary['PA'] += $as;
        if     ($hs > $as) { $summary['W']++; $result = 'W'; }
        elseif ($hs < $as) { $summary['L']++; $result = 'L'; }
        else               { $summary['T']++; $result = 'T'; }
        $diff = $hs - $as;
    } else {
        $summary['PF'] += $as;
        $summary['PA'] += $hs;
        if     ($as > $hs) { $summary['W']++; $result = 'W'; }
        elseif ($as < $hs) { $summary['L']++; $result = 'L'; }
        else               { $summary['T']++; $result = 'T'; }
        $diff = $as - $hs;
    }

    $displayRows[] = [
        'date'      => $date,
        'home_name' => $homeName,
        'home_id'   => $homeId,
        'home_pts'  => $hs,
        'away_name' => $awayName,
        'away_id'   => $awayId,
        'away_pts'  => $as,
        'result'    => $result, // W/L/T from TEAM_ID perspective
        'diff'      => $diff,   // PF-PA from TEAM_ID perspective
    ];
}

/* -----------------------------------------------------------------------------
   6) Helper to rebuild query string for "Back to standings" link
   ----------------------------------------------------------------------------- */

function buildStandingsHref(?int $year, ?int $from, ?int $to): string {
    $qs = [];
    if ($year) { $qs['year'] = $year; }
    else {
        if ($from) { $qs['from'] = $from; }
        if ($to)   { $qs['to']   = $to; }
    }
    $href = '91_standings.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
    return $href;
}

$teamLabel = getTeamLabel($pdo, $TEAM_ID, $teamNameById, $detectedTeamIdCol ? $teamTable : null, $detectedTeamIdCol);
$standingsHref = buildStandingsHref($year, $from, $to);

?>
<!-- Bootstrap CSS (safe to include in case header.php doesn't) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css"
      integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T"
      crossorigin="anonymous">

<style>
    .tag { font-size: 0.9rem; }
    .res-w { background-color: #d4edda; } /* win */
    .res-l { background-color: #f8d7da; } /* loss */
    .res-t { background-color: #fff3cd; } /* tie  */
</style>

<div class="container mt-4 mb-5">
    <!-- Header row -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="mb-0"><?= htmlspecialchars($teamLabel) ?></h1>
            <small class="text-muted">Team ID: <code><?= htmlspecialchars($TEAM_ID) ?></code></small>
        </div>
        <div class="text-right">
            <span class="badge badge-info tag" title="Active season filter"><?= htmlspecialchars($activeFilterLabel) ?></span>
            <div class="mt-2">
                <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars($standingsHref) ?>">Back to standings</a>
            </div>
        </div>
    </div>

    <!-- Filter form (same behavior as 91_standings) -->
    <form class="border rounded p-3 mb-4 bg-light" method="get" action="">
        <input type="hidden" name="team_id" value="<?= htmlspecialchars($TEAM_ID) ?>">
        <div class="form-row">
            <div class="form-group col-md-3">
                <label for="year">Single year (overrides range)</label>
                <input type="number" class="form-control" id="year" name="year" placeholder="e.g. 2017"
                       value="<?= $year ? (int)$year : '' ?>">
            </div>
            <div class="form-group col-md-3">
                <label for="from">From year</label>
                <input type="number" class="form-control" id="from" name="from" placeholder="e.g. 2015"
                       value="<?= ($year ? '' : ($from ? (int)$from : '')) ?>">
            </div>
            <div class="form-group col-md-3">
                <label for="to">To year</label>
                <input type="number" class="form-control" id="to" name="to" placeholder="e.g. 2018"
                       value="<?= ($year ? '' : ($to ? (int)$to : '')) ?>">
            </div>
            <div class="form-group col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-block">Apply</button>
            </div>
        </div>
        <div class="d-flex">
            <a class="btn btn-outline-secondary btn-sm" href="92_team_games.php?team_id=<?= urlencode($TEAM_ID) ?>">Reset filters</a>
            <?php if (($year || $from || $to) && !$detectedDateCol): ?>
                <span class="ml-3 text-danger small align-self-center">
                    Note: No date column found in <code><?= htmlspecialchars($gamesTable) ?></code>,
                    so filtering did not apply. Expected one of:
                    <?= htmlspecialchars(implode(', ', $possibleDateCols)) ?>.
                </span>
            <?php endif; ?>
        </div>
    </form>

    <!-- Summary -->
    <div class="row mb-3">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-body py-2">
                    <strong>Summary:</strong>
                    <span class="ml-2">GP: <?= (int)$summary['GP'] ?></span>
                    <span class="ml-3 text-success">W: <?= (int)$summary['W'] ?></span>
                    <span class="ml-3 text-danger">L: <?= (int)$summary['L'] ?></span>
                    <span class="ml-3 text-warning">T: <?= (int)$summary['T'] ?></span>
                    <span class="ml-3">PF: <?= (int)$summary['PF'] ?></span>
                    <span class="ml-3">PA: <?= (int)$summary['PA'] ?></span>
                    <span class="ml-3">+/-: <?= (int)($summary['PF'] - $summary['PA']) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Games table -->
    <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm text-center">
            <thead class="thead-dark">
                <tr>
                    <?php if ($detectedDateCol): ?><th>Date</th><?php endif; ?>
                    <th class="text-left">Home</th>
                    <th>Score</th>
                    <th class="text-left">Away</th>
                    <th>Result</th>
                    <th>+/-</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($displayRows)): ?>
                    <tr>
                        <td colspan="<?= $detectedDateCol ? 6 : 5; ?>" class="text-muted">
                            No games found<?= $activeFilterLabel !== 'All seasons' ? ' for ' . htmlspecialchars($activeFilterLabel) : '' ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($displayRows as $row): ?>
                        <?php
                            $cls = ($row['result'] === 'W') ? 'res-w' : (($row['result'] === 'L') ? 'res-l' : 'res-t');
                        ?>
                        <tr class="<?= $cls ?>">
                            <?php if ($detectedDateCol): ?>
                                <td><?= htmlspecialchars($row['date']) ?></td>
                            <?php endif; ?>
                            <td class="text-left"><?= htmlspecialchars($row['home_name']) ?></td>
                            <td><?= (int)$row['home_pts'] ?> - <?= (int)$row['away_pts'] ?></td>
                            <td class="text-left"><?= htmlspecialchars($row['away_name']) ?></td>
                            <td><strong><?= htmlspecialchars($row['result']) ?></strong></td>
                            <td><?= (int)$row['diff'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/php/footer.php';
