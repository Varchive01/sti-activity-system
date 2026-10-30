# STI-ARMES: Initial Administrator Setup Guide (InfinityFree / phpMyAdmin)

When deploying STI-ARMES to shared hosting environments without SSH/CLI access (such as InfinityFree), the first administrator account can be created securely using an idempotent SQL snippet in phpMyAdmin.

---

## 1. Why a Pre-Committed SQL Seed Is Not Used
PHP applications authenticate passwords using `password_hash()` (bcrypt with a cryptographic salt). MySQL does not have a native bcrypt generation function. Embedding a pre-computed password hash into a public or committed SQL file would expose a shared credential in source control.

To remain secure, the temporary password and its matching bcrypt hash are generated locally on the administrator's computer using PHP's native `random_bytes()` and `password_hash()`.

---

## 2. Generating the phpMyAdmin SQL Snippet

On your local workstation or development machine, run:

```bash
php scripts/init_admin.php --sql --name="Dean Administrator" --email="dean@sti.edu"
```

The script will:
1. Generate a high-entropy 16-character one-time temporary password and display it once in your local terminal.
2. Hash the temporary password with `password_hash(..., PASSWORD_DEFAULT)`.
3. Output a ready-to-run SQL snippet formatted as follows:

> [!WARNING]
> **CRITICAL TEMPORARY PASSWORD SECURITY:**
> - **Never commit** the temporary password or generated SQL snippet with real hashes to Git or public repositories.
> - **Never transmit** the temporary password through unencrypted communication channels (e.g. unencrypted chat, public forums, plain email) or save it in shared notes.
> - **Store it securely** (e.g. in your local password manager) only until the initial login is completed.
> - **Log in immediately** after running the SQL query and complete the forced permanent password change.

```sql
INSERT INTO `users` (`name`, `email`, `password`, `role`, `must_change_password`, `department`)
SELECT 'Dean Administrator', 'dean@sti.edu', '<GENERATED_BCRYPT_HASH>', 'dean', 1, 'Administration'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `users` WHERE `role` IN ('dean', 'admin2')
);
```

---

## 3. Applying the Snippet in phpMyAdmin
1. Open the InfinityFree Control Panel and launch **phpMyAdmin**.
2. Select your imported STI-ARMES database.
3. Click the **SQL** tab.
4. Paste the generated SQL snippet and click **Go**.
5. **Idempotency Guarantee**: If an administrator or Dean account already exists in the database, `WHERE NOT EXISTS` ensures that **0 rows are inserted**. Existing administrators cannot be overwritten or hijacked.

---

## 4. First Login & Forced Password Change
1. Navigate to your deployed STI-ARMES login page: `https://<your-domain>/auth/login.php`.
2. Sign in using the email and the one-time temporary password.
3. Because `must_change_password = 1`, the system immediately redirects to `/auth/change-password.php`.
4. Choose and confirm a new permanent password ($\ge 6$ characters).
5. The account is updated with the new hash and `must_change_password = 0`, granting access to the Dean dashboard.

