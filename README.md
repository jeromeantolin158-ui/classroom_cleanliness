# SpotLess — Classroom Cleanliness Rating

Section accounts time in/out of a room and rate how clean the previous class left it.
Admins manage rooms, sections, schedules and users, and read ratings, reports and analytics.

**Stack:** PHP 8.3 · PostgreSQL (PDO) · vanilla JS/CSS · works on phones.

## Features
- Section accounts: time in / time out, rate the room left by the previous section (1–5 stars)
- Rules enforced in the database: one rating per class, no overlapping schedules, valid time ranges
- Admin: rooms with live status, sections, schedules, users, latest ratings, weekly report
- Email: missed time-in reminders (PHPMailer + Gmail SMTP), logged so nobody is emailed twice
- Analytics: weekly report with room ranking (cleanest / needs attention), building performance, daily trend
- Security: CSRF tokens, hashed passwords, forced password change for temporary passwords, admin-approved password resets

## Setup
1. Create the PostgreSQL database and run your schema.
2. Copy `db.example.php` to `db.local.php` and fill in a **limited** DB user (never `postgres`).
3. Run `email.sql`, then copy `mail.example.php` to `mail.local.php` (Gmail app password) to enable emails.
4. Serve the folder with Apache/XAMPP or `php -S localhost:8000`.

## Files
| File | Purpose |
|---|---|
| `db.php` | connection + login/CSRF helpers |
| `lib.php` | shared helpers (`require_admin`, `require_student`, `week_bounds`, `icon`) |
| `header*.php`, `footer*.php` | page frame (admin / student) |
| `dashboard.php` | picks `dashboard_admin.php` or `dashboard_student.php` |
| `mailer.php`, `send_reminders.php` | email sending and the reminder job (`php send_reminders.php` or the Rooms page button) |
| `app.css`, `app.js` | all styles and scripts for signed-in pages |
| `login.css` | styles for login / forgot password |
