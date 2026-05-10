# Platform Boundaries

Metis is a platform with explicit trust boundaries:

- Browser input enters through router requests, AJAX contracts, or documented legacy module surfaces.
- Public files are only served from `storage/public-media` through `/media/raw/...`.
- Protected files are served only by token lookup with expiration, permission, and audit checks.
- Database access belongs in DB services, repositories, schema managers, installers, migrations, and tools.
- Process execution belongs in `ProcessRunner` or CLI-only tooling.
- Hermes actions never inherit authority implicitly; they resolve a registered tool, validate permissions, and pass through Secure Enclave mediation.

Future modules should declare routes through module manifests or router registration, register AJAX actions through `metis_ajax_register_controller()`, and add privileged operations through Secure Enclave policies.
