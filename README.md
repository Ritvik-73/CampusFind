# CampusFind

CampusFind is a PHP + MySQL lost-and-found site for the DBMS end-semester project. It follows the supplied Context.dev direction: white space, clear blue actions, quiet borders, compact pill buttons, and a small dotted motif.

## Run with XAMPP

1. Copy the project folder to C:\xampp\htdocs\campusfind.
2. Start Apache and MySQL in XAMPP Control Panel.
3. Open http://localhost/phpmyadmin, choose Import, and import database.sql.
4. Visit http://localhost/campusfind.

The default database login is root with a blank password. If your local MySQL password differs, edit db.php.

## What works

- Search found items and filter by category.
- Submit lost and found reports. Item and report inserts are grouped in transactions.
- Link a lost report and found report through the composite primary key in item_match.
- Submit a claim and review it. Approved claims create verification and handover records.
- View status summaries and recent handovers in the project desk.

All form values that go to SQL use PDO prepared statements. database.sql creates the normalized tables, keys, foreign keys, checks, and fictional starter rows. The project desk is a classroom demo and does not implement sign-in or evidence uploads.
