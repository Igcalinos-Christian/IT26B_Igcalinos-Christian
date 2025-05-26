<?php
header('Content-Type: application/json');

$servername = "localhost";
$db_username_db = "root";
$db_password = "";
$dbname = "webApp";

$response = ['success' => false, 'newScore' => 0, 'message' => ''];

// Function to update the entire leaderboard table based on current scores
function recalculateAndUpdateLeaderboard($conn) {
    // Step 1: Get all users ordered by score (for ranking)
    $sql_get_ranked_users = "SELECT userId FROM users ORDER BY userScore DESC, userId ASC"; // Tie-break by userId
    $result_ranked_users = $conn->query($sql_get_ranked_users);

    if (!$result_ranked_users) {
        throw new Exception("Failed to retrieve ranked users: " . $conn->error);
    }

    // Step 2: Clear the existing leaderboard
    // For high-traffic, TRUNCATE is faster if no FKs prevent it, but DELETE is safer.
    $conn->query("DELETE FROM leaderboard");
    // Or if entityID is not auto-increment and you want to reset it:
    // $conn->query("TRUNCATE TABLE leaderboard");


    // Step 3: Insert new placement data
    $placement = 1;
    $stmt_insert_leaderboard = $conn->prepare("INSERT INTO leaderboard (userID, placement) VALUES (?, ?)");
    if (!$stmt_insert_leaderboard) {
        throw new Exception("Prepare failed (insert leaderboard): " . $conn->error);
    }

    while ($user = $result_ranked_users->fetch_assoc()) {
        $current_user_id = $user['userId'];
        $stmt_insert_leaderboard->bind_param("ii", $current_user_id, $placement);
        if (!$stmt_insert_leaderboard->execute()) {
            // Log error, but try to continue for other users if possible, or re-throw
            // For simplicity, we'll throw, which will roll back transaction.
            throw new Exception("Execute failed (insert leaderboard for user " . $current_user_id . "): " . $stmt_insert_leaderboard->error);
        }
        $placement++;
    }
    $stmt_insert_leaderboard->close();
    $result_ranked_users->close();
}


if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['userId'])) {
    $userIdToUpdate = intval($_POST['userId']);

    if ($userIdToUpdate > 0) {
        $conn = new mysqli($servername, $db_username_db, $db_password, $dbname);
        if ($conn->connect_error) {
            $response['message'] = "Connection failed: " . $conn->connect_error;
            echo json_encode($response);
            exit;
        }

        $conn->begin_transaction();
        try {
            // Increment userScore in the 'users' table
            $stmt_update_score = $conn->prepare("UPDATE users SET userScore = userScore + 1 WHERE userId = ?");
            if (!$stmt_update_score) throw new Exception("Prepare failed (update score): " . $conn->error);
            $stmt_update_score->bind_param("i", $userIdToUpdate);
            if (!$stmt_update_score->execute()) throw new Exception("Execute failed (update score): " . $stmt_update_score->error);
            $stmt_update_score->close();

            // Get the new score
            $stmt_get_score = $conn->prepare("SELECT userScore FROM users WHERE userId = ?");
            if (!$stmt_get_score) throw new Exception("Prepare failed (get score): " . $conn->error);
            $stmt_get_score->bind_param("i", $userIdToUpdate);
            $stmt_get_score->execute();
            $result_new_score = $stmt_get_score->get_result();
            if ($row_new_score = $result_new_score->fetch_assoc()) {
                $response['newScore'] = $row_new_score['userScore'];
            } else {
                throw new Exception("Could not retrieve new score.");
            }
            $stmt_get_score->close();

            // Recalculate and update the entire leaderboard table
            recalculateAndUpdateLeaderboard($conn);

            $conn->commit();
            $response['success'] = true;

        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = "Database error: " . $e->getMessage();
        }
        $conn->close();
    } else {
        $response['message'] = "Invalid user ID provided.";
    }
} else {
    $response['message'] = "Invalid request.";
}

echo json_encode($response);
?>