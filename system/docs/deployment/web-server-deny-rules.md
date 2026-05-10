# Web Server Deny Rules

Apache is governed by the repository root `.htaccess`. nginx and LiteSpeed deployments must apply equivalent deny rules.

## Required Denials

Block direct access to:

- hidden dotfiles except `/.well-known/`
- `system/config/`
- `system/docs/`
- `system/modules/`
- `system/src/`
- `system/tests/`
- `system/tools/`
- `system/vendor/`
- `storage/`
- `vendor/`
- `.metis-integrity/`
- file extensions: `env`, `ini`, `log`, `md`, `markdown`, `pem`, `key`, `crt`, `sql`, `sqlite`, `sqlite3`, `toml`, `txt`, `yaml`, `yml`, `zip`, `tar`, `gz`, `bak`, `dist`, `old`

Raw files under `storage/` must never be exposed directly. Public media is served only through `/media/raw/...`, which routes through the front controller and the central media policy.

## nginx Example

```nginx
location ~ (^|/)\.(?!well-known/) {
    deny all;
}

location ~ ^/(?:vendor|\.metis-integrity|storage)(?:/|$) {
    return 404;
}

location ~ ^/system/(?:config|docs|modules|src|tests|tools|cloudflare|vendor)(?:/|$) {
    return 404;
}

location ~* \.(?:env|ini|log|md|markdown|pem|key|crt|sql|sqlite3?|toml|txt|ya?ml|zip|tar|gz|bak|dist|old)$ {
    return 404;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## LiteSpeed Example

Use the Apache `.htaccess` rules when LiteSpeed honors per-directory rewrite rules. If `.htaccess` is disabled, apply equivalent virtual-host rewrite rules:

```apache
RewriteRule (^|/)\.(?!well-known/) - [F,L]
RewriteRule ^(?:vendor|\.metis-integrity|storage)(?:/|$) - [F,L,NC]
RewriteRule ^system/(?:config|docs|modules|src|tests|tools|cloudflare|vendor)(?:/|$) - [F,L,NC]
RewriteRule \.(?:env|ini|log|md|markdown|pem|key|crt|sql|sqlite3?|toml|txt|ya?ml|zip|tar|gz|bak|dist|old)$ - [F,L,NC]
```
