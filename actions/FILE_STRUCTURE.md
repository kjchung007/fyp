# FCSIT Room Booking System - Complete File Structure

## Project Directory Tree

```
fcsit_booking/
│
├── 📄 README.md                    # Complete setup and usage guide
├── 📄 PROJECT_SUMMARY.md           # Implementation summary and checklist
│
├── 🔧 config.php                   # Database configuration & helper functions
├── 🎨 style.css                    # Main stylesheet for entire application
│
├── 📱 Frontend Pages (User Interface)
│   ├── login.php                   # User authentication page
│   ├── dashboard.php               # Main user dashboard with statistics
│   ├── browse_rooms.php            # Room browsing with filters
│   ├── room_details.php            # Detailed room information
│   ├── book_room.php               # Room booking form
│   ├── my_bookings.php             # User's booking history
│   └── report_issue.php            # Maintenance issue reporting
│
├── ⚙️ actions/                     # Backend Processing Scripts
│   ├── login_process.php           # Authentication processing
│   ├── logout.php                  # Session destruction
│   ├── submit_booking.php          # Booking submission with FCFS
│   ├── check_availability.php      # Real-time availability API (AJAX)
│   ├── cancel_booking.php          # Booking cancellation
│   └── submit_report.php           # Maintenance report submission
│
├── 👨‍💼 admin/                        # Admin Panel (To be expanded in FYP2)
│   └── dashboard.php               # Admin dashboard (placeholder)
│
└── 🗄️ database_schema.sql          # Complete MySQL database structure
```

---

## File Descriptions & Purposes

### 📄 Documentation Files

**README.md** (8KB)
- Installation guide (XAMPP setup)
- Database configuration
- Default login credentials
- Troubleshooting guide
- Feature overview

**PROJECT_SUMMARY.md** (12KB)
- Implementation checklist
- FYP report alignment
- Testing procedures
- Future development roadmap
- Technical specifications

---

### 🔧 Core Configuration

**config.php** (2.5KB)
```php
Purpose: Database connection and utility functions
Functions:
- Database connection (mysqli)
- sanitize_input() - XSS protection
- is_logged_in() - Session validation
- check_role() - RBAC implementation
- create_notification() - Notification system
- log_action() - Audit trail
```

**style.css** (8KB)
```css
Purpose: Complete styling for all pages
Includes:
- Responsive sidebar navigation
- Card layouts and grids
- Table styling with hover effects
- Form controls and buttons
- Status badges
- Alert messages
- Mobile responsiveness
- Loading animations
```

---

### 📱 Frontend Pages (User Interface)

#### **login.php** (4.6KB)
- Modern gradient design
- Email/password authentication
- Error message display
- Link to registration (future)
- Responsive layout

#### **dashboard.php** (7.7KB)
**Features:**
- Welcome message with user name
- Statistics cards (Total bookings, Pending, Notifications)
- Featured rooms grid with icons
- Recent bookings table
- Quick action buttons
- Real-time data from database

**SQL Queries:**
- User booking statistics
- Available rooms with facility count
- Recent booking history
- Unread notifications count

#### **browse_rooms.php** (7.4KB)
**Features:**
- Advanced filtering sidebar
  - Room type (lecture hall, tutorial, lab, meeting)
  - Minimum capacity
  - Required facilities
- Room grid with visual icons
- Facility count per room
- Quick book and details buttons
- Results count display

**SQL Queries:**
- Dynamic room filtering with JOIN
- Facility aggregation with GROUP_CONCAT
- Real-time availability checking

#### **room_details.php** (7.6KB)
**Features:**
- Comprehensive room information
- Statistics cards (Type, Capacity, Location, Status)
- Facility list with condition status
- Color-coded condition indicators
- Upcoming bookings table
- Large "Book This Room" CTA button

**SQL Queries:**
- Room details with prepared statements
- Room facilities with quantity/condition
- Upcoming approved bookings

#### **book_room.php** (9KB)
**Features:**
- Real-time conflict detection (AJAX)
- Date picker (min: today)
- Time slot selection
- Purpose description textarea
- Availability status display
- Booking guidelines sidebar
- Visual feedback for conflicts

**JavaScript:**
- checkAvailability() function
- AJAX call to check_availability.php
- Dynamic alert display
- Form validation

#### **my_bookings.php** (8.7KB)
**Features:**
- Statistics dashboard (Total, Pending, Approved, Rejected)
- Status filter dropdown
- Comprehensive booking table
- Status badges with colors
- Cancel button for pending/future bookings
- Admin remarks display for rejected bookings
- Pagination-ready structure

**SQL Queries:**
- User-specific bookings with room JOIN
- Booking count by status
- Filtered queries with dynamic WHERE

#### **report_issue.php** (11KB)
**Features:**
- Room selection dropdown
- Optional facility selection
- Issue type categories (4 types)
- Urgency level selector (4 levels)
- Detailed description textarea
- Guidelines and notes
- Recent reports history table
- Color-coded urgency display

**Issue Types:**
- Equipment fault
- Furniture damage
- Cleanliness
- Other

**Urgency Levels:**
- Low, Medium, High, Critical

---

### ⚙️ Backend Actions (Processing Scripts)

#### **login_process.php** (1.4KB)
```php
POST: email, password
Process:
1. Sanitize inputs
2. Query user from database
3. Verify password with password_verify()
4. Set session variables (user_id, name, email, role)
5. Log action to system_logs
6. Redirect based on role (admin → admin panel, user → dashboard)

Security:
- Prepared statements
- Password hashing verification
- Failed login logging
- Active status check
```

#### **logout.php** (0.2KB)
```php
Process:
1. Log logout action
2. Destroy session
3. Redirect to login page

Simple but secure session termination
```

#### **submit_booking.php** (3KB)
```php
POST: room_id, booking_date, start_time, end_time, purpose
Process:
1. Validate all inputs
2. Check date is not in past
3. Check end_time > start_time
4. Execute FCFS conflict detection algorithm
5. Insert booking with status='pending'
6. Create user notification
7. Log action
8. Redirect with success message

FCFS Algorithm Implementation:
- Complex SQL query with time overlap detection
- Checks for (pending OR approved) bookings
- Prevents double-booking
```

#### **check_availability.php** (1.4KB)
```php
GET: room_id, date, start_time, end_time
Returns: JSON
{
  "available": true/false,
  "message": "explanation"
}

Process:
1. Validate session
2. Sanitize inputs
3. Query database for conflicts
4. Return JSON response

Used by: book_room.php (AJAX call)
Real-time feedback to users
```

#### **cancel_booking.php** (1.6KB)
```php
GET: id (booking_id)
Process:
1. Verify booking belongs to user
2. Check if cancellation is allowed
3. Update status to 'cancelled'
4. Create notification
5. Log action
6. Redirect with message

Validation:
- User ownership check
- Status validation (can't cancel already cancelled/rejected)
```

#### **submit_report.php** (2.8KB)
```php
POST: room_id, facility_id, issue_type, urgency, description
Process:
1. Validate all required fields
2. Validate enum values
3. Insert maintenance report
4. If urgency=critical: Update room status to 'maintenance'
5. Create user notification
6. Notify all admin users
7. Log action
8. Redirect with success message

Smart Features:
- Auto room disabling for critical issues
- Admin notification system
- Audit trail
```

---

### 🗄️ Database Structure

**database_schema.sql** (8.3KB)

#### Tables Created (8 tables):

1. **users** (User accounts)
   - Fields: user_id, name, email, password, role, matric_no, phone, status
   - Indexes: email, role
   - Sample: 1 admin user

2. **rooms** (Room inventory)
   - Fields: room_id, room_name, room_type, capacity, building, floor, description, image_url, status
   - Indexes: room_type, status
   - Sample: 8 rooms (2 tutorial, 2 lecture halls, 2 labs, 2 meeting rooms)

3. **facilities** (Available facilities)
   - Fields: facility_id, facility_name, facility_type, description
   - Sample: 12 facilities (Projector, Whiteboard, Computer, AC, etc.)

4. **room_facilities** (Room-Facility mapping)
   - Fields: room_facility_id, room_id, facility_id, quantity, condition_status
   - Foreign keys: CASCADE delete
   - Sample: 40+ mappings

5. **bookings** (Booking records)
   - Fields: booking_id, user_id, room_id, booking_date, start_time, end_time, purpose, status, admin_remarks
   - Indexes: booking_date, status, user_id, room_id
   - Status: pending, approved, rejected, cancelled

6. **maintenance_reports** (Issue tracking)
   - Fields: report_id, room_id, facility_id, reported_by, issue_type, description, urgency, status
   - Indexes: status, room_id, urgency
   - Status: pending, in_progress, resolved, closed

7. **notifications** (User notifications)
   - Fields: notification_id, user_id, type, title, message, is_read
   - Indexes: user_id + is_read composite
   - Types: booking updates, maintenance alerts, system messages

8. **system_logs** (Audit trail)
   - Fields: log_id, user_id, action_type, table_affected, record_id, description, ip_address
   - Indexes: action_type, created_at
   - Tracks: All user actions with timestamps

---

## Code Statistics

| Category | Count | Total Lines |
|----------|-------|-------------|
| PHP Frontend Pages | 7 | ~1,400 |
| PHP Backend Actions | 6 | ~450 |
| Configuration | 1 | ~90 |
| CSS Styling | 1 | ~380 |
| Database SQL | 1 | ~180 |
| Documentation | 2 | ~500 |
| **TOTAL** | **18 files** | **~3,000 lines** |

---

## Technology Integration

### Frontend Stack
- **HTML5** - Semantic structure
- **CSS3** - Modern styling with flexbox/grid
- **JavaScript** - AJAX, form validation

### Backend Stack
- **PHP 7.4+** - Server-side logic
- **MySQL 8.0** - Relational database
- **Apache** - Web server

### Security Layer
- **bcrypt** - Password hashing
- **Prepared Statements** - SQL injection prevention
- **Input Sanitization** - XSS protection
- **Session Management** - Authentication
- **RBAC** - Role-based access

### Features
- **AJAX** - Real-time updates without page reload
- **FCFS Algorithm** - Fair booking system
- **Audit Logging** - Complete action history
- **Notification System** - Database-ready for email integration

---

## Quick Navigation

### For Students/Lecturers:
1. Start: `login.php`
2. Browse: `browse_rooms.php`
3. Book: `book_room.php`
4. Track: `my_bookings.php`
5. Report: `report_issue.php`

### For Administrators:
1. Dashboard: `admin/dashboard.php` (FYP2)
2. Process: Actions in database tables

### For Developers:
1. Setup: `README.md`
2. Database: `database_schema.sql`
3. Config: `config.php`
4. Backend: `actions/*.php`

---

## Installation Flow

```
1. Install XAMPP
   ↓
2. Start Apache + MySQL
   ↓
3. Create Database (phpMyAdmin)
   ↓
4. Import database_schema.sql
   ↓
5. Copy files to htdocs/fcsit_booking/
   ↓
6. Access http://localhost/fcsit_booking/login.php
   ↓
7. Login with admin@fcsit.unimas.my / admin123
   ↓
8. Start using the system!
```

---

## File Dependencies

```
login.php
  → actions/login_process.php
    → config.php
      → database

dashboard.php
  → config.php (auth check)
  → database (fetch stats)

browse_rooms.php
  → config.php
  → database (room query)
  → room_details.php (links)
  → book_room.php (links)

book_room.php
  → config.php
  → actions/check_availability.php (AJAX)
  → actions/submit_booking.php (form)

my_bookings.php
  → config.php
  → actions/cancel_booking.php (links)

report_issue.php
  → config.php
  → actions/submit_report.php (form)
```

---

**Document Version:** 1.1  
**Last Updated:** February 7, 2026  
**Total Project Size:** ~85KB (excluding database)  
**Estimated Setup Time:** 15-20 minutes
