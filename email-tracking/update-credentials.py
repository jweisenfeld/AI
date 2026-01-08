"""
Quick script to update database credentials in all PHP files

Run this script and it will update track.php, dashboard.php, and api/record_sent.php
with your database credentials.
"""

import os

# YOUR CREDENTIALS (update these!)
DB_NAME = 'fikrttmy_email_tracking'
DB_USER = 'fikrttmy_tracker'
DB_PASS = 'm}^KBykDn5r]'  # Replace with the full password that starts with m}^K
DASHBOARD_PASS = 'physics2026'  # Change this to something more secure!

# Files to update
script_dir = os.path.dirname(os.path.abspath(__file__))
files_to_update = [
    os.path.join(script_dir, 'track.php'),
    os.path.join(script_dir, 'dashboard.php'),
    os.path.join(script_dir, 'api', 'record_sent.php')
]

def update_file(filepath):
    """Update database credentials in a PHP file"""
    print(f"Updating {os.path.basename(filepath)}...")

    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Replace database name
    content = content.replace("'flkrttmy_email_tracking'", f"'{DB_NAME}'")
    content = content.replace("'fikrttmy_email_tracking'", f"'{DB_NAME}'")  # In case it's already updated

    # Replace database user
    content = content.replace("'flkrttmy_tracker'", f"'{DB_USER}'")
    content = content.replace("'fikrttmy_tracker'", f"'{DB_USER}'")  # In case it's already updated

    # Replace database password
    content = content.replace("'YOUR_DB_PASSWORD_HERE'", f"'{DB_PASS}'")
    content = content.replace("'REPLACE_WITH_YOUR_FULL_PASSWORD'", f"'{DB_PASS}'")

    # Replace dashboard password (only in dashboard.php)
    if 'dashboard.php' in filepath:
        content = content.replace("'physics2026'", f"'{DASHBOARD_PASS}'")

    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)

    print(f"  ✓ Updated successfully")

if __name__ == '__main__':
    print("=" * 60)
    print("DATABASE CREDENTIAL UPDATER")
    print("=" * 60)
    print()
    print(f"Database: {DB_NAME}")
    print(f"User:     {DB_USER}")
    print(f"Password: {DB_PASS}")
    print(f"Dashboard Password: {DASHBOARD_PASS}")
    print()

    if DB_PASS == 'PASTE_YOUR_FULL_PASSWORD_HERE':
        print("ERROR: You need to edit this script and add your password first!")
        print("Edit line 11 and replace 'PASTE_YOUR_FULL_PASSWORD_HERE' with your actual password")
        input("\nPress Enter to exit...")
        exit(1)

    confirm = input("Update all PHP files with these credentials? (yes/no): ")

    if confirm.lower() in ['yes', 'y']:
        print()
        for filepath in files_to_update:
            if os.path.exists(filepath):
                update_file(filepath)
            else:
                print(f"Warning: {filepath} not found, skipping...")

        print()
        print("=" * 60)
        print("✓ ALL FILES UPDATED!")
        print("=" * 60)
        print()
        print("Next steps:")
        print("1. git add email-tracking/")
        print("2. git commit -m 'Add email tracking with credentials'")
        print("3. git push origin main")
        print()
    else:
        print("Cancelled.")
