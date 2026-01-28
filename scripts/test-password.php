<?php
// Script to test password hashing and verification
// Run this to verify password functionality

$test_password = 'password';
$hashed = password_hash($test_password, PASSWORD_DEFAULT);

echo "Original password: " . $test_password . "\n";
echo "Hashed password: " . $hashed . "\n";
echo "Verification test: " . (password_verify($test_password, $hashed) ? 'SUCCESS' : 'FAILED') . "\n";

// Test with the default hash used in database
$default_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
echo "Default hash verification: " . (password_verify($test_password, $default_hash) ? 'SUCCESS' : 'FAILED') . "\n";
?>
