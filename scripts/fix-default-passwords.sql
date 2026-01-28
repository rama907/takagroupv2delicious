-- Fix default passwords for existing users
-- This script will reset all passwords to 'password' with proper hashing

UPDATE employees SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE password IS NULL OR password = '';

-- The hash above is for the password 'password'
-- Generated using: password_hash('password', PASSWORD_DEFAULT)
