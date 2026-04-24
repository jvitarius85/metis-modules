# Metis Authentication Flow

Metis uses a single login entry point at `/login`.

## Flow Map

Identifier Input
  -> Account Resolution
  -> Auth Method Detection
  -> `passkey` or `google_workspace` or `password + MFA`
  -> Session Creation
  -> Redirect to Application

## Decision Order

1. The login screen accepts only a username or email.
2. `/api/auth/resolve` calls `Metis\Auth\AuthResolver`.
3. The resolver inspects the matching Metis account and returns the preferred method in this order:
   - passkey
   - Google Workspace
   - password

## Method Behavior

### Passkey

- Start WebAuthn assertion immediately.
- Do not prompt for password.
- Do not prompt for MFA after a successful passkey assertion.
- Create the Metis session through `Metis\Auth\AuthSessionManager`.

### Google Workspace

- Redirect to Google OAuth using the configured callback URI.
- Validate OAuth state on callback.
- Resolve the Metis user from the Google email and linked person record.
- Create the Metis session through `Metis\Auth\AuthSessionManager`.
- Redirect to the original destination or the portal.

### Password

- Prompt for password only after account resolution returns `password`.
- Verify the password.
- Require TOTP MFA before creating a session.
- Create the Metis session only after successful MFA verification.

## Centralized Services

- `src/Metis/Auth/AuthResolver.php`
- `src/Metis/Auth/AuthService.php`
- `src/Metis/Auth/MfaService.php`
- `src/Metis/Auth/PasskeyService.php`
- `src/Metis/Auth/SsoService.php`
- `src/Metis/Auth/AuthSessionManager.php`

## Version Source

Metis version reads through `src/Metis/Core/Version.php`, which is the single source used by runtime version helpers and the system version service.
