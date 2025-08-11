<?php
/**
 * File: 90_list_flagg_games.php
 * Purpose: List all games from the `flagg_games` table with basic info.
 * Notes:
 *  - Uses php/db.php for DB connection
 *  - Displays all rows in chronological order
 *  - Later we can transform this into a standings/marathon table
 */

// === 1) Include session, DB connection, and common header/footer ===
require_once __DIR__ . '/php/session.php';   // Handles session start/login checks if needed
require_once __DIR__ . '/php/db.php';        // Contains getDatabaseConnection()
require_once __DIR__ . '/php/header.php';    // Common HTML header

// === 2) Connect to the database (PDO) ===
$pdo = getDatabaseConnection();

// === 3) Fetch all games ===
// ORDER BY match_date will sort them by the raw date string. If it's YYYY-MM-DD, it will be correct.
// If match_date has other formats, we'll normalize later.
$sql = "SELECT * FROM flagg_games ORDER BY match_date ASC, id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container mt-4">
    <h1>All Flag Football Games</h1>
    <table class="table table-striped table-bordered table-sm">
        <thead class="thead-dark">
            <tr>
                <th>ID</th>
                <th>Year</th>
                <th>Division</th>
                <th>Class</th>
                <th>Home Team</th>
                <th>Home Score</th>
                <th>Away Team</th>
                <th>Away Score</th>
                <th>Match Type</th>
                <th>Date</th>
                <th>Competition</th>
                <th>Typ</th>
                <th>Location</th>
                <th>City</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($games as $g): ?>
                <tr>
                    <td><?= htmlspecialchars($g['id']) ?></td>
                    <td><?= htmlspecialchars($g['year']) ?></td>
                    <td><?= htmlspecialchars($g['division']) ?></td>
                    <td><?= htmlspecialchars($g['class']) ?></td>
                    <td><?= htmlspecialchars($g['home_team_id']) ?></td>
                    <td><?= htmlspecialchars($g['home_score']) ?></td>
                    <td><?= htmlspecialchars($g['away_team_id']) ?></td>
                    <td><?= htmlspecialchars($g['away_score']) ?></td>
                    <td><?= htmlspecialchars($g['match_type']) ?></td>
                    <td><?= htmlspecialchars($g['match_date']) ?></td>
                    <td><?= htmlspecialchars($g['competition']) ?></td>
                    <td><?= htmlspecialchars($g['typ']) ?></td>
                    <td><?= htmlspecialchars($g['location']) ?></td>
                    <td><?= htmlspecialchars($g['city']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
// === 4) Include common footer ===
require_once __DIR__ . '/php/footer.php';
