# Security Model

- Routing middleware enforces request normalization, AJAX contract checks, and route security.
- The help system uses the same authenticated AJAX pipeline as the rest of the portal.
- Help content should never expose secrets; keep API keys and credential instructions in protected admin-only docs or settings screens.
