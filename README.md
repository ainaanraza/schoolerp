# School ERP (PHP + MySQL)

Starter implementation for a multi-role School ERP with role-based portal structure.

## Roles

- Super Admin
- Admin
- Teacher
- Student
- Parent

## Implemented Foundation

- Clean folder structure by portal and module
- Login/logout flow with role-based redirection
- Route-level role guards
- Shared layout files
- Core module entry points (attendance, fees, admission, academics, notifications)
- MySQL schema covering:
  - users, students, parents, teachers
  - classes, subjects, homework
  - leads/admission
  - attendance
  - fees, payments, fee adjustments
  - notifications
  - documents

## Phase 2 (Admission Workflow) Implemented

- Public admission form for applicants at `modules/admission/apply.php`
- Admin lead pipeline at `modules/admission/leads.php`
   - Create lead manually
   - Track status (`new` → `contacted` → `approved` / `rejected`)
   - Enroll approved lead
   - Auto-generate Student and Parent login credentials on enrollment
- Student listing page now reads real DB data at `modules/admission/students.php`

## Phase 3 (Fee Engine) Implemented

- Fee structure management at `modules/fees/fee_structure.php`
   - Create class-wise fee structures
   - Configure billing cycle (`monthly`, `quarterly`, `half_yearly`, etc.)
   - Generate period-wise student fee rows (e.g. `Apr-2026`) for enrolled students
- Fee payment flow at `modules/fees/student_fees.php`
   - Role-aware visibility (Admin/Super Admin see all, Student sees own, Parent sees linked children)
   - Post payments and auto-update status (`pending`, `partial`, `paid`)
   - Payment history shown per fee row
- Super Admin controls at `modules/fees/superadmin_finance.php`
   - Finance summary (total payable/paid/pending)
   - Billing-cycle analytics
   - Discount and override actions with adjustment logs

### Admin Course Fee Setup (Updated)

- Admin and Super Admin dashboards include direct card to `Fee Structure`
- `modules/fees/fee_structure.php` supports adding fees for a particular course (class-section):
   - choose class/section in "Particular Course (Class/Section)"
   - create fee structures and generate student fees for selected period
   - view full fee structure details

## Phase 4 (Attendance APIs + Screens) Implemented

- Teacher attendance marking at `modules/attendance/mark.php`
   - Class/date selection
   - Student-wise status marking (`present`, `absent`, `late`, `leave`)
   - Bulk save with update on re-submit
- Admin attendance control at `modules/attendance/manage.php`
   - Filter by class and date
   - Edit existing attendance rows and remarks
- Role-based attendance summary at `modules/attendance/view.php`
   - Date range filters
   - Class filter for Admin/Super Admin/Teacher
   - Student sees own records, parent sees linked children
- Attendance JSON API at `modules/attendance/api.php`
   - Supports date range and optional class filtering
   - Returns summary + rows with role-based access control

## Phase 5 (Notification Persistence) Implemented

- Notification sender at `modules/notification/send.php`
   - Stores notifications in `notifications` table
   - Supports `all`, `role`, `class`, `user` target scopes
   - Role permissions enforced:
      - Super Admin: all scopes
      - Admin: role/class/user
      - Teacher: class (assigned classes only)
- Notification inbox at `modules/notification/inbox.php`
   - Reads notifications from DB with role-aware delivery filtering
   - Supports read tracking via `notification_reads`
   - Shows unread count and mark-as-read action

## Phase 6 (Excel Exports) Implemented

- Finance Excel export at `modules/fees/export_excel.php`
   - Exports fee rows with payable/paid/outstanding fields
   - Role-aware data scope (Admin/Super Admin all, Student own, Parent linked children)
   - Download format: `.xls` (Excel compatible)
- Attendance Excel export at `modules/attendance/export_excel.php`
   - Exports attendance rows using active date/class filters
   - Role-aware data scope (Student own, Parent linked children)
   - Download format: `.xls` (Excel compatible)

## Phase 7 (Parent-Child Linking UX) Implemented

- Admin linking panel at `modules/admission/parent_linking.php`
   - Link parent and student with relation type
   - Update existing relation and unlink records
   - View complete parent-child mapping list
- Parent children view at `parent/children.php`
   - Shows all linked children with admission number, class, and session
   - Supports one parent linked to multiple children

## Phase 8 (Dashboard Analytics Widgets) Implemented

- Super Admin dashboard widgets at `superadmin/dashboard.php`
   - Active user counts, lead funnel, fee pending summary, attendance today
- Admin dashboard widgets at `admin/dashboard.php`
   - Leads summary, enrolled students, fee paid/pending totals, attendance today
- Teacher dashboard widgets at `teacher/dashboard.php`
   - Assigned classes/students, attendance marked today, homework and notifications count
- Student dashboard widgets at `student/dashboard.php`
   - Attendance %, pending fees, homework count, unread notifications
- Parent dashboard widgets at `parent/dashboard.php`
   - Linked children count, pending fees, absent alerts today, unread notifications

## Phase 9 (Payment Gateway Integration - Sandbox Ready) Implemented

- Payment gateway config at `config/payment.php`
   - Provider, currency, sandbox mode, and merchant keys via env vars
- Dedicated checkout flow at `modules/fees/checkout.php`
   - Role-aware access to fee records
   - Transaction reference generation and atomic payment posting
   - Updates fee status (`pending` / `partial` / `paid`) after payment
- Fee page integration at `modules/fees/student_fees.php`
   - `online_gateway` method now redirects to checkout flow
   - Shows payment success feedback after completion

### Razorpay Setup (Environment Variables)

Set these in your web server environment:

- `PAYMENT_PROVIDER=razorpay`
- `PAYMENT_CURRENCY=INR`
- `PAYMENT_SANDBOX=1`
- `PAYMENT_MERCHANT_NAME=Your School Name`
- `PAYMENT_PUBLIC_KEY=rzp_test_xxxxx`
- `PAYMENT_SECRET_KEY=xxxxxxxx`

If Razorpay keys are not configured, checkout automatically falls back to sandbox confirmation flow.

## Setup

1. Create database and tables using:

   - `database/schema.sql`

   If you already created DB from older schema, run migration:

   - `database/migrations/2026_04_02_phase2_admission.sql`

2. Configure DB credentials in:

   - `config/db.php`

3. Start a PHP server from project root `school-erp`:

   - `php -S localhost:8000`

4. Open:

   - `http://localhost:8000/index.php`

## Seed User Note

You need at least one user in `users` with a valid `password_hash` generated by `password_hash()`.

Example in PHP shell:

```php
<?php
echo password_hash('Admin@123', PASSWORD_DEFAULT);
```

Then insert user manually into the `users` table with one of the supported roles.

## Demo Credentials (All Roles)

Import once:

- `database/seed_demo_users.sql`

Login users:

- Super Admin: `superadmin@demo.local` / `Super@123`
- Admin: `admin@demo.local` / `Admin@123`
- Teacher: `teacher@demo.local` / `Teacher@123`
- Student: `student@demo.local` / `Student@123`
- Parent: `parent@demo.local` / `Parent@123`

## Next Build Steps

1. Stripe API integration (optional second gateway)
2. PDF report export (Finance + Attendance)
3. Notification delivery channels (email/SMS/push)
4. Scheduled automated report emails
5. Multi-school branch-level reporting
