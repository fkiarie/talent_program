# MIST Talent Program

PHP 8 + MySQL 8 system for registering children for talent activities, billing them, and collecting fees by M-Pesa.

## Install (new server)
1. Create the database and import `talent_program.sql`, then run every file in `sql/migrations/` in order
   (`001_mpesa_and_security.sql` is required; `002_backfill_first_month.sql` is optional, see the file).
   `003_pay_later_reservations.sql` adds the "Pay later" follow-up list; run it before uploading the pay-later pages.
2. Copy `.env.example` to `.env` and fill it in. `BASE_URL` must match the URL path exactly (`/talent_program` if the folder is called that).
3. Open `/setup_admin.php` once if there are no users yet. Delete the file afterwards.
4. Requirements: PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`. Apache with `.htaccess` enabled (it protects `.env`, `includes/`, `config/`, `sql/`).
   On nginx, add equivalent `deny` rules for those paths.

## Going live checklist
- `APP_DEBUG=false` (this also disables the M-Pesa simulator).
- Serve over HTTPS.
- Move `.env` outside the web folder if you can (change the path in `config/config.php`).
- Change any password that was ever used during testing.

## How money flows
- Registering a student adds a one-time **registration charge** (REGISTRATION_FEE, copied onto the charge).
- Enrolling in an activity (public form or staff) adds a **first-month charge** immediately. If a billing period already covers today it belongs to that period; otherwise it is a joining-month charge. Running a billing period never bills a month twice.
- The parent's checkout (`pay.php`) is built from what the database says is unpaid. The browser never sends an amount.
- `api/stk_push.php` asks M-Pesa for that amount. Safaricom calls `api/mpesa_callback.php`, which records the payment once (the M-Pesa code is unique) and **allocates** it to unpaid charges: registration fees first, then oldest first. A payment covering several children is split between them. Anything above what was owed stays on the payment as credit.
- `receipt.php` shows which charges a payment settled.

## M-Pesa setup
1. **Simulate** (default): `MPESA_MODE=simulate` with `APP_DEBUG=true`. Register a child, press pay, a fake receipt appears in ~5 seconds.
2. **Sandbox**: create an app at developer.safaricom.co.ke, put its key, secret, shortcode (174379) and the sandbox passkey in `.env`, set `MPESA_MODE=sandbox`.
   Safaricom must reach your callback, so use a public HTTPS URL (a tunnel such as ngrok works from your laptop) in `MPESA_CALLBACK_URL`, and set `MPESA_CALLBACK_SECRET`.
3. **Live**: after Safaricom approves your Go-Live, use your Paybill/Till, production key/secret and passkey, `MPESA_MODE=live`.

Callbacks carry no signature, so the endpoint is protected by the secret token in the URL, by only accepting prompts this system created, and by idempotent recording.
If a callback is lost, the prompt shows as "delayed" and the money will not appear in the system: reconcile against your M-Pesa statement and check the PHP error log (the full callback body is logged on errors).

## Frontend
Tailwind CSS is compiled to `assets/css/tailwind.css` (no CDN needed). After adding new Tailwind classes, rebuild:
`npm i -D tailwindcss@3 && npx tailwindcss -c tailwind.config.js -i assets/src/tailwind.input.css -o assets/css/tailwind.css --minify`

## Known limits / next steps
- Staff cannot yet record a cash or manual Paybill payment, or void a payment, from the screens (`payments.status` and `recorded_by` are ready for it).
- There is no parent login, so a returning parent cannot pay next month's fee online yet.
- Payments are stored against the first child of a family payment; allocations carry the real per-child split.
