<?php
/**
 * File: 91_standings.php
 * Purpose: Generate league standings table from `flagg_games`
 * Notes:
 *  - Uses Bootstrap for styling
 *  - Sorts by W, then points difference
 */

require_once __DIR__ . '/php/session.php';
require_once __DIR__ . '/php/db.php';
require_once __DIR__ . '/php/header.php';

$pdo = getDatabaseConnection();

$sql = "SELECT home_team_id, home_score, away_team_id, away_score
        FROM flagg_games
        WHERE home_team_id IS NOT NULL 
          AND away_team_id IS NOT NULL
          AND home_score IS NOT NULL 
          AND away_score IS NOT NULL";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

$standings = [];

function initTeam(&$standings, $team) {
    if (!isset($standings[$team])) {
        $standings[$team] = [
            'GP' => 0,
            'W'  => 0,
            'L'  => 0,
            'T'  => 0,
            'PF' => 0,
            'PA' => 0
        ];
    }
}

foreach ($games as $g) {
    $home = $g['home_team_id'];
    $away = $g['away_team_id'];
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

uasort($standings, function($a, $b) {
    $diff = $b['W'] <=> $a['W'];
    if ($diff === 0) {
        $diff = ($b['PF'] - $b['PA']) <=> ($a['PF'] - $a['PA']);
    }
    return $diff;
});
?>

<!-- Bootstrap CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css"
      integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T"
      crossorigin="anonymous">

<style>
    .file-link {
        font-size: 1em;
        display: block;
        margin-bottom: 5px;
        word-break: break-all;
    }
    .file-section {
        margin-bottom: 30px;
    }
    .win {
        background-color: #d4edda;
    }
    .loss {
        background-color: #f8d7da;
    }
    .tie {
        background-color: #fff3cd;
    }
</style>

<div class="container mt-4">
    <h1 class="mb-4 text-center">League Standings</h1>
    <div class="table-responsive">
        <table class="table table-bordered table-hover table-sm text-center">
            <thead class="thead-dark">
                <tr>
                    <th>Team</th>
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
                <?php foreach ($standings as $team => $stats): ?>
                    <tr>
                        <td class="text-left font-weight-bold"><?= htmlspecialchars($team) ?></td>
                        <td><?= $stats['GP'] ?></td>
                        <td class="win"><?= $stats['W'] ?></td>
                        <td class="loss"><?= $stats['L'] ?></td>
                        <td class="tie"><?= $stats['T'] ?></td>
                        <td><?= $stats['PF'] ?></td>
                        <td><?= $stats['PA'] ?></td>
                        <td><?= $stats['PF'] - $stats['PA'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/php/footer.php';
