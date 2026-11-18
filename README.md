# Dorm-Management2

For existing installations, run `php scripts/migrate.php` after updates. The
announcement migration creates the missing announcement tables without replacing
existing announcements and upgrades the older floor-audience schema.

## Room allocation occupancy

The Rooms & Occupancy table is read-only. Add or move tenants through Tenant
Profiles and create reservations through Reservations. It displays occupied beds
and shows `Reserved / N` for the number of reserved beds. A room with occupied
beds is partially or fully occupied; any remaining reserved beds are shown
alongside that status. Maintenance and unavailable labels remain visible.
Manually reserved rooms remain flagged. This display does not change the room's
stored status or reservation records. Housekeeping users can view Room Allocation
but cannot transfer, vacate, or clear allocation history.

## Tenant acknowledgment receipts

When adding a tenant profile, select Next to review an acknowledgment receipt for one
month's advance and one month's deposit, each based on the selected room's monthly
rate. A 2x2 photo, phone number, and both contract dates are required, along with
the other profile fields; selecting a reservation is optional. Staff must confirm both amounts were received before creating the profile and
receipt. The room must have a positive monthly rate. The receipt number is assigned
on save, and its name, address, room, amounts, date, recipient, and bank
reference number are saved as receipt details. Ref No. is required for new tenant
profiles; older receipts without a reference remain available.
Staff can view or print it from Tenant Profiles; linked tenants can find it in My
Profile. An email is required when creating a tenant profile so its receipt is
automatically linked to a portal account. New accounts use the tenant name without spaces followed by `@123` as the
initial password; existing linked accounts keep their existing passwords.

The receipt records the confirmed move-in payment separately; it does not mark a
bill paid or apply the deposit to a bill. Existing installations must run
`php scripts/migrate.php` to add the receipt table. Existing profiles are not
automatically given receipts. Deleting an inactive tenant profile also deletes its
acknowledgment receipt.

Billing & Payments shows saved tenant and reservation acknowledgment receipts in
Payments history alongside bill payments, including existing receipts. A completed
reservation with an additional payment has one entry for the combined total,
linked to its completion receipt. Each entry links to its receipt; cancelled reservations remain
visible and do not imply a refund. This list shows the most recent 50 records.
Acknowledgments are separate from bill payments and do not affect bill balances
or the collected-this-month bill total. Deleting a tenant profile removes its
associated tenant acknowledgment from this history as well.

In the tenant portal, My Billing shows the signed-in tenant's bill payments and
linked acknowledgment receipts in My payment history. Completed reservations
appear once with the combined total; older balance-only receipts are identified
as legacy. These receipts do not reduce bill balances.

When approving a billing payment submission, staff select an existing bill for
that resident or explicitly create a bill for a selected period from the
submission. Staff choose the payment method and may enter a reference. Approval
records the payment once, updates the bill balance, and saves an invoice PDF and
a JPG copy of the screenshot under Tenant Files. The tenant can open the same
invoice PDF from Billing Payment; the bill and receipt appear in My Billing.
The PDF is a snapshot at approval. Previously approved submissions are not
automatically converted into bills or payments. Approval requires a linked
resident and, for new bills, the resident's current room. Rebuild the Docker app
after updating dependencies and run `php scripts/migrate.php` before using this
workflow. Non-Docker deployments require Composer dependencies, GD with JPEG
and WebP support, and web-server rules blocking direct access to billing uploads.

On Tenant Profiles, click an active status to mark the profile inactive. This
changes only the profile status; the account, bed assignment, and contract remain
unchanged. To reactivate a profile, use Edit and change its status to Active.

## Tenant profile folders

Staff can select **Profile folder** in Tenant Files, then open a tenant's folder.
The eight categories contain links to the tenant's existing profile, receipts,
bills, payments, contracts, violations and uploaded files. Staff can also upload
PDF, Word and image files to any category (up to 5 MB each). Receipts, invoices,
payment and violation records remain database-backed links; they are not duplicated
or converted into new financial records. Files from reports and repairs appear
under Other Documents. Access to the folder and manually uploaded files is limited
to administrators and admin staff; existing portal documents keep their own
authorization rules.

New linked uploads are stored in private per-tenant directories under
`secret/tenant-files`, with a stable name and unique profile ID. Profiles with the
same name have separate folders. Folder names do not change when a profile is
renamed. Files for accounts not linked to a tenant profile remain in their
existing locations until linked and migrated.

For an existing Docker installation, rebuild and restart the app so private
storage is writable, and run `docker compose exec app php scripts/migrate.php`.
Before moving old files, back up the database, uploads and the `private_references`
volume, and prevent users from changing records while the app container remains
available for the command. Run a dry run with
`docker compose exec -u www-data app php scripts/move-tenant-files.php`, then run
`docker compose exec -u www-data app php scripts/move-tenant-files.php --apply`.
The command copies and verifies each file before updating its record and removing
the original when no other record references it. It reports missing files and
unlinked records, and can be rerun after resolving errors. It does not create
invoices or payments for older approvals. Back up `private_references` with the
database and uploads going forward; it now contains tenant documents as well as
private reference data.

Non-Docker installations must provide a writable `secret/tenant-files` directory
for the web-server user and block direct web access to the entire `secret`
directory. Run the file migration as that same web-server user.

## Reservations

Staff can use Reservations to record a name, email, contact number, bank reference,
floor, and unit. Next opens an acknowledgment receipt for a manually entered
reservation payment. Staff must confirm receipt of the payment before saving.
Saving reserves one vacant bed and stores a printable receipt, without creating a
tenant account, contract, or billing payment. On the saved receipt, staff choose
Partial Payment to email the receipt or Full Payment to create a tenant profile and
account using the reserved bed and email the receipt. Staff decide the classification;
the system does not calculate or verify a full-payment threshold. The converted
receipt is also available on the tenant profile and in the tenant's My Profile.
If a partial payment was already recorded, completing the reservation requires a
separate additional amount and bank reference. It keeps the original partial
receipt unchanged and creates a differently numbered full-payment receipt showing
the combined total and the partial receipt's number, amount, and date. The new
receipt also shows the separate balance amount and bank reference. Staff must
verify the combined payments.
On a partial-payment receipt, select Create Profile to open Add Tenant Profile
with that reservation selected. Alternatively, choose an active reservation marked
Partial in Add Tenant Profile.
Its name, contact details, bank reference, and unit are filled from the reservation
and cannot be changed in that form. Enter the balance and its bank reference,
add a photo and both contract dates, then confirm the combined receipt.
Saving converts that reservation using its reserved bed, creates or links the
tenant account, creates a linked contract, and emails the full-payment receipt.
The original partial receipt remains available. A reservation that was cancelled,
converted, or whose reserved bed or unit changed cannot be converted from the form.
Previously issued additional-payment receipts retain their original balance-only
amount; payment history shows their combined total and labels them as legacy
balance receipts. Run `php scripts/migrate.php` before using this update.
No bill payment is created by conversion. Direct full-payment conversion of an
unclassified reservation from its receipt does not create a contract. Receipt and account welcome
emails can fail independently; failures are reported, and the receipt can be resent
from its page. The receipt number has an `AR-R` prefix and the address is the
dormitory address. Cancelling releases the bed and retains the payment receipt; it
does not record a refund. Run `php scripts/migrate.php` on existing installations
before using this page.

## Bank references

Administrators and admin staff can open Reference number from Billing & Payments.
Upload an `.xlsx` workbook of at most 2 MB; only the first worksheet is read.
Its first row must contain these five headings (in any order): Bank Name,
Account Name, Account Number, Reference Number, Date. Each row must contain
all five values. Format account and reference numbers as **Text** in Excel
before entering them, so leading zeroes and long numbers are retained; numeric
cells are rejected because Excel may already have changed the identifier.
Names must also be text. Dates must be real Excel date cells without a time,
or text in `YYYY-MM-DD` format. Formulas are not accepted. The first worksheet
is limited to 10,000 data rows and 20 columns, and the uncompressed workbook
to 30 MB. Search checks all five columns; dates are searched as YYYY-MM-DD.
Every upload appends all rows, including duplicates. If any row is invalid,
none of its rows are saved. Uploaded files are not retained.
Staff can permanently delete individual records or select records on the current
page and delete them together. Deletion affects only imported reference records,
not acknowledgment receipts or bill payments.

Existing databases need `php scripts/migrate.php`. Docker builds install the
locked spreadsheet dependencies outside the application bind mount; rebuild
the image after updating. For local PHP, enable ZIP, GD and mbstring, then run
`composer install --no-dev` using PHP 8.2 or newer. Keep `vendor` and Composer
files inaccessible to web clients (the included Apache Docker configuration
does this).
Run `php tests/reference_import.php` to check the workbook parser after installing
the dependencies.