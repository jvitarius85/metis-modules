# METIS MODULE GENERATOR CONTRACT
Version: 1.0

Defines the required behavior for automated module generation.

## Generator Output

Every generated module must include:

- `module.json`
- `Module.php`
- `controllers/`
- `services/`
- `views/`
- `routes/`
- `assets/css/`
- `assets/js/`
- `migrations/`

## Generator Rules

The generator must:
- validate naming rules
- create a correct manifest
- create route placeholders
- create service/controller/view stubs
- register no alternate architecture
- follow all contracts in this pack

## Dependency Support

If dependencies are declared in the manifest, the loader must validate them before module boot.
