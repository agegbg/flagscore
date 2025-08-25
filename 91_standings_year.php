<?php
/**
 * File: 91_standings.php
 * Purpose: Generate league standings from `flagg_games` with optional year filters.
 *
 * Features:
 *  - Filters:
 *      * Single year:  ?year=2017
 *      * Year range:   ?from=2015&to=2018
 *      * Precedence:   'year' overrides 'from'/'to'
 *  - Date column auto-detect in `flagg_games`: tries common column names
 *  - Team names from `flagg_teams` if available; otherwise shows the raw team ID
 *  - String-safe team IDs (no intval) so codes like "GGI" won't become 0
 *  - Sorting by Wins (desc) then Points Diff (PF-PA) desc
 *
 * Dependencies (already in your project):
 *  - php/session.php   (session/bootstrap)
 *  - php/db.php        (PDO: getDatabaseConnection())
 *  - php/header.php    (top layout / Bootstrap / nav)
 *  - php/footer.php    (footer layout / scripts)
 */

require_once __DIR__ . '/php/session.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/header.php';

$pdo = getDatabaseConnection();

/* -----------------------------------------------------------------------------
   1) Read and normalize filters from the query string
   ----------------------------------------------------------------------------- */

/**
 * Return YYYY-01-01 and YYYY-12-31 as strings for a given year.
 */
function yearBounds(int $y): array {
    return [$y . '-01-01', $y . '-12-31'];
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$from = isset($_GET['from']) ? (int)$_GET['from'] : null;
$to   = isset($_GET['to'])   ? (int)$_GET['to']   : null;

$startDate = null;
$endDate   = null;
$activeFilterLabel = 'All seasons'; // shown in the UI

if ($year) {
    // Single year takes precedence over range
    [$startDate, $endDate] = yearBounds($year);
    $activeFilterLabel = "Season $year";
} elseif ($from || $to) {
    // If only one bound is given, mirror it to the other
    if ($from && !$to) { $to = $from; }
    if ($to && !$from) { $from = $to; }

    if ($from && $to) {
        // Ensure from <= to
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        [$startDate, ] = yearBounds($from);
        [, $endDate]   = yearBounds($to);
        $activeFilterLabel = ($from === $to) ? "Season $from" : "Seasons $from–$to";
    }
}

/* -----------------------------------------------------------------------------
   2) Detect a usable date column in `flagg_games`
   ----------------------------------------------------------------------------- */

$gamesTable = 'flagg_games';
$possibleDateCols = ['game_date', 'date', 'gamedate', 'played_at', 'played_on', 'match_date'];
$detectedDateCol = null;

try {
    $cols = $pdo->query("DESCRIBE {$gamesTable}")->fetchAll(PDO::FETCH_COLUMN, 0);
    if ($cols) {
        foreach ($possibleDateCols as $c) {
            if (in_array($c, $cols, true)) {
                $detectedDateCol = $c;
                break;
            }
        }
    }
} catch (Throwable $e) {
    // DESCRIBE might fail if permissions are limited; ignore and continue unfiltered.
    $detectedDateCol = null;
}

/* -----------------------------------------------------------------------------
   3) Fetch completed games with optional date filtering
   ----------------------------------------------------------------------------- */

$sql = "
    SELECT home_team_id, home_score, away_team_id, away_score
    FROM {$gamesTable}
    WHERE home_team_id IS NOT NULL
      AND away_team_id IS NOT NULL
      AND home_score   IS NOT NULL
      AND away_score   IS NOT NULL
";

$params = [];
if ($detectedDateCol && $startDate && $endDate) {
    $sql .= " AND {$detectedDateCol} BETWEEN :start AND :end";
    $params[':start'] = $startDate;
    $params[':end']   = $endDate;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------------------------------------------------------
   4) Accumulate standings
   ----------------------------------------------------------------------------- */

$standings = [];

/**
 * Ensure a team bucket exists in $standings.
 * NOTE: $teamId is kept as a string to support non-numeric IDs.
 */
function initTeam(array &$standings, $teamId): void {
    $key = (string)$teamId;
    if (!isset($standings[$key])) {
        $standings[$key] = [
            'GP' => 0,
            'W'  => 0,
            'L'  => 0,
            'T'  => 0,
            'PF' => 0,
            'PA' => 0,
        ];
    }
}

foreach ($games as $g) {
    // DO NOT cast to int — preserve string IDs (e.g., codes like "GGI")
    $home = (string)$g['home_team_id'];
    $away = (string)$g['away_team_id'];
    $hs   = (int)$g['home_score'];
    $as   = (int)$g['away_score'];

    initTeam($standings, $home);
    initTeam($standings, $away);

    $standings[$home]['GP']++;
    $standings[$away]['GP']++;

    $standings[$home]['PF'] += $hs;
    $standings[$home]['PA'] += $as;
    $standings[$away]['PF'] += $as;
    $standings[$away]['PA'] += $hs;

    if ($hs > $as) {
        $standings[$home]['W']++;
        $standings[$away]['L']++;
    } elseif ($hs < $as) {
        $standings[$away]['W']++;
        $standings[$home]['L']++;
    } else {
        $standings[$home]['T']++;
        $standings[$away]['T']++;
    }
}

/* -----------------------------------------------------------------------------
   5) Sort: Wins desc, then Points Diff (PF-PA) desc
   ----------------------------------------------------------------------------- */

uasort($standings, function($a, $b) {
    $byWins = $b['W'] <=> $a['W'];
    if ($byWins !== 0) return $byWins;
    $diffA = $a['PF'] - $a['PA'];
    $diffB = $b['PF'] - $b['PA'];
    return $diffB <=> $diffA;
});

/* -----------------------------------------------------------------------------
   6) Resolve team names (optional) from `flagg_teams` with string-safe IDs
   ----------------------------------------------------------------------------- */

$teamTable = 'flagg_teams';
$teamIdColCandidates = ['id', 'team_id', 'code', 'slug']; // common patterns
$detectedTeamIdCol = null;

try {
    $tcols = $pdo->query("DESCRIBE {$teamTable}")->fetchAll(PDO::FETCH_COLUMN, 0);
    if ($tcols) {
        foreach ($teamIdColCandidates as $c) {
            if (in_array($c, $tcols, true)) {
                $detectedTeamIdCol = $c;
                break;
            }
        }
    }
} catch (Throwable $e) {
    $detectedTeamIdCol = null;
}

$teamNameById = [];
if ($detectedTeamIdCol) {
    // Collect raw keys (strings) so string IDs match correctly
    $ids = array_keys($standings);
    $ids = array_values(array_unique(array_map('strval', $ids)));

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sqlTeams = "SELECT {$detectedTeamIdCol} AS t_id, name FROM {$teamTable} WHERE {$detectedTeamIdCol} IN ($placeholders)";
        $q = $pdo->prepare($sqlTeams);
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $teamNameById[(string)$row['t_id']] = (string)$row['name'];
        }
    }
}

/**
 * Format a team label (prefer name, else raw ID).
 */
function formatTeamLabel($teamId, array $nameMap): string {
    $key = (string)$teamId;
    return isset($nameMap[$key]) ? $nameMap[$key] : $key;
}

/**
 * Build link to a per-team page, preserving the current filter.
 * Adjust the target filename if needed.
 */
function teamLink($teamId, array $nameMap, ?int $year, ?int $from, ?int $to): string {
    $label = htmlspecialchars(formatTeamLabel($teamId, $nameMap));
    $qs = [];
    if ($year) { $qs['year'] = $year; }
    else {
        if ($from) { $qs['from'] = $from; }
        if ($to)   { $qs['to']   = $to; }
    }
    $qs['team_id'] = (string)$teamId; // keep as string
    $href = '92_team_games.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
    return '<a href="' . htmlspecialchars($href) . '">' . $label . '</a>';
}

?>
<!-- Bootstrap CSS (kept here in case header.php doesn't include it) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css"
      integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T"
      crossorigin="anonymous">

<style>
    /* Minimal helper styles (Bootstrap does most of the work) */
    .win  { background-color: #d4edda; }  /* greenish for wins */
    .loss { background-color: #f8d7da; }  /* reddish for losses */
    .tie  { background-color: #fff3cd; }  /* yellowish for ties */
</style>

<div class="container mt-4 mb-5">
    <!-- Header + active filter badge -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="mb-0">League Standings</h1>
        <span class="badge badge-info" title="Active season filter">
            <?= htmlspecialchars($activeFilterLabel) ?>
        </span>
    </div>

    <!-- Filter form -->
    <form class="border rounded p-3 mb-4 bg-light" method="get" action="">
        <div class="form-row">
            <!-- Single year (overrides range if filled) -->
            <div class="form-group col-md-3">
                <label for="year">Single year (overrides range)</label>
                <input type="number" class="form-control" id="year" name="year" placeholder="e.g. 2017"
                       value="<?= $year ? (int)$year : '' ?>">
            </div>

            <!-- Range: from / to -->
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
            <a class="btn btn-outline-secondary btn-sm" href="91_standings.php">Reset filters</a>
            <?php if (($year || $from || $to) && !$detectedDateCol): ?>
                <span class="ml-3 text-danger small align-self-center">
                    Note: No date column found in <code><?= htmlspecialchars($gamesTable) ?></code>,
                    so filtering did not apply. Expected one of:
                    <?= htmlspecialchars(implode(', ', $possibleDateCols)) ?>.
                </span>
            <?php endif; ?>
        </div>
    </form>

    <!-- Standings table -->
    <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm text-center">
            <thead class="thead-dark">
                <tr>
                    <th class="text-left">Team</th>
                    <th>GP</th>
                    <th>W</th>
                    <th>L</th>
                    <th>T</th>
                    <th>PF</th>
                    <th>PA</th>
                    <th>+/-</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($standings as $teamId => $stats): ?>
                    <tr>
                        <td class="text-left font-weight-bold">
                            <?= teamLink($teamId, $teamNameById, $year, $from, $to) ?>
                        </td>
                        <td><?= (int)$stats['GP'] ?></td>
                        <td class="win"><?= (int)$stats['W'] ?></td>
                        <td class="loss"><?= (int)$stats['L'] ?></td>
                        <td class="tie"><?= (int)$stats['T'] ?></td>
                        <td><?= (int)$stats['PF'] ?></td>
                        <td><?= (int)$stats['PA'] ?></td>
                        <td><?= (int)($stats['PF'] - $stats['PA']) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($standings)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">
                            No games found<?= $activeFilterLabel !== 'All seasons' ? ' for ' . htmlspecialchars($activeFilterLabel) : '' ?>.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// Shared footer (version, copyright, JS, Matomo slot, etc.)
require_once __DIR__ . '/php/footer.php';
