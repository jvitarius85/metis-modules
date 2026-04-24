# METIS DEPLOYMENT RULES
Version: 1.1

Defines deployment expectations.

## Standard Deployment Steps

1. pull repository
2. install dependencies
3. run module compliance verification
4. run migrations
5. clear caches
6. validate permissions and config

## Required Gates

- deployment and release workflows must fail when module compliance verification fails
- release apply/rollback operations must be blocked when compliance checks fail
- automated compliance verification must run in CI and release pipelines

Recommended command:
- `php tools/module_compliance.php verify --refresh`

## Environments

Supported:
- development
- staging
- production

Production must disable debug output and internal exception exposure.
