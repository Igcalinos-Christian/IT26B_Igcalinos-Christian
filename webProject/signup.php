<?php
// signup.php
// WARNING: THIS SCRIPT STORES PASSWORDS IN PLAIN TEXT. THIS IS A SEVERE SECURITY RISK.

$servername = "localhost";
$db_username_conn = "root"; // Renamed to avoid conflict with form input variable
$db_password_conn = "";
$dbname = "webApp";

$message = "";
$form_username_input_val = "";

session_start(); // Start session to check if user is already logged in
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $form_username_input = trim($_POST['username'] ?? '');
    $form_password_input = $_POST['password'] ?? '';
    $form_username_input_val = $form_username_input;

    if (empty($form_username_input) || empty($form_password_input)) {
        $message = "Username and Password fields cannot be empty.";
    } else {
        $conn = new mysqli($servername, $db_username_conn, $db_password_conn, $dbname);

        if ($conn->connect_error) {
            error_log("Signup DB Connection Error: " . $conn->connect_error);
            $message = "A system error occurred. Please try again later.";
        } else {
            $stmt_check = $conn->prepare("SELECT userId FROM users WHERE userName = ?");
            if ($stmt_check === false) {
                error_log("Signup Prepare statement failed (check userName): " . $conn->error);
                $message = "An error occurred during setup. Please try again.";
            } else {
                $stmt_check->bind_param("s", $form_username_input);
                $stmt_check->execute();
                $stmt_check->store_result();

                if ($stmt_check->num_rows > 0) {
                    $message = "Username already taken. Please choose a different one.";
                } else {
                    $initialUserScore = 0;
                    $stmt_insert_user = $conn->prepare("INSERT INTO users (userName, password, userScore) VALUES (?, ?, ?)");
                    if ($stmt_insert_user === false) {
                        error_log("Signup Prepare statement failed (insert user): " . $conn->error);
                        $message = "An error occurred during registration. Please try again.";
                    } else {
                        $stmt_insert_user->bind_param("ssi", $form_username_input, $form_password_input, $initialUserScore);
                        if ($stmt_insert_user->execute()) {
                            header("Location: login.php?status=registered");
                            exit;
                        } else {
                            error_log("Signup Insert execution failed: " . $stmt_insert_user->error);
                            $message = "Error during sign up. Please try again.";
                        }
                        $stmt_insert_user->close();
                    }
                }
                $stmt_check->close();
            }
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
   <div class="container">
        <form id="signupForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <h2>Sign Up</h2>
            <?php if (!empty($message)): ?>
                <p class="message-display <?php echo (strpos(strtolower($message), 'error') !== false || strpos(strtolower($message), 'taken') !== false || strpos(strtolower($message), 'empty') !== false) ? 'error' : 'success'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </p>
            <?php endif; ?>
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($form_username_input_val); ?>">
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Sign Up</button>
            <p class="form-switch">Already have an account? <a href="login.php">Login</a></p>
        </form>
    </div>
</body>
</html>