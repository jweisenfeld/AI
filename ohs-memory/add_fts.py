import psycopg2, os
from dotenv import load_dotenv
load_dotenv()

conn = psycopg2.connect(os.environ['DATABASE_URL'])
conn.autocommit = True
cur = conn.cursor()

print('Adding fts column (this may take 15-30 seconds)...')
cur.execute("""
    ALTER TABLE chunks
    ADD COLUMN IF NOT EXISTS fts tsvector
    GENERATED ALWAYS AS (to_tsvector('english', content)) STORED
""")
print('Creating GIN index...')
cur.execute("CREATE INDEX IF NOT EXISTS chunks_fts_gin ON chunks USING GIN (fts)")
print('Done.')
conn.close()
