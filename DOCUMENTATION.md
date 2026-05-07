# TUNEDIK Alumni Management System
## Complete System Documentation

**Version:** 1.0  
**Platform:** PHP 8.0+ / MySQL 5.7+ / Bootstrap 5.3  
**URL:** alumni.wedev.com.ng

---

## TABLE OF CONTENTS

1. [System Overview](#1-system-overview)
2. [User Roles](#2-user-roles)
3. [Getting Started](#3-getting-started)
4. [Member Features](#4-member-features)
5. [Admin Features](#5-admin-features)
6. [Financial Secretary Features](#6-financial-secretary-features)
7. [NEC Member Features](#7-nec-member-features)
8. [System Settings](#8-system-settings)
9. [Security Features](#9-security-features)
10. [Membership ID Format](#10-membership-id-format)

---

## 1. SYSTEM OVERVIEW

TUNEDIK Alumni Management System is a web-based platform that manages the full lifecycle of alumni membership — from registration and payment, through community engagement, to elections and financial reporting. It is role-based, meaning different users see different features depending on their assigned role.

---

## 2. USER ROLES

The system has four roles:

| Role | Description |
|---|---|
| **Member** | Standard registered alumni. Can pay dues, view ID card, join forums, vote in elections, donate, and manage their profile. |
| **Admin** | Full system access. Manages all users, settings, content, payments, elections, committees, and reports. |
| **Financial Secretary** | Manages payment verification and financial reporting. Can approve/reject offline payments and export financial data. |
| **NEC Member** | National Executive Committee. Manages elections — creates positions, reviews applications, announces candidates, and views results. |

> A user can only have one role at a time. Roles are assigned by the Admin.

---

## 3. GETTING STARTED

### Registration
1. Visit the homepage and click **Register**
2. Fill in: First Name, Last Name, Email, Phone, State, Department, Graduation Year, Password
3. Accept the Terms & Conditions
4. Submit — a welcome email is sent

### First Login
1. Go to **Login** and enter your email and password
2. If Two-Factor Authentication (2FA) is enabled, enter the OTP sent to your email
3. You are redirected to your dashboard

### Paying for Membership
1. From your dashboard, click **Pay Dues** (or go to **Payments**)
2. Choose a payment method:
   - **Paystack** — pay online with card/bank transfer (instant activation)
   - **Bank Transfer** — upload a payment receipt for manual verification
3. On approval, your Membership ID is generated and your account is activated

---

## 4. MEMBER FEATURES

### 4.1 Dashboard
The member dashboard shows:
- Membership status (Active / Pending / Expired) with days remaining
- Quick navigation to all key sections
- Active election alerts (positions open to apply or vote)
- Profile completeness percentage
- 2FA security toggle
- Recent donation history

### 4.2 Profile Management
Members can update:
- **Personal Details** — name, phone, state, country, department, graduation year, matric number
- **Current Job Role / Occupation** — shown on ID card and member directory
- **Date of Birth** — used for birthday celebrations on the home page
- **Profile Picture** — JPEG/PNG/GIF/WebP, max 2MB
- **Signature** — used on the ID card (PNG/JPG/GIF/WebP, max 2MB)
- **Password** — change from current to new password

Profile completeness is tracked across 9 fields with a percentage indicator.

### 4.3 Membership ID Card
- Generates a digital credit-card-sized membership ID (85.6 × 54 mm)
- **Front:** Organisation logo, member photo, full name, job role, membership ID, email, department, phone, member signature, validity period
- **Back:** QR code (links to public member profile), organisation logo, back content, president's signature
- QR code links to a public profile page showing: full name, job role, membership ID, department, valid period — verifiable by anyone who scans it
- Printable in A4 portrait format

### 4.4 Member Directory
- Browse all active members
- Search by name or department
- Filter by state or graduation year
- Each card shows: name, photo, department, state, membership ID, job role

### 4.5 Announcements (Notices)
- View all published announcements from the administration
- Priority levels: **Normal**, **Important**, **Urgent** (colour-coded)
- Announcements may have expiry dates

### 4.6 Events
- View upcoming and past events
- RSVP to events (where RSVP is enabled)
- See event location, date, time, and RSVP count
- RSVP is limited by capacity (set by admin)

### 4.7 Forum
- Browse discussion categories
- Create new topics
- Reply to existing topics
- Like/unlike posts
- Delete your own posts

### 4.8 Elections
Members can:
- View all election positions and their current state
- **Apply** for a position (within the application window) — submit a manifesto
- **Vote** for a candidate (within the voting window) — one vote per position
- Track their own application status (Pending / Approved / Rejected)
- View approved candidates and their manifestos
- See vote counts during and after voting

> Only **active members** (with a paid, non-expired membership) can apply or vote.

Election states a position goes through:
1. **Upcoming** — not yet open
2. **Apply Now** — application window is open
3. **Under Review** — applications closed, NEC reviewing
4. **Candidates Announced** — approved candidates published
5. **Vote Now** — voting window is open
6. **Election Ended** — voting closed, results visible

### 4.9 Committees
- View all active committees and their members
- See committee purpose, duration, and chair contact
- View and download committee reports

### 4.10 Donations
- Browse active fundraising campaigns
- See campaign progress (amount raised vs target)
- Donate online via Paystack
- Option to donate anonymously
- View campaign deadline and remaining days

### 4.11 Payments History
- View a full history of all membership payment transactions
- See status (Pending / Approved / Rejected) and reference numbers

### 4.12 Birthday Celebrations
- On any member's birthday, their profile card is displayed on the home page
- Visible to all visitors (no login required)
- Shows: photo, name, job role, department, and a congratulatory message

---

## 5. ADMIN FEATURES

### 5.1 Admin Dashboard
Overview statistics:
- Total registered users
- Active members count
- Expired memberships count
- Pending payment approvals
- Total revenue collected
- Active committees count
- Recent payments table (10 latest)
- Members by state (top 10)
- Revenue trend chart (6 months)
- New member signups chart (6 months)

### 5.2 User Management
- View and search all registered users
- Change any user's role (Member, Financial Secretary, Admin, NEC Member)
- **Suspend** a user — enter a reason; suspended users cannot log in
- **Unsuspend** a user
- **Deactivate / Reactivate** a user account
- View user profile details inline

### 5.3 Membership Management
- View all memberships filtered by status: All / Active / Pending / Expired
- See membership ID, start date, expiry date, days remaining for every member
- Summary count cards at the top

### 5.4 Payment Management
- View all payments (online + offline)
- **Approve** offline/bank transfer payments → automatically activates the member's membership and generates their Membership ID
- **Reject** payments with optional notes
- **Clear disputed payments** — resolve previously rejected payments
- **Manual receipt upload** for dispute resolution

### 5.5 Financial Reports
- Filter by date range
- Total revenue (online vs offline split)
- Pending payments count
- Rejected payments count
- Donation campaign totals
- Member expiry alerts (members expiring within 30 days)
- Trigger manual stale-account cleanup

### 5.6 Announcements Management
- Create announcements with title, body, priority level
- Schedule publish date/time
- Set expiry date
- Delete announcements

### 5.7 Events Management
- Create events with title, description, date, location
- Enable/disable RSVP with optional capacity limit
- Delete events

### 5.8 Forum Management
- Create and manage forum categories
- Delete any post or topic (moderation)
- Pin topics

### 5.9 Committee Management
- Create committees with name, purpose, start/end dates
- Add members with roles: Chair, Vice-Chair, Secretary, Member
- Remove members from committees
- Mark committees as Completed or Dissolved
- Upload committee reports (PDF/document)
- Delete reports
- Delete empty committees

### 5.10 Donation Campaign Management
- Create campaigns: set title, description, target amount, deadline
- Assign to a specific member beneficiary or a named project
- Toggle donor name visibility
- Close / archive campaigns
- View all donation transactions per campaign

### 5.11 System Settings
*(See Section 8 for full details)*

### 5.12 Audit Log
- Full trail of all system actions
- Search by action type, user, or description
- Shows: user, action, description, IP address, timestamp
- Colour-coded action badges
- Paginated (30 per page)

### 5.13 Data Exports
- **Export Members** — CSV of all members with full details
- **Export Payments** — CSV of payment records (filterable by date and status)

---

## 6. FINANCIAL SECRETARY FEATURES

The Financial Secretary role focuses exclusively on financial operations.

### 6.1 Finance Dashboard
- Total revenue collected
- Pending payment approvals alert
- Online (Paystack) vs offline (bank transfer) payment split
- Recent transactions table

### 6.2 Payment Verification
- View all pending offline payments
- View uploaded receipt images
- **Approve** — activates the member's membership immediately
- **Reject** — records rejection with optional notes
- Filter by status and date range
- Email notification is sent to member on approval

### 6.3 Dispute Resolution
- Clear previously rejected payments
- Upload corrected receipts
- Manually activate memberships for cleared payments

### 6.4 Financial Reports
- Same reporting tools as Admin
- Date range filtering
- Revenue breakdown by payment method

### 6.5 Export
- Export all payment records to CSV

---

## 7. NEC MEMBER FEATURES

The NEC (National Executive Committee) manages the elections process.

### 7.1 NEC Dashboard
- Total election positions
- Pending applications requiring review
- Positions currently in voting
- Total votes cast across all elections

### 7.2 Election Position Management
- **Create** a new election position with:
  - Title and description
  - Application start and end dates/times
  - Voting start and end dates/times
- **Edit** position details and dates
- **Publish candidates** — makes approved candidates visible to members for voting
- **Delete** positions (if no votes cast)

### 7.3 Application Review
- View all applications per position
- See applicant's full name, membership ID, manifesto
- **Approve** an application — applicant becomes a candidate
- **Reject** an application — with optional reviewer notes
- Filter by position

### 7.4 Election Results
- View vote counts per candidate for all ended elections
- See winner (highest votes)
- **Notify winner** — sends congratulatory email to winning candidate
- Mark results as finalised

---

## 8. SYSTEM SETTINGS

Managed by Admin under **Admin → Settings**.

### Branding
| Setting | Description |
|---|---|
| Site Name | Organisation name shown across the system |
| Site Email | Contact email address |
| Site Phone | Contact phone number |
| Site Address | Physical address |
| Logo | Upload site logo (shown in navbar and on ID card) |
| Favicon | Upload browser tab icon |
| Developer URL | Link shown in site footer |

### Social Media
| Setting | Description |
|---|---|
| Facebook URL | Link to Facebook page |
| Twitter URL | Link to Twitter/X profile |
| Instagram URL | Link to Instagram page |
| LinkedIn URL | Link to LinkedIn page |
| WhatsApp Number | WhatsApp contact number |

### Payment Configuration
| Setting | Description |
|---|---|
| Paystack Public Key | From your Paystack dashboard |
| Paystack Secret Key | From your Paystack dashboard |
| Bank Name | For offline bank transfer instructions |
| Account Number | Bank account number |
| Account Name | Bank account name |
| Membership Fee | Annual fee in Naira (default: 5,000) |

### Email Templates
Customisable templates for:
- Welcome email (on registration)
- Password reset email
- Two-factor authentication (OTP) email
- Payment approval email

### ID Card
| Setting | Description |
|---|---|
| Back Content | Text printed on the back of the ID card |
| President's Signature | Upload an image of the president's signature |

### Constitution
- Upload the organisation's constitution as a PDF

---

## 9. SECURITY FEATURES

| Feature | Detail |
|---|---|
| **Password Hashing** | Bcrypt with cost factor 12 |
| **CSRF Protection** | Token on every form submission |
| **Rate Limiting** | 5 failed login attempts per 15 minutes (per email and IP) |
| **Two-Factor Authentication** | Email OTP, 6 digits, expires in 10 minutes |
| **Session Security** | Session ID regenerated on login; HttpOnly cookies |
| **Password Reset** | Tokenised link expires in 1 hour |
| **File Upload Validation** | MIME type checked, not just extension |
| **SQL Injection Prevention** | All queries use PDO prepared statements |
| **Account Suspension** | Admin can lock accounts with a reason on record |
| **Audit Trail** | Every admin action logged with IP address and timestamp |
| **Concurrency Protection** | Database transactions + SELECT FOR UPDATE on membership ID generation and voting |
| **Automatic Cleanup** | Accounts pending >7 days with no payment are deleted automatically (runs once per hour) |

---

## 10. MEMBERSHIP ID FORMAT

Every activated member receives a unique Membership ID in the format:

```
08/TUN/XXX/0001
```

| Segment | Meaning |
|---|---|
| `08` | Year code |
| `TUN` | Organisation code |
| `XXX` | 3-letter code for the member's Nigerian state (e.g. LAG = Lagos, ABJ = Abuja) |
| `0001` | Sequential serial number per state |

- IDs are generated automatically on payment approval
- The serial number increments independently per state
- IDs are permanent and never reused

---

## QUICK REFERENCE — WHO CAN DO WHAT

| Feature | Member | Financial Sec. | NEC Member | Admin |
|---|:---:|:---:|:---:|:---:|
| Register & Login | Yes | Yes | Yes | Yes |
| View Dashboard | Yes | Yes | Yes | Yes |
| Update Profile | Yes | Yes | Yes | Yes |
| Pay Membership | Yes | Yes | Yes | Yes |
| View ID Card | Yes | Yes | Yes | Yes |
| View Directory | Yes | Yes | Yes | Yes |
| View Announcements | Yes | Yes | Yes | Yes |
| View Events & RSVP | Yes | Yes | Yes | Yes |
| Use Forum | Yes | Yes | Yes | Yes |
| Vote in Elections | Yes | Yes | Yes | Yes |
| Apply for Elections | Yes | Yes | Yes | Yes |
| View Committees | Yes | Yes | Yes | Yes |
| Donate | Yes | Yes | Yes | Yes |
| Approve Payments | — | Yes | — | Yes |
| Export Payments CSV | — | Yes | — | Yes |
| Financial Reports | — | Yes | — | Yes |
| Manage Election Positions | — | — | Yes | Yes |
| Review Applications | — | — | Yes | Yes |
| Notify Election Winners | — | — | Yes | Yes |
| Manage Users & Roles | — | — | — | Yes |
| Suspend Users | — | — | — | Yes |
| Manage Announcements | — | — | — | Yes |
| Manage Events | — | — | — | Yes |
| Manage Committees | — | — | — | Yes |
| Manage Donations | — | — | — | Yes |
| System Settings | — | — | — | Yes |
| View Audit Log | — | — | — | Yes |
| Export Members CSV | — | — | — | Yes |

---

*Documentation generated for TUNEDIK Alumni Management System v1.0*  
*Hosted at alumni.wedev.com.ng*
