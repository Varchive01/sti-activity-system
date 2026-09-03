<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// 1. Setup Database Table
try {
    $db = getDB();
    $sql = "CREATE TABLE IF NOT EXISTS email_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        activity_id INT NULL, 
        recipient_email VARCHAR(255) NOT NULL, 
        notification_type VARCHAR(50) NOT NULL, 
        status VARCHAR(20) NOT NULL, 
        error_message TEXT, 
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    $db->exec($sql);
    echo "Database table 'email_logs' created or already exists.\n";
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}

// 2. Download and Setup Composer
if (!file_exists('composer.phar')) {
    echo "Downloading composer.phar...\n";
    copy('https://getcomposer.org/download/latest-stable/composer.phar', 'composer.phar');
}

echo "Installing PHPMailer via composer.phar...\n";
exec('c:\xampp\php\php.exe composer.phar require phpmailer/phpmailer', $output, $return_var);
if ($return_var !== 0) {
    // try fallback just php
    exec('php composer.phar require phpmailer/phpmailer', $output2, $return_var2);
    if ($return_var2 !== 0) {
        echo "Failed to install PHPMailer.\n";
        print_r($output2);
    } else {
        echo "PHPMailer installed successfully.\n";
    }
} else {
    echo "PHPMailer installed successfully.\n";
}
