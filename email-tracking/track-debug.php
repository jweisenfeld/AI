<?php
/**
 * DEBUG VERSION of Email Tracking Pixel Endpoint
 *
 * This version shows errors instead of hiding them
 * Use this to diagnose database connection issues
 */

// Show all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$DB_HOST = 'localhost';
$DB_NAME = 'fikrttmy_email_tracking';
$DB_USER = 'fikrttmy_tracker';
$DB_PASS = 'm}^KBykDn5r]';

// Get email ID from query parameter
$email_id = isset($_GET['id']) ? $_GET['id'] : 'no-id-provided';

echo "<h1>Email Tracking Debug</h1>";
echo "<p><strong>Email ID:</strong> " . htmlspecialchars($email_id) . "</p>";

// Test database connection
echo "<h2>Step 1: Testing Database Connection</h2>";
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "<p style='color:green;'>✓ Database connection successful!</p>";
    echo "<p>Connected to: <strong>$DB_NAME</strong></p>";
} catch (PDOException $e) {
    echo "<p style='color:red;'>✗ Database connection failed!</p>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<h3>Common Issues:</h3>";
    echo "<ul>";
    echo "<li>Database name wrong? Current: <code>$DB_NAME</code></li>";
    echo "<li>Username wrong? Current: <code>$DB_USER</code></li>";
    echo "<li>Password wrong? (starts with: " . substr($DB_PASS, 0, 3) . ")</li>";
    echo "<li>Database exists? Check in phpMyAdmin</li>";
    echo "<li>User has permissions? Check in MySQL Databases in cPanel</li>";
    echo "</ul>";
    exit;
}

// Test if tables exist
echo "<h2>Step 2: Checking Tables</h2>";
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "<p style='color:green;'>✓ Found " . count($tables) . " tables:</p>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>" . htmlspecialchars($table);
        if ($table == 'email_opens') {
            echo " <strong>(This is the one we need!)</strong>";
        }
        echo "</li>";
    }
    echo "</ul>";

    if (!in_array('email_opens', $tables)) {
        echo "<p style='color:red;'>✗ ERROR: email_opens table not found!</p>";
        echo "<p>You need to run the schema.sql file in phpMyAdmin</p>";
        exit;
    }
} catch (PDOException $e) {
    echo "<p style='color:red;'>✗ Error checking tables: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

// Test inserting a record
echo "<h2>Step 3: Testing Insert</h2>";
try {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $referer = $_SERVER['HTTP_REFERER'] ?? null;
    $opened_at = date('Y-m-d H:i:s');

    echo "<p>Attempting to insert:</p>";
    echo "<ul>";
    echo "<li><strong>Email ID:</strong> " . htmlspecialchars($email_id) . "</li>";
    echo "<li><strong>Opened At:</strong> " . htmlspecialchars($opened_at) . "</li>";
    echo "<li><strong>IP Address:</strong> " . htmlspecialchars($ip_address) . "</li>";
    echo "<li><strong>User Agent:</strong> " . htmlspecialchars(substr($user_agent, 0, 100)) . "</li>";
    echo "</ul>";

    $stmt = $pdo->prepare("
        INSERT INTO email_opens
        (email_id, opened_at, ip_address, user_agent, referer)
        VALUES
        (:email_id, :opened_at, :ip_address, :user_agent, :referer)
    ");

    $stmt->execute([
        ':email_id' => $email_id,
        ':opened_at' => $opened_at,
        ':ip_address' => $ip_address,
        ':user_agent' => $user_agent,
        ':referer' => $referer
    ]);

    echo "<p style='color:green;font-size:18px;'><strong>✓ SUCCESS! Record inserted into database!</strong></p>";

    // Show what was inserted
    $stmt = $pdo->prepare("SELECT * FROM email_opens WHERE email_id = :email_id ORDER BY id DESC LIMIT 1");
    $stmt->execute([':email_id' => $email_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h3>Inserted Record:</h3>";
    echo "<pre>";
    print_r($record);
    echo "</pre>";

} catch (PDOException $e) {
    echo "<p style='color:red;'>✗ Insert failed!</p>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";

    if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
        echo "<p><strong>Issue:</strong> Foreign key constraint error. The email_id needs to exist in email_sent table first.</p>";
        echo "<p><strong>Solution:</strong> We can disable the foreign key check temporarily.</p>";
    }
}

echo "<hr>";
echo "<h2>Next Steps:</h2>";
echo "<ul>";
echo "<li>If you see SUCCESS above, check phpMyAdmin → email_opens table</li>";
echo "<li>If you see an error, read the error message and fix the issue</li>";
echo "<li>Once this works, the real track.php will work too</li>";
echo "</ul>";
?>
