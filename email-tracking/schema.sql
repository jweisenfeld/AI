-- Email Tracking Database Schema
--
-- Instructions:
-- 1. Log into cPanel → MySQL Databases
-- 2. Create a new database (e.g., flkrttmy_email_tracking)
-- 3. Create a new MySQL user with password
-- 4. Add user to database with ALL PRIVILEGES
-- 5. Go to phpMyAdmin and run this SQL script

-- Table to store sent emails
CREATE TABLE IF NOT EXISTS email_sent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email_id VARCHAR(255) UNIQUE NOT NULL,
    student_id VARCHAR(50),
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255),
    subject TEXT,
    sent_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_id (email_id),
    INDEX idx_student_id (student_id),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store email open events
CREATE TABLE IF NOT EXISTS email_opens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email_id VARCHAR(255) NOT NULL,
    opened_at DATETIME NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    referer TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_id (email_id),
    INDEX idx_opened_at (opened_at),
    FOREIGN KEY (email_id) REFERENCES email_sent(email_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View for email open statistics
CREATE OR REPLACE VIEW email_stats AS
SELECT
    es.email_id,
    es.student_id,
    es.recipient_email,
    es.recipient_name,
    es.subject,
    es.sent_at,
    COUNT(eo.id) as open_count,
    MIN(eo.opened_at) as first_opened_at,
    MAX(eo.opened_at) as last_opened_at,
    CASE
        WHEN COUNT(eo.id) > 0 THEN 'Opened'
        ELSE 'Not Opened'
    END as status
FROM email_sent es
LEFT JOIN email_opens eo ON es.email_id = eo.email_id
GROUP BY es.email_id, es.student_id, es.recipient_email, es.recipient_name, es.subject, es.sent_at
ORDER BY es.sent_at DESC;

-- Sample query to check open rates
-- SELECT
--     status,
--     COUNT(*) as count,
--     ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM email_sent), 2) as percentage
-- FROM email_stats
-- GROUP BY status;
