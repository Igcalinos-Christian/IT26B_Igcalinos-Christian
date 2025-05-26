<?php
// doomclicking.php
session_start();

$servername = "localhost";
$db_username_conn = "root";
$db_password_conn = "";
$dbname = "webApp";

// --- AJAX Score Update Handling ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_score') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'newScore' => 0, 'message' => ''];

    if (!isset($_SESSION['loggedin']) || !isset($_SESSION['userId'])) {
        $response['message'] = "Authentication error.";
        http_response_code(403);
        echo json_encode($response);
        exit;
    }
    $userIdToUpdate = $_SESSION['userId']; // Use session userId for security

    // Verify POSTed userId matches session userId if you choose to send it from JS
    // $postedUserId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;
    // if ($postedUserId !== $userIdToUpdate) {
    //     $response['message'] = "User ID mismatch.";
    //     http_response_code(403);
    //     echo json_encode($response);
    //     exit;
    // }


    $conn_ajax = new mysqli($servername, $db_username_conn, $db_password_conn, $dbname);
    if ($conn_ajax->connect_error) {
        $response['message'] = "Connection failed: " . $conn_ajax->connect_error;
        error_log("Doomclick AJAX DB connection error: " . $conn_ajax->connect_error);
        http_response_code(500);
        echo json_encode($response);
        exit;
    }

    $conn_ajax->begin_transaction();
    try {
        $stmt_update_score = $conn_ajax->prepare("UPDATE users SET userScore = userScore + 1 WHERE userId = ?");
        if (!$stmt_update_score) throw new Exception("Prepare failed (update score): " . $conn_ajax->error);
        $stmt_update_score->bind_param("i", $userIdToUpdate);
        if (!$stmt_update_score->execute()) throw new Exception("Execute failed (update score): " . $stmt_update_score->error);
        if ($stmt_update_score->affected_rows === 0) throw new Exception("User not found or score not updated.");
        $stmt_update_score->close();

        $stmt_get_score = $conn_ajax->prepare("SELECT userScore FROM users WHERE userId = ?");
        if (!$stmt_get_score) throw new Exception("Prepare failed (get score): " . $conn_ajax->error);
        $stmt_get_score->bind_param("i", $userIdToUpdate);
        $stmt_get_score->execute();
        $result_new_score = $stmt_get_score->get_result();
        if ($row_new_score = $result_new_score->fetch_assoc()) {
            $response['newScore'] = (int)$row_new_score['userScore'];
        } else {
            throw new Exception("Could not retrieve new score.");
        }
        $stmt_get_score->close();

        // Recalculate and update the entire leaderboard table
        // This function needs to be defined or inlined here
        recalculateAndUpdateLeaderboard_inline($conn_ajax); // Pass ajax connection

        $conn_ajax->commit();
        $response['success'] = true;
    } catch (Exception $e) {
        $conn_ajax->rollback();
        $response['message'] = "DB operation failed: " . $e->getMessage();
        error_log("Doomclick AJAX Exception: " . $e->getMessage());
        http_response_code(500);
    }
    $conn_ajax->close();
    echo json_encode($response);
    exit; // IMPORTANT: Stop further script execution for AJAX requests
}

// Function to be used by AJAX handler (must be defined before AJAX block or included)
// This is from your original update_score.php
function recalculateAndUpdateLeaderboard_inline($conn_func) {
    $sql_get_ranked_users = "SELECT userId FROM users ORDER BY userScore DESC, userName ASC";
    $result_ranked_users = $conn_func->query($sql_get_ranked_users);
    if (!$result_ranked_users) throw new Exception("AJAX: Failed to retrieve ranked users: " . $conn_func->error);

    if (!$conn_func->query("DELETE FROM leaderboard")) throw new Exception("AJAX: Failed to clear leaderboard: " . $conn_func->error);
    
    // Optional: Reset AUTO_INCREMENT if your leaderboard table's PK is AI and you want to reset it
    // $conn_func->query("ALTER TABLE leaderboard AUTO_INCREMENT = 1;");

    $placement = 1;
    $stmt_insert_leaderboard = $conn_func->prepare("INSERT INTO leaderboard (userID, placement) VALUES (?, ?)");
    if (!$stmt_insert_leaderboard) throw new Exception("AJAX: Prepare failed (insert leaderboard): " . $conn_func->error);

    while ($user = $result_ranked_users->fetch_assoc()) {
        $current_user_id = $user['userId'];
        $stmt_insert_leaderboard->bind_param("ii", $current_user_id, $placement);
        if (!$stmt_insert_leaderboard->execute()) {
            // Log, decide if to throw. For atomicity, throwing is better.
            throw new Exception("AJAX: Execute failed (insert leaderboard for user " . $current_user_id . "): " . $stmt_insert_leaderboard->error);
        }
        $placement++;
    }
    $stmt_insert_leaderboard->close();
    $result_ranked_users->close();
}


// --- Regular Page Load Logic ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['userId'])) {
    header("Location: login.php?error=not_logged_in");
    exit;
}

$loggedInUserId = $_SESSION['userId'];
$loggedInUsername = $_SESSION['username'];
$currentUserScore = 0;
$leaderboardData = [];
$page_message = "";

$conn_page = new mysqli($servername, $db_username_conn, $db_password_conn, $dbname);
if ($conn_page->connect_error) {
    error_log("Doomclicking Page DB Connection Error: " . $conn_page->connect_error);
    die("Database connection failed for page. Please try again later.");
}

// Rough: This method pulls the related information based on matching values.
$stmt_current_user_score = $conn_page->prepare("SELECT userScore FROM users WHERE userId = ?");
if ($stmt_current_user_score) {
    $stmt_current_user_score->bind_param("i", $loggedInUserId);
    $stmt_current_user_score->execute();
    $result_current_user_score = $stmt_current_user_score->get_result();
    if ($user_data = $result_current_user_score->fetch_assoc()) {
        $currentUserScore = $user_data['userScore'];
    } else {
        session_unset(); session_destroy();
        header("Location: login.php?error=user_data_invalid");
        $conn_page->close(); exit;
    }
    $stmt_current_user_score->close();
} else {
    $page_message = "Could not fetch your current score.";
}

// Main purpose: Fetches and controls the amount of rows in the leaderboards (alias l.)—
// —based on the number of rows within the users (u) table
$sql_leaderboard = "
    SELECT u.userName, u.userScore, l.placement
    FROM users u
    JOIN leaderboard l ON u.userId = l.userID
    ORDER BY l.placement ASC, u.userScore DESC
    LIMIT 10";
 // only includes top 10 higest, basically...

$result_leaderboard = $conn_page->query($sql_leaderboard);
if ($result_leaderboard) {
    while ($row = $result_leaderboard->fetch_assoc()) {
        $leaderboardData[] = $row;
    }
    if ($result_leaderboard->num_rows === 0 && empty($page_message)) {
        $page_message = "Leaderboard is currently empty. Be the first to score!";
    }
} else {
    error_log("Doomclicking Page: Error fetching leaderboard - " . $conn_page->error);
    if (!empty($page_message)) $page_message .= "<br>";
    $page_message .= "Could not load leaderboard data.";
}
$conn_page->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doomclicking Game - <?php echo htmlspecialchars($loggedInUsername); ?></title>
    <link rel="stylesheet" href="style.css">
    <!-- Add class to body for doomclicking specific theme overrides in style.css -->
    <script>document.addEventListener('DOMContentLoaded', () => document.body.classList.add('doomclicking-body'));</script>
</head>
<body>
    <header class="site-header">
        <nav class="main-nav">
            <ul>
                <li><a href="dashboard.php">Dashboard</a></li>
                <li><a href="doomclicking.php" class="active">Doomclicking</a></li>
                <li class="logout-link-li"><a href="login.php?action=logout">Logout (<?php echo htmlspecialchars($loggedInUsername); ?>)</a></li>
            </ul>
        </nav>
    </header>
    <div class="container doomclicking-page-container">
        <h1>DOOMCLICKER</h1>
        <?php if (!empty($page_message)): ?>
            <p class="page-message"><?php echo htmlspecialchars($page_message); ?></p>
        <?php endif; ?>
        <div class="click-area">
            <h3>Your Score: <span id="clickCounterDisplay" class="counter-display"><?php echo htmlspecialchars($currentUserScore); ?></span></h3>
            <!-- data-userid is optional here if AJAX relies purely on session -->
            <button id="clickMeButton" class="click-button" data-userid="<?php echo htmlspecialchars($loggedInUserId); ?>">Click Me!</button>
        </div>
        <h2>Leaderboard</h2>
        <table class="leaderboard-table" id="leaderboardTable">
            <thead>
                <tr>
                    <th class="placement-col">Placement</th>
                    <th class="username-col">Username</th>
                    <th class="score-col">Score</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($leaderboardData)): ?>
                    <?php foreach ($leaderboardData as $entry): ?>
                        <?php
                            $placement_display = (isset($entry['placement']) && is_numeric($entry['placement'])) ? '#' . htmlspecialchars($entry['placement']) : 'N/A';
                            $isCurrentUser = ($entry['userName'] === $loggedInUsername);
                            $safeUsernameClass = preg_replace("/[^a-zA-Z0-9_-]/", "", $entry['userName']);
                        ?>
                        <tr class="<?php echo $isCurrentUser ? 'current-user-highlight' : ''; ?>" id="user-row-<?php echo $safeUsernameClass; ?>">
                            <td class="placement-col"><?php echo $placement_display; ?></td>
                            <td class="username-col"><?php echo htmlspecialchars($entry['userName']); ?></td>
                            <td class="score-col user-score-cell-<?php echo $safeUsernameClass; ?>"><?php echo htmlspecialchars($entry['userScore']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" style="text-align:center;">Leaderboard data is not available. Play to get on the board!</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="leaderboard-note">Leaderboard rankings update after scores are processed. Refresh if needed.</p>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const clickButton = document.getElementById('clickMeButton');
        const counterDisplay = document.getElementById('clickCounterDisplay');
        const loggedInUsernameJS = <?php echo json_encode($loggedInUsername); ?>;
        // const loggedInUserIdJS = <?php echo json_encode($loggedInUserId); ?>; // userId from button data attribute or session on server

        if (clickButton) {
            clickButton.addEventListener('click', function() {
                // const userId = this.dataset.userid; // Can be sent if needed
                clickButton.disabled = true;

                fetch('doomclicking.php', { // POST to self
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    // Send action to differentiate from page load
                    // userId can also be sent if your PHP AJAX handler uses it, but session is more secure
                    body: 'action=update_score' // + '&userId=' + encodeURIComponent(userId)
                })
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(text => {
                           throw new Error('Network response: ' + response.status + (text ? ' - ' + text : ''));
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && typeof data.newScore !== 'undefined') {
                        counterDisplay.textContent = data.newScore;
                        const safeUsernameSelector = loggedInUsernameJS.replace(/[^a-zA-Z0-9_-]/g, "");
                        const userScoreCell = document.querySelector('.user-score-cell-' + safeUsernameSelector);
                        if (userScoreCell) {
                            userScoreCell.textContent = data.newScore;
                        }
                        // For full leaderboard resort, user needs to refresh the page.
                        // Alternatively, you could make another AJAX call to fetch new leaderboard HTML/data.
                    } else {
                        console.error('Error updating score:', data.message || 'Unknown server error.');
                        alert('Error: Could not update score. ' + (data.message || 'Please try again.'));
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert('Error: Could not connect to update score. ' + error.message);
                })
                .finally(() => {
                    clickButton.disabled = false;
                });
            });
        }
    });
    </script>
</body>
</html>