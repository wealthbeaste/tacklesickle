# TSCA Registry — Authentication, Roles & Access Control

## Scope

Implement ONLY the Registry authentication, role-based access control, and migration from the current shared-secret-only access flow.

The previous Registry implementation is already successful. Do NOT rebuild unrelated Registry features.

The client requirements explicitly specify:
- Data Entry Personnel — Input and edit
- Supervisor — Review and validate
- Administrator — Full access and system configuration

The client also specifies secure login/password and controlled access to sensitive Registry information.

---

## 1. Current problem

The current Registry is accessed through a shared secret key:

Visitor → Registry page → Secret Key → Registry Dashboard

This must NOT remain the normal production login mechanism because it does not identify individual personnel and cannot enforce different permissions.

---

## 2. Required final flow

Implement this architecture:

Public TSCA Website
        ↓
Registry Login
        ↓
Username/Email + Password
        ↓
Backend Authentication
        ↓
Identify User
        ↓
Determine Role
   ┌────┼──────────────┐
   ↓    ↓              ↓
Data Entry  Supervisor  Administrator
   ↓        ↓              ↓
Input/Edit Review/Validate Full Access/
                           Configuration

The backend must enforce permissions. Frontend role checks are only for UI.

---

## 3. Keep the existing secret key — but change its purpose

Do NOT blindly delete the existing Registry secret key.

Convert it into a secure BOOTSTRAP mechanism for creating the first Administrator.

Required flow:

Initial setup
→ Existing Registry Admin Secret
→ Server verifies secret
→ Create first Administrator account
→ Password securely hashed
→ Administrator logs in normally
→ Administrator creates other personnel accounts

After the first Administrator exists, personnel use individual accounts.

The secret key must NOT become everyone's password.

The secret must:
- remain server-side
- come from environment configuration
- never be hardcoded in frontend JavaScript
- never be returned by an API
- never be committed to Git
- never be logged
- not be stored as plaintext if persisted

Use the existing environment variable/name if already present.

Prevent uncontrolled repeated creation of administrators.

---

## 4. First Administrator bootstrap

Inspect the existing authentication/database architecture first.

If a user system already exists, extend it instead of creating a duplicate system.

Conceptual bootstrap form:

Registry Setup
- Bootstrap Secret
- Administrator Name
- Email/Username
- Password
- Confirm Password
[Create Administrator]

Server must:
1. Validate the bootstrap secret.
2. Validate administrator data.
3. Securely hash the password.
4. Create the first Administrator.
5. Prevent unauthorized/repeated bootstrap.
6. Return safe JSON only.
7. Never return the secret or password/hash.

If the project already has an established password service, reuse it.

---

## 5. User/account model

Inspect the current database first.

If no suitable account model exists, add one using existing project conventions.

Conceptually it needs:

- id
- name
- email/username
- password_hash
- role
- status
- created_at
- updated_at
- last_login_at

Controlled roles:

- DATA_ENTRY
- SUPERVISOR
- ADMINISTRATOR

Account status should support at least:

- ACTIVE
- DISABLED

Disabling a user must NOT delete their historical Registry records.

---

## 6. Normal login

Implement individual Registry login.

Example UI:

Registry Login

Email / Username
[____________]

Password
[____________]

[Sign In]

Backend must:
1. Find user.
2. Verify password against secure hash.
3. Verify account is active.
4. Determine role.
5. Establish the existing project's session/token mechanism.
6. Return only safe user information.

Never authenticate solely in frontend JavaScript.

Never return password hashes.

Never reveal unnecessary account-existence information in login errors.

---

## 7. Use the existing authentication architecture

Before implementing, inspect whether the project already uses:

- sessions
- secure cookies
- JWT
- another authentication mechanism

If one exists, integrate with it.

Do NOT create a second incompatible authentication system.

If JWT is used:
- keep signing secrets server-side
- validate expiry
- include only necessary claims
- do not place patient information in tokens

If cookies are used:
- use HttpOnly
- appropriate SameSite
- Secure in HTTPS production

---

## 8. Required auth endpoints

Follow existing API conventions. Do not duplicate equivalent routes.

Conceptually provide:

POST /api/v1/registry/auth/login
POST /api/v1/registry/auth/logout
GET  /api/v1/registry/auth/me

And a bootstrap endpoint/page if required by the existing architecture.

Successful /me response should contain safe information such as:

{
  "authenticated": true,
  "user": {
    "id": "...",
    "name": "...",
    "role": "SUPERVISOR"
  }
}

Never return password, password hash, Registry secret, or unnecessary sensitive data.

Unauthenticated requests must receive HTTP 401 where appropriate.

---

## 9. Logout

Implement logout using the project's actual authentication mechanism.

After logout:
- invalidate/clear session/token as appropriate
- Registry UI returns to login
- protected API requests no longer work

---

# 10. Exact role behavior

## DATA ENTRY PERSONNEL

The client specifies Input and Edit.

Allow appropriate Registry operations such as:
- login
- participant registration
- authorized participant viewing
- permitted participant editing
- screening entry
- permitted screening editing
- relevant follow-up/referral entry
- relevant record viewing

Do NOT allow:
- user administration
- role changes
- creating administrators
- system configuration
- security administration

## SUPERVISOR

The client specifies Review and Validate.

Allow Data Entry capabilities plus:
- review records
- validate records
- view review/validation status
- perform supervisory actions
- appropriate reports

Do NOT allow unrestricted user/system administration unless explicitly granted to Administrator.

## ADMINISTRATOR

The client specifies Full Access and System Configuration.

Allow:
- all Registry operations
- user creation
- user disabling/enabling
- role assignment
- account administration
- configuration
- reports/exports
- Registry administration

Do not invent additional client requirements and present them as if they came from the PDF. Use the above as the core permission model.

---

# 11. Server-side authorization — CRITICAL

Frontend hiding is NOT security.

This is insufficient:

if (user.role === 'ADMIN') showAdminButton();

Every protected backend operation must do:

Request
→ Authenticate
→ Identify user
→ Check role/permission
→ Allow or reject

Expected behavior:

Unauthenticated → 401 Unauthorized
Authenticated but unauthorized → 403 Forbidden

Test protected endpoints directly without relying on the UI.

---

# 12. Administrator user management

Create/use an Administrator-only user management area.

Desktop may display:

Name | Role | Status | Actions

But mobile MUST use cards/list records, for example:

┌──────────────────────────────┐
│ John Doe                     │
│ Data Entry                   │
│ Status: Active               │
│                              │
│ [Edit] [Disable]             │
└──────────────────────────────┘

Administrator capabilities:
- create user
- assign role
- activate/disable account
- update permitted account details
- secure password reset/change

Data Entry and Supervisor must receive 403 if they call these admin APIs directly.

---

# 13. Supervisor review/validation

Because the client explicitly requires supervisors to review and validate records, support a review/validation state in the existing Registry.

Conceptually:

New record → Pending Review → Supervisor Review → Validated

If the existing Registry already has suitable status fields, reuse them.

Where supported, maintain:
- reviewed_by
- reviewed_at
- review_status

Only authorized Supervisors/Admins may perform validation actions.

---

# 14. Frontend role-aware interface

After login, adapt the Registry UI.

Data Entry example:

Dashboard
Participants
Screenings
Follow-up
[Register Participant]
[Record Screening]
Profile
Logout

Supervisor example:

Dashboard
Participants
Screenings
Pending Review
Validated Records
Follow-up
Reports
[Review Records]
[Validate]
Profile
Logout

Administrator example:

Dashboard
Participants
Screenings
Outreach Events
Follow-up
Reports
Exports
Administration
  Users
  Configuration
Profile
Logout

These are UI examples. Preserve the previous successful Registry design and only add the necessary role-aware controls.

---

# 15. Secret-key migration behavior

Before the first Administrator exists:

Registry Setup
→ Bootstrap Secret
→ Create Administrator

After an Administrator exists:

Registry Login
→ Individual credentials
→ Role-specific Registry

Do NOT leave the shared secret as the everyday login for all employees.

If recovery is needed, implement it safely rather than making the secret a universal password.

---

# 16. Do not break the successful Registry

The previous Registry implementation is already successful.

Do NOT unnecessarily change:
- participant registration
- screening
- reports
- responsive layouts
- image slider
- existing Registry routes
- existing data

Integrate authentication and authorization with what already exists.

Before changing a route, inspect its current implementation.

---

# 17. Database migration safety

If database changes are needed:
- use existing migration mechanisms
- preserve existing Registry data
- do not drop Registry tables
- do not destroy production data
- add safe constraints/indexes
- follow current project conventions

---

# 18. Authentication and authorization tests

You MUST actually run tests.

### Bootstrap
- valid secret creates first administrator
- invalid secret rejected
- repeated unauthorized bootstrap prevented
- password hashed
- secret never returned

### Login
- Data Entry login succeeds
- Supervisor login succeeds
- Administrator login succeeds
- wrong password rejected
- disabled user rejected
- invalid credentials handled safely

### Authorization

Data Entry:
- permitted Registry action → PASS
- admin user API → 403
- role-management API → 403

Supervisor:
- Registry action → PASS
- review/validate → PASS
- user-management API → 403

Administrator:
- Registry actions → PASS
- user management → PASS
- configuration → PASS

### Session/token
- refresh preserves authenticated state
- logout works
- invalid/expired authentication rejected
- unauthenticated protected API → 401

---

# 19. Security audit

Search the repository for:

REGISTRY_ADMIN_KEY
password
password_hash
secret
token

Verify:
- no secret in frontend bundle
- no hardcoded credentials
- no password hashes in API responses
- no authorization bypass
- no role escalation
- disabled users cannot authenticate
- no unnecessary sensitive data in logs

---

# 20. Mobile authentication/admin UI

Test at:

320px
375px
390px
430px

Verify:
- no horizontal page overflow
- readable inputs
- usable buttons
- readable errors
- role badges fit
- user cards work
- action buttons are touch-friendly

Do NOT create desktop-only user management.

---

# 21. Final acceptance flow

The complete lifecycle must be:

FIRST INSTALLATION
↓
Bootstrap Administrator using existing secret
↓
Create Administrator account
↓
Normal individual Registry login
↓
Administrator creates Data Entry/Supervisor accounts
↓
Personnel log in individually
↓
Backend identifies role
↓
Backend enforces permissions
↓
Data Entry: Input/Edit
Supervisor: Review/Validate
Administrator: Full Access/Configuration

---

# 22. Final report

Report:

Authentication architecture:
Login endpoint:
Logout endpoint:
Current-user endpoint:

Bootstrap:
How first Administrator is created:
How repeated bootstrap is prevented:

Roles:
Data Entry permissions:
Supervisor permissions:
Administrator permissions:

Database:
Tables/models changed:
Migration performed:

Security:
Password hashing:
Secret protection:
Server-side authorization:
Session/token protection:

Testing:
Admin bootstrap: PASS/FAIL
Data Entry login: PASS/FAIL
Supervisor login: PASS/FAIL
Administrator login: PASS/FAIL
Invalid login: PASS/FAIL
Disabled account: PASS/FAIL
Data Entry authorization: PASS/FAIL
Supervisor authorization: PASS/FAIL
Administrator authorization: PASS/FAIL
User management protection: PASS/FAIL
Supervisor validation protection: PASS/FAIL
Logout: PASS/FAIL
Session/token persistence: PASS/FAIL
Mobile login: PASS/FAIL
Mobile user management: PASS/FAIL
Secret exposure check: PASS/FAIL

If anything is PARTIAL or FAIL, state exactly why.

# FINAL DIRECTIVE

Implement ONLY this authentication, role-based access, and Registry access-control layer.

The previous Registry implementation was successful. Integrate with it; do not rebuild it.

The final production flow must be:

Bootstrap secret → first Administrator account → normal individual login → role detection → server-side authorization → role-specific Registry access.

The shared secret must NOT remain the normal login mechanism for all personnel.

The three required roles are:

DATA ENTRY PERSONNEL → Input/Edit
SUPERVISOR → Review/Validate
ADMINISTRATOR → Full Access/System Configuration

The frontend can adapt its menus to the role, but the backend is the final authority.

Inspect → implement → migrate safely → run → test authentication → test authorization → test mobile → verify → report honestly.
