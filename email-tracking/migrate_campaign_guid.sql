-- Migration: Add Campaign GUID support
-- Run this in phpMyAdmin against fikrttmy_email_tracking
-- Safe to run on a live database — uses ALTER ... IF NOT EXISTS and CREATE OR REPLACE

-- -------------------------------------------------------
-- 1. New table: campaigns
--    One row per campaign (folder like pd5, board7, etc.)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS campaigns (
    campaign_id   VARCHAR(36)  PRIMARY KEY,
    folder_name   VARCHAR(100) NOT NULL,
    label         VARCHAR(255) NOT NULL,
    subject_template TEXT,
    sent_date     DATE,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_folder_name (folder_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------
-- 2. Add campaign_id column to email_sent
--    NULL = pre-migration / unknown campaign
-- -------------------------------------------------------
ALTER TABLE email_sent
    ADD COLUMN campaign_id VARCHAR(36) DEFAULT NULL,
    ADD INDEX idx_campaign_id (campaign_id);

-- -------------------------------------------------------
-- 3. Rebuild email_stats view to include campaign_id
-- -------------------------------------------------------
CREATE OR REPLACE VIEW email_stats AS
SELECT
    es.email_id,
    es.campaign_id,
    es.student_id,
    es.recipient_email,
    es.recipient_name,
    es.subject,
    es.sent_at,
    COUNT(eo.id)        AS open_count,
    MIN(eo.opened_at)   AS first_opened_at,
    MAX(eo.opened_at)   AS last_opened_at,
    CASE
        WHEN COUNT(eo.id) > 0 THEN 'Opened'
        ELSE 'Not Opened'
    END AS status
FROM email_sent es
LEFT JOIN email_opens eo ON es.email_id = eo.email_id
GROUP BY
    es.email_id, es.campaign_id, es.student_id,
    es.recipient_email, es.recipient_name, es.subject, es.sent_at
ORDER BY es.sent_at DESC;

-- -------------------------------------------------------
-- Verify: run these SELECTs after applying — expect no errors
-- -------------------------------------------------------
-- SELECT COUNT(*) AS total_campaigns FROM campaigns;
-- SELECT COUNT(*) AS total_sent, COUNT(campaign_id) AS tagged FROM email_sent;
-- SELECT campaign_id, recipient_email, status FROM email_stats LIMIT 5;
