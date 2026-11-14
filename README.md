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
Needs PHP 8.3 and PostgreSQL 15 or newer.
1. Create an empty database `classroom_cleanliness` and run `database/schema.sql` in it.
2. Run `database/create_admin.sql` (first login: `admin` / `ChangeMe123`, you must change it).
3. Optional demo data: run `database/seed_demo.sql` (section accounts use the password `Password123`).
4. Create the limited database user (commands at the end of `schema.sql`), never use the `postgres` superuser.
5. Copy `db.example.php` to `db.local.php` and fill in that user.
6. Optional emails: copy `mail.example.php` to `mail.local.php` (Gmail app password) and set `enabled` to `true`.
7. Serve the folder with Apache/XAMPP or `php -S localhost:8000`.

## Files
| File | Purpose |
|---|---|
| `database/` | `schema.sql`, `create_admin.sql`, `seed_demo.sql` |
| `db.php` | connection + login/CSRF helpers |
| `lib.php` | shared helpers (`require_admin`, `require_student`, `week_bounds`, `icon`) |
| `header*.php`, `footer*.php` | page frame (admin / student) |
| `dashboard.php` | picks `dashboard_admin.php` or `dashboard_student.php` |
| `mailer.php`, `send_reminders.php` | email sending and the reminder job (`php send_reminders.php` or the Rooms page button) |
| `app.css`, `app.js` | all styles and scripts for signed-in pages |
| `login.css` | styles for login / forgot password |
