# Student Portal

## Student registration setup

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Import `database/schema.sql` using phpMyAdmin. This creates the `lcupay` database and its `students` table.
3. Check that the database credentials in `includes/config.php` match your MySQL setup.
4. Complete the admin setup below and create at least one active fee type.
5. Open [http://localhost/registrar-frontend/register.php](http://localhost/registrar-frontend/register.php) and create an account. Registration assigns each active fee type at its default amount, due 30 days after signup, and saves the student and charges together before redirecting to sign in.

## Admin setup

1. Import `database/admin_schema.sql` into the existing `lcupay` database using phpMyAdmin.
2. From the project root, run `C:\xampp\php\php.exe database\seed_admin.php` in a terminal and follow the prompts to create an administrator account.
3. Sign in at [http://localhost/registrar-frontend/admin/login.php](http://localhost/registrar-frontend/admin/login.php) and create/activate fee types before student registration.

## Admin pages

- `admin/index.php`: dashboard totals and the five most recent payments.
- `admin/students.php`: search and browse students and their outstanding balances.
- `admin/student-add.php`: create a student account with a temporary password.
- `admin/student-view.php`: view a student's charges and payments, change account status, or reset the temporary password.
- `admin/fee-types.php`: add, edit, activate, or deactivate fee types.
- `admin/charges.php`: assign charges to students and edit or remove charges that have no payments.
- `admin/payments.php`: filter and export payment records.
- `admin/record-payment.php`: record an over-the-counter cash payment.
- `admin/payment-success.php`: display the reference for a successfully recorded cash payment.
- `admin/logout.php`: end the administrator session.
