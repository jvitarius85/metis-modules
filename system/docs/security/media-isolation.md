# Media Isolation

Metis uses three production storage roots:

- `storage/public-media/`
- `storage/protected-media/`
- `storage/private-records/`

## Access Rules

Only `public-media` can be served through `/media/raw/...`.

Protected and private media must use `/media/{token}`. Token access enforces:

- token format validation
- registered storage class lookup
- expiration metadata
- authenticated user check
- `media.view` permission check
- path normalization and traversal denial
- symlink denial
- audit logging for denied and granted access
- private, no-store cache headers

## Write Rules

Use the canonical helpers:

- `metis_store_public_media(...)` for intentionally public media.
- `metis_store_protected_media(...)` for access-controlled operational files.
- `metis_store_private_record(...)` for records that should never be public raw media.

Protected/private writes require an explicit `access_ttl_seconds` option. Missing expiration metadata is rejected and audit logged.

## Legacy Compatibility

`storage/uploads/` and `storage/media/` are deprecated. Existing tokenized legacy reads remain supported for compatibility, but new sensitive modules must not write there.

## Sensitive Defaults

Sensitive modules should default to `protected-media` or `private-records`, including:

- inbound communications attachments
- reconciliation statements
- case and personnel documents
- donor-sensitive exports
- admin exports
- foster or child records
