git pull origin claude/legal-code-rag-design-fYqkV

echo ------------------------------
echo Pushing New Changes from Remote Branch
git add .

echo ------------------------------
echo Committing changes...
set datetime=%date% %time%
git commit -m "from rcw-wac\ingestion %datetime%"

echo ------------------------------
echo Pushing to GitHub...
git push origin main