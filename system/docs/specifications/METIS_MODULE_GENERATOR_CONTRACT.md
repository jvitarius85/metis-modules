# METIS MODULE GENERATOR CONTRACT
Version: 1.0

Defines the required behavior for automated module generation.

## Generator Output

Every generated module must include:

- `module.json`
- `Module.php`
- `views/`

Generated modules may include these folders only when the manifest references files inside them:

- `assets/`
- `routes/`
- `services/`
- `templates/`

## Generator Rules

The generator must:
- validate naming rules
- create a correct manifest
- create route, service, and asset files only when the module actually declares them
- create view files for every manifest view
- register no alternate architecture
- follow all contracts in this pack

## Dependency Support

If dependencies are declared in the manifest, the loader must validate them before module boot.
