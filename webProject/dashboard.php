<?php
// dashboard.php
// WARNING: THIS SCRIPT HANDLES PLAIN TEXT PASSWORDS. THIS IS A SEVERE SECURITY RISK.
session_start();

$servername = "localhost";
$db_username_conn = "root";
$db_password_conn = "";
$dbname = "webApp";

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['userId'])) {
    header("Location: login.php?error=not_logged_in");
    exit;
}

$loggedInUserId = $_SESSION['userId'];
$loggedInUsername = $_SESSION['username'];

$message = "";
$userScore = "N/A";
$currentUsernameForForm = $loggedInUsername;

$conn = new mysqli($servername, $db_username_conn, $db_password_conn, $dbname);
if ($conn->connect_error) {
    error_log("Dashboard DB Connection Error: " . $conn->connect_error);
    die("A critical system error occurred. Please try again later.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    if ($_POST['action'] === 'update') {
        $newUsernameInput = trim($_POST['dashUsername']);
        $newPasswordInput = $_POST['dashPassword']; // No trim

        $updateFields = [];
        $bindParams = [];
        $bindTypes = "";

        if (!empty($newUsernameInput) && $newUsernameInput !== $loggedInUsername) {
            $stmt_check_new_user = $conn->prepare("SELECT userId FROM users WHERE userName = ? AND userId != ?");
            if ($stmt_check_new_user) {
                $stmt_check_new_user->bind_param("si", $newUsernameInput, $loggedInUserId);
                $stmt_check_new_user->execute();
                $stmt_check_new_user->store_result();
                if ($stmt_check_new_user->num_rows > 0) {
                    $message = "Error: New username '" . htmlspecialchars($newUsernameInput) . "' is already taken.";
                } else {
                    $updateFields[] = "userName = ?";
                    $bindParams[] = $newUsernameInput;
                    $bindTypes .= "s";
                }
                $stmt_check_new_user->close();
            } else {
                $message = "Error preparing username check: " . $conn->error;
            }
        }

        if (!empty($newPasswordInput)) {
            $updateFields[] = "password = ?";
            $bindParams[] = $newPasswordInput; // Plain text
            $bindTypes .= "s";
        }

        if (empty($message) && !empty($updateFields)) {
            $bindParams[] = $loggedInUserId;
            $bindTypes .= "i";
            $sql_update = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE userId = ?";
            $stmt_update = $conn->prepare($sql_update);
            if ($stmt_update) {
                $stmt_update->bind_param($bindTypes, ...$bindParams);
                if ($stmt_update->execute()) {
                    $message = "Account updated successfully.";
                    if (in_array("userName = ?", $updateFields)) {
                        $_SESSION['username'] = $newUsernameInput;
                        $loggedInUsername = $newUsernameInput;
                        $currentUsernameForForm = $newUsernameInput;
                    }
                } else {
                    $message = "Error updating account: " . $stmt_update->error;
                }
                $stmt_update->close();
            } else {
                $message = "Error preparing update statement: " . $conn->error;
            }
        } elseif (empty($message) && empty($updateFields)) {
             $message = "No changes detected. Nothing updated.";
        }

    } elseif ($_POST['action'] === 'delete') {
        $stmt_delete_user = $conn->prepare("DELETE FROM users WHERE userId = ?");
        if ($stmt_delete_user) {
            $stmt_delete_user->bind_param("i", $loggedInUserId);
            if ($stmt_delete_user->execute()) {
                if ($stmt_delete_user->affected_rows > 0) {
                    // Also delete from leaderboard table
                    $stmt_delete_leaderboard = $conn->prepare("DELETE FROM leaderboard WHERE userID = ?");
                    if($stmt_delete_leaderboard) {
                        $stmt_delete_leaderboard->bind_param("i", $loggedInUserId);
                        $stmt_delete_leaderboard->execute();
                        $stmt_delete_leaderboard->close();
                    } // else log error if leaderboard delete fails but proceed with logout

                    session_unset();
                    session_destroy();
                    header("Location: login.php?status=account_deleted");
                    $conn->close();
                    exit;
                } else {
                    $message = "Error: Could not delete account (user not found).";
                }
            } else {
                $message = "Error deleting account: " . $stmt_delete_user->error;
                 if ($conn->errno == 1451) {
                     $message = "Error deleting account: Cannot delete due to related records (e.g., if leaderboard has strict FK and ON DELETE RESTRICT).";
                 }
            }
            $stmt_delete_user->close();
        } else {
            $message = "Error preparing user delete statement: " . $conn->error;
        }
    }
}

$stmt_user_data = $conn->prepare("SELECT userName, userScore FROM users WHERE userId = ?");
if ($stmt_user_data) {
    $stmt_user_data->bind_param("i", $loggedInUserId);
    $stmt_user_data->execute();
    $result_user_data = $stmt_user_data->get_result();
    if ($user_data_row = $result_user_data->fetch_assoc()) {
        if ($loggedInUsername !== $user_data_row['userName']) { // Sync session if changed elsewhere
             $_SESSION['username'] = $user_data_row['userName'];
             $loggedInUsername = $user_data_row['userName'];
        }
        $currentUsernameForForm = $user_data_row['userName'];
        $userScore = $user_data_row['userScore'];
    } else {
        session_unset(); session_destroy();
        header("Location: login.php?error=user_not_found_critical");
        $conn->close(); exit;
    }
    $stmt_user_data->close();
} else {
    die("A critical error occurred while fetching your data.");
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - <?php echo htmlspecialchars($loggedInUsername); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="site-header">
        <nav class="main-nav">
            <ul>
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="doomclicking.php">Doomclicking</a></li>
                <li class="logout-link-li"><a href="login.php?action=logout">Logout (<?php echo htmlspecialchars($loggedInUsername); ?>)</a></li>
            </ul>
        </nav>
    </header>
    <div class="container dashboard-container">
        <h2>User Dashboard</h2>
        <p class="sub-header">Welcome, <?php echo htmlspecialchars($loggedInUsername); ?>!</p>
        <?php if (!empty($message)): ?>
            <p class="message-display <?php echo (strpos(strtolower($message), 'error') !== false || strpos(strtolower($message), 'could not delete') !== false) ? 'error' : 'success'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </p>
        <?php endif; ?>
        <div class="info-group">
            <span class="info-label">Your Current Score:</span>
            <span id="userScoreDisplay" class="info-value"><?php echo htmlspecialchars($userScore); ?></span>
        </div>
        <form id="dashboardUpdateForm" method="POST" action="dashboard.php">
            <h3>Update Account</h3>
            <div class="form-group">
                <label for="dashUsername">Username:</label>
                <input type="text" id="dashUsername" name="dashUsername" value="<?php echo htmlspecialchars($currentUsernameForForm); ?>">
                <small>Enter a new username or leave as is.</small>
            </div>
            <div class="form-group">
                <label for="dashPassword">New Password:</label>
                <input type="password" id="dashPassword" name="dashPassword" placeholder="Leave empty to keep current password">
                <small>Enter a new password or leave empty.</small>
            </div>
            <div class="action-buttons single-center">
                <button type="submit" name="action" value="update" class="btn btn-primary">Update Account Details</button>
            </div>
        </form>
        <hr>
        <form id="dashboardDeleteForm" method="POST" action="dashboard.php">
            <h3>Delete Account</h3>
            <p><strong>Warning:</strong> This action cannot be undone and will permanently remove your account and scores.</p>
            <button type="submit" name="action" value="delete" class="btn btn-danger" onclick="return confirm('Are you absolutely sure you want to delete your account? This action is permanent and cannot be undone.');">Delete My Account</button>
        </form>
    </div>
</body>
</html>