@echo off
setlocal

rem ── Move to repo root (this script lives two levels down: rcw-wac\ingestion) ──
cd /d "%~dp0..\.."
echo Repo root: %CD%
echo.

rem ── Pull changes from Claude Code feature branch ──────────────────────────────
echo Pulling from claude/legal-code-rag-design-fYqkV...
git pull origin claude/legal-code-rag-design-fYqkV
if errorlevel 1 (
    echo.
    echo STOPPED: pull failed or merge conflict detected.
    echo Resolve any conflicts manually, then run:
    echo   git add . ^&^& git commit -m "resolved"
    exit /b 1
)

rem ── Guard: check for leftover conflict markers before staging ─────────────────
git diff --check >nul 2>&1
if errorlevel 1 (
    echo.
    echo STOPPED: unresolved conflict markers found in working tree.
    echo Fix all conflicts, then re-run this script.
    exit /b 1
)

rem ── Stage all changes ─────────────────────────────────────────────────────────
echo.
echo ------------------------------
echo Staging...
git add .

rem ── Commit only if there is something staged ──────────────────────────────────
echo.
echo ------------------------------
git diff --cached --quiet
if errorlevel 1 (
    echo Committing...
    set datetime=%date% %time%
    git commit -m "sync from rcw-wac\ingestion %datetime%"
    if errorlevel 1 (
        echo ERROR: commit failed.
        exit /b 1
    )
) else (
    echo Nothing new to commit.
)

rem ── Push to main ──────────────────────────────────────────────────────────────
echo.
echo ------------------------------
echo Pushing to main...
git push origin main
if errorlevel 1 (
    echo ERROR: push failed.
    exit /b 1
)

echo.
echo Done.
