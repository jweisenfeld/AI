-- Backfill email_sent table from email_opens data
-- This will create email_sent records for all the tracked opens we already have

INSERT INTO email_sent (email_id, student_id, recipient_email, recipient_name, subject, sent_at)
SELECT DISTINCT
    eo.email_id,
    'UNKNOWN' as student_id,
    'unknown@example.com' as recipient_email,
    'Unknown Recipient' as recipient_name,
    'Tracked Email' as subject,
    MIN(eo.opened_at) as sent_at
FROM email_opens eo
WHERE NOT EXISTS (
    SELECT 1 FROM email_sent es WHERE es.email_id = eo.email_id
)
GROUP BY eo.email_id;

-- Check how many records were added
SELECT COUNT(*) as records_added FROM email_sent;

-- View the email_stats to see if dashboard will work now
SELECT * FROM email_stats ORDER BY sent_at DESC LIMIT 10;
