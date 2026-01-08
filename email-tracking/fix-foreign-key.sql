-- Option 1: Remove the foreign key constraint
-- This allows tracking to work without pre-recording emails in email_sent table
ALTER TABLE email_opens DROP FOREIGN KEY email_opens_ibfk_1;

-- Option 2 (Alternative): Auto-create email_sent record if it doesn't exist
-- We can do this in the PHP code with a trigger, but removing the constraint is simpler

-- After running Option 1, you can verify the constraint is gone:
SHOW CREATE TABLE email_opens;
