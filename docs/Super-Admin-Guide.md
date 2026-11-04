# Heart Ladies Dormitory Management System
## Super Admin Guide — Operations Manual & Product Overview

*Modern, secure, all-in-one management for your ladies' dormitory.*

---

## 1. Why Heart Ladies Dormitory System?

Running a dormitory means juggling rooms, residents, contracts, billing, repairs, and supplies —
usually across notebooks, spreadsheets, and chat threads. This system puts everything in **one
professional web application** that works on desktop, laptop, tablet, and mobile.

**What you get:**
- 💗 **Room & bed management** — floors 3–6, Solo/Shared, Aircon/Non-Aircon, live occupancy bars.
  No more overbooking: the system blocks assignments beyond room capacity.
- 📄 **Contract control** — create, upload, download, and monitor contracts with automatic
  **30 / 15 / 7-day expiration warnings** so renewals never slip.
- ₱ **Billing & receipts** — one-click monthly billing, payment recording, and professional
  **printable invoices and official receipts** with your logo.
- 🛠 **Maintenance workflow** — residents report issues with photos; staff update status;
  residents are notified automatically.
- 📦 **Inventory tracking** — equipment, linen, and cleaning supplies with **low-stock alerts**
  (e.g. Bleach running below minimum).
- 🔔 **Built-in communication** — announcements, payment reminders, and emergency notices
  with read/unread tracking.
- 📊 **Reports & audit trail** — occupancy, collections, outstanding balances, and a full log
  of who did what, when.
- 🔐 **Role-based security** — Super Admin, Admin Staff, Housekeeping, and Resident accounts,
  each seeing only what they should.

> Perfect pitch line: *"From vacant bed to paid receipt — manage your entire dormitory
> in one pink-and-purple dashboard."*

---

## 2. Getting Started

1. Open the system URL (e.g. `http://localhost:8080` or your Hostinger domain).
2. Log in with your Super Admin account.
3. You land on the **Super Admin Dashboard**: total residents, rooms, vacant/occupied rooms,
   available beds, active & expiring contracts, outstanding payments, inventory alerts,
   and open repairs — plus charts for occupancy, income, payments, repairs, and inventory.

**First-day setup order:** Settings → Users & Staff → Rooms → Residents → Allocations →
Contracts → Billing. Each step is detailed below.

---

## 3. Daily, Weekly & Monthly Routines

**Daily (5 minutes):** Dashboard glance → open repair requests → unread notifications →
any payment recorded yesterday.

**Weekly:** review low-stock alerts → expiring contracts (Contact button) → overdue bills.

**Monthly:** Generate billing → record payments → print collection report → review audit logs.

---

## 4. Module-by-Module Manual

### 4.1 Users & Staff (`Users & Residents`)
- **Create accounts** for Admin Staff, Housekeeping, and Residents (name, email, password, role, phone).
- Creating a Resident auto-creates their resident profile.
- **Edit** details, **reset passwords**, **activate/deactivate** accounts.
- **Delete** only when the user has no active allocations, contracts, or unpaid bills
  (the system blocks unsafe deletes and explains why).
- Admin Staff can manage residents but **cannot** touch admin roles or critical settings.

### 4.2 Rooms (`Rooms`)
- Filter by floor (3rd–6th), Solo/Shared, Aircon/Non-Aircon, status, or search by room number.
- Each card shows occupancy (e.g. **2/4 Occupied**) with a visual bar, rate, and status:
  Available · Partially Occupied · Fully Occupied · Reserved · Under Maintenance · Unavailable.
- **View Details** lists every bed and occupant. **Create/Edit** rooms (beds auto-created).
- Mark rooms **Under Maintenance** to block bookings until repairs finish.

### 4.3 Room Allocation (`Room Allocation`)
- **Assign** residents to vacant beds — full rooms are rejected automatically
  ("Room is already fully occupied.").
- **Transfer** residents between rooms, **Vacate** on move-out, **Reserve** beds for incoming residents.
- The **history table** shows every move for accountability.

### 4.4 Contracts (`Contracts` + `Contract Monitoring`)
- Create contracts (number, resident, room, dates, rate, terms) and **upload** the signed file
  (PDF/DOC/JPG/PNG, max 5 MB). Replace, download, or archive anytime.
- Statuses compute automatically: Draft · Pending · Active · **Expiring Soon** · Expired · Terminated.
- The **monitoring page** + dashboard widget flag contracts expiring soon
  ("Maria Santos — Room 302 — Expires in 7 days") with a **Contact** button that opens
  a pre-addressed notification.

### 4.5 Billing & Payments (`Billing & Payments`)
- **Generate monthly billing**: pick the period, set utilities/defaults → one click bills
  every active resident (existing bills are skipped, never duplicated).
- **Record payments** (Cash/GCash/Bank): receipts auto-numbered (`OR-YYYYMM-XXXX`),
  balances and statuses (Unpaid / Partially Paid / Paid / Overdue) update instantly.
- Print professional **Invoices** and **Official Receipts** with logo, breakdown,
  and totals. Export CSV for accounting.

### 4.6 Inventory (`Inventory`)
- Categories: **Equipment** (bed, cabinet, curtain) · **Linen** (bedsheet, pillowcase,
  blanket, towel) · **Cleaning Supplies** (broom, rags, soap powder, bleach…).
- Track quantity, available/assigned/damaged, minimum stock, location, supplier, and cost.
- Transactions: Stock In/Out, Assigned, Returned, Damaged, Lost, Disposed — every movement logged.
- **Low-stock cards** warn you before supplies run out. Housekeeping sees stock but not costs.

### 4.7 Repairs (`Repairs`)
- Requests arrive with Report ID, resident, room, category, priority
  (Low/Medium/High/**Emergency**), description, and photo.
- **Assign** housekeeping staff, move through Reported → Assigned → In Progress → Completed.
- Residents are **notified automatically** on every status change. Full update history per request.

### 4.8 Notifications (`Notifications`)
- Send to **all residents**, a **role**, or a **specific person**; priorities Normal/Important/Urgent.
- Use for announcements, payment reminders, contract renewals, maintenance updates, emergencies.
- Unread badges and read/unread tracking included.

### 4.9 Reports (`Reports`)
One click each, all printable + CSV: occupancy, vacant rooms, resident list, contract
expiration, monthly billing, payment collection, outstanding balances, inventory,
low-stock, repair requests, maintenance history.

### 4.10 Audit Logs (`Audit Logs`)
Every important action recorded: who (user), what (action), where (module), which record,
when, and IP address. Filter by module, user, or date. *Example: "Admin Staff John —
Assigned Room 305 — 2026-10-09 14:02".*

### 4.11 Settings (`Settings`)
Dorm name, address, phone, default low-stock level, and contract warning days.
Changes apply system-wide (invoices, receipts, alerts).

---

## 5. Marketing One-Pager (copy-paste ready)

**Headline:** *Fill every bed. Miss nothing. Get paid on time.*

**For dormitory owners:** replace notebooks and spreadsheets with a dashboard that shows
occupancy, income, expiring contracts, and open repairs the second you log in.

**For administrators:** billing day becomes one click; receipts print with your logo;
contract renewals chase themselves with automatic warnings.

**For residents:** their own portal — room, contract, bills, receipts, repair requests
with photo upload, and instant status notifications.

**Trust points:** role-based access · password encryption · audit trail · automatic
backups via Docker · runs on affordable Hostinger hosting.

---

## 6. FAQ

**Can staff see everything I see?** No. Admin Staff can't access settings, audit logs, or
user roles; Housekeeping sees only operations; Residents see only their own records.

**What if a room is full?** The system refuses the assignment and tells you —
overbooking is technically impossible.

**How do residents get notified?** In-app notification center with unread badges,
triggered manually or automatically (repairs, reminders).

**Is our data safe?** Passwords are encrypted, uploads are access-controlled,
all input is validated, and every change is audit-logged.

---

*Heart Ladies Dormitory Management System · Crafted for safe, organized, ladies-only living.* 💗
