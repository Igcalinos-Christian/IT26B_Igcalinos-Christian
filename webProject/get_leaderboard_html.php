<?php
$servername = "localhost";
$db_username_db = "root";
$db_password = "";
$dbname = "webApp";

$loggedInUsernameForHighlight = isset($_GET['currentUser']) ? $_GET['currentUser'] : null;

$conn = new mysqli($servername, $db_username_db, $db_password, $dbname);
if ($conn->connect_error) {
    // In a real app, log this error, don't just die
    die("Connection failed: " . $conn->connect_error);
}

// Fetch leaderboard data (Top 10 or all, joined with users for userName and userScore)
// Order by placement from the now-updated leaderboard table
$sql_leaderboard = "
    SELECT u.userName, u.userScore, l.placement
    FROM leaderboard l
    JOIN users u ON l.userID = u.userId
    ORDER BY l.placement ASC
    LIMIT 10"; // Or however many you want to display

$result_leaderboard = $conn->query($sql_leaderboard);
$output_html = "";

if ($result_leaderboard && $result_leaderboard->num_rows > 0) {
    while ($entry = $result_leaderboard->fetch_assoc()) {
        $isCurrentUser = ($loggedInUsernameForHighlight && $entry['userName'] === $loggedInUsernameForHighlight);
        $highlightClass = $isCurrentUser ? 'current-user-highlight' : '';

        $output_html .= "<tr class=\"{$highlightClass}\" id=\"user-row-" . htmlspecialchars($entry['userName']) . "\">";
        $output_html .= "<td class=\"placement-col\">#" . htmlspecialchars($entry['placement']) . "</td>";
        $output_html .= "<td class=\"username-col\">" . htmlspecialchars($entry['userName']) . "</td>";
        $output_html .= "<td class=\"score-col user-score-cell-" . htmlspecialchars($entry['userName']) . "\">" . htmlspecialchars($entry['userScore']) . "</td>";
        $output_html .= "</tr>";
    }
} elseif($result_leaderboard) {
    $output_html = '<tr><td colspan="3" style="text-align:center;">Leaderboard is empty.</td></tr>';
} else {
    $output_html = '<tr><td colspan="3" style="text-align:center;">Error loading leaderboard: ' . $conn->error . '</td></tr>';
}

$conn->close();
echo $output_html; // Just output the <tbody> content
?>