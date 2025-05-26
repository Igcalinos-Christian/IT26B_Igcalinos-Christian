<?php
// login.php
// WARNING: THIS SCRIPT CHECKS PASSWORDS IN PLAIN TEXT. THIS IS A SEVERE SECURITY RISK.

session_start();

$servername = "localhost";
$db_username_conn = "root";
$db_password_conn = "";
$dbname = "webApp";

$login_message = "";
$form_username_input_val = "";

// Handle Logout Action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    $login_message = "You have been logged out successfully.";
    // Fall through to display login page with message
}


// If user is already logged in (and not trying to logout), redirect to dashboard
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && !(isset($_GET['action']) && $_GET['action'] === 'logout')) {
    header("Location: dashboard.php");
    exit;
}


// Messages from other pages or previous attempts
if (empty($login_message)) { // Only set if not already set by logout
    if (isset($_SESSION['login_error'])) {
        $login_message = $_SESSION['login_error'];
        unset($_SESSION['login_error']);
    } elseif (isset($_GET['status']) && $_GET['status'] === 'registered') {
        $login_message = "Registration successful! Please log in.";
    } elseif (isset($_GET['status']) && $_GET['status'] === 'account_deleted') {
        $login_message = "Your account has been deleted.";
    } elseif (isset($_GET['error']) && $_GET['error'] === 'not_logged_in') {
        $login_message = "You need to login to access that page.";
    }
}


// Process Login Form Submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $form_username_input = trim($_POST['username'] ?? '');
    $form_password_input = $_POST['password'] ?? '';
    $form_username_input_val = $form_username_input;

    if (empty($form_username_input) || empty($form_password_input)) {
        $_SESSION['login_error'] = "Username and Password fields cannot be empty.";
        header("Location: login.php");
        exit;
    }

    $conn = new mysqli($servername, $db_username_conn, $db_password_conn, $dbname);
    if ($conn->connect_error) {
        error_log("Login DB Connection Error: " . $conn->connect_error);
        $_SESSION['login_error'] = "A system error occurred. Please try again later.";
        header("Location: login.php");
        exit;
    }

    $stmt = $conn->prepare("SELECT userId, userName, password FROM users WHERE userName = ?");
    if ($stmt === false) {
        error_log("Login Prepare statement failed: " . $conn->error);
        $_SESSION['login_error'] = "An error occurred during login. Please try again.";
        header("Location: login.php");
        exit;
    }

    $stmt->bind_param("s", $form_username_input);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if ($form_password_input === $user['password']) { // Plain text comparison
            $_SESSION['loggedin'] = true;
            $_SESSION['userId'] = $user['userId'];
            $_SESSION['username'] = $user['userName'];
            header("Location: dashboard.php");
            exit;
        } else {
            $_SESSION['login_error'] = "Invalid username or password.";
            header("Location: login.php");
            exit;
        }
    } else {
        $_SESSION['login_error'] = "Invalid username or password.";
        header("Location: login.php");
        exit;
    }
    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <form id="loginForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <h2>Login</h2>
            <?php
            if (!empty($login_message)) {
                $message_class = 'info';
                if (strpos(strtolower($login_message), 'invalid') !== false ||
                    strpos(strtolower($login_message), 'error') !== false ||
                    strpos(strtolower($login_message), 'empty') !== false ||
                    strpos(strtolower($login_message), 'need to login') !== false) {
                    $message_class = 'error';
                } elseif (strpos(strtolower($login_message), 'successful') !== false && strpos(strtolower($login_message), 'logged out') === false) {
                    $message_class = 'success';
                }
                echo '<p class="message-display ' . $message_class . '">' . htmlspecialchars($login_message) . '</p>';
            }
            ?>
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($form_username_input_val); ?>">
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Login</button>
            <p class="form-switch">Don't have an account? <a href="signup.php">Sign Up</a></p>
        </form>
    </div>
</body>
</html>