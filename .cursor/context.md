# Project Context

Project name: wbss

This project is a PHP + MariaDB web application used for store management.

Main domains:
- attendance
- cashier
- orders
- customers
- events

---

# Architecture

Directory structure:

app/
Contains domain logic and shared modules.

public/
HTTP entrypoints (pages).

public/api/
AJAX endpoints and API handlers.

Database:
MariaDB

---

# Important Modules

Attendance module
Files:
- app/attendance.php
- public/attendance/index.php
- public/api/attendance_clock_in.php
- public/api/attendance_clock_out.php
- public/api/attendance_toggle.php

Key table:
attendances

Important concepts:
- business_date
- store_id
- clock_in / clock_out
- is_late
- note

This module is sensitive because it affects daily reports.

---

# Database Overview

Main tables:

users
user_roles
roles
stores
attendances
orders
customers

Rules:
- attendances records are keyed by
  store_id + user_id + business_date

---

# Store Handling

There are two types of store resolution:

1. Admin / manager context
   store selected in UI or session

2. Cast context
   store determined from user role or cast profile

These must not be mixed.

---

# Business Date

business_date is calculated based on store.business_day_start.

Example:

business_day_start = 06:00

03:00 -> previous day
20:00 -> same day

Consistency is critical.

---

# Development Rules

- Do not break existing API responses.
- Prefer minimal changes.
- Avoid large rewrites.
- Maintain backward compatibility.
- Refactor incrementally.

---

# Risk Areas

The following parts require careful changes:

- attendance database updates
- store_id resolution
- business_date calculation
- CSRF validation
