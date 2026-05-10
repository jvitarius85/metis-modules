<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Metis/Core/Runtime/CliToolGuard.php';
metis_require_cli_tool();
require_once __DIR__ . '/../src/Metis/Core/Runtime/StandaloneBootstrap.php';
require_once __DIR__ . '/../src/Metis/Services/DatabaseService.php';

$args = $argv;
array_shift($args);

$identifier = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--identifier=')) {
        $identifier = trim(substr($arg, strlen('--identifier=')));
        break;
    }
}

if ($identifier === '') {
    fwrite(STDERR, "Usage: php tools/reset_mfa.php --identifier=<login|email|pid>\n");
    exit(1);
}

$config = require __DIR__ . '/../config/database.php';
if (!is_array($config)) {
    fwrite(STDERR, "Database config is invalid.\n");
    exit(1);
}

$host = (string) ($config['host'] ?? '127.0.0.1');
$port = (int) ($config['port'] ?? 3306);
$user = (string) ($config['username'] ?? '');
$pass = (string) ($config['password'] ?? '');
$name = (string) ($config['database'] ?? '');
$prefix = (string) ($config['prefix'] ?? 'metis_');

$db = new \Metis\Services\DatabaseService(
    new \MetisRuntimeDbConnection(
        $user,
        $pass,
        $name,
        $host . ':' . $port,
        $prefix
    )
);

$resolve_table = static function (\Metis\Services\DatabaseService $db, string $bare, string $legacy): ?string {
    foreach ([$bare, $legacy] as $candidate) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $candidate) !== 1) {
            continue;
        }
        $exists = (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            [$candidate]
        );
        if ($exists > 0) {
            return $candidate;
        }
    }
    return null;
};

$peopleTable = $resolve_table($db, 'metis_people', $prefix . 'metis_people');
$authTable = $resolve_table($db, 'metis_auth_users', $prefix . 'metis_auth_users');

if ($peopleTable === null) {
    fwrite(STDERR, "Could not find the Metis people table.\n");
    exit(1);
}

$identifierLower = mb_strtolower($identifier, 'UTF-8');
$identifierUpper = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $identifier) ?? '');

$personSql = "SELECT id, pid, email, display_name, requires_2fa, totp_enabled, passkey_enabled
              FROM `{$peopleTable}`
              WHERE LOWER(email) = ?
                 OR LOWER(workspace_email) = ?
                 OR pid = ?
              LIMIT 1";
$person = $db->fetchOne(
    str_replace('?', '%s', $personSql),
    [$identifierLower, $identifierLower, $identifierUpper]
);

if (!$person && $authTable !== null) {
    $authSql = "SELECT a.person_id, a.user_login, a.user_email
                FROM `{$authTable}` a
                WHERE LOWER(a.user_login) = ?
                   OR LOWER(a.user_email) = ?
                LIMIT 1";
    $auth = $db->fetchOne(
        str_replace('?', '%s', $authSql),
        [$identifierLower, $identifierLower]
    );

    if (is_array($auth) && (int) ($auth['person_id'] ?? 0) > 0) {
        $personByIdSql = "SELECT id, pid, email, display_name, requires_2fa, totp_enabled, passkey_enabled
                          FROM `{$peopleTable}`
                          WHERE id = %d
                          LIMIT 1";
        $person = $db->fetchOne($personByIdSql, [(int) $auth['person_id']]);
    }
}

if (!$person) {
    fwrite(STDERR, "No matching Metis person was found for '{$identifier}'.\n");
    exit(1);
}

$updateSql = "UPDATE `{$peopleTable}`
              SET requires_2fa = 0,
                  mfa_method = 'none',
                  totp_enabled = 0,
                  passkey_enabled = 0,
                  totp_secret_enc = NULL,
                  updated_at = NOW()
              WHERE id = %d";

$personId = (int) $person['id'];
$ok = $db->executePrepared($updateSql, [$personId]);

if (!$ok) {
    fwrite(STDERR, "Failed to reset MFA for person #{$personId}.\n");
    exit(1);
}

fwrite(STDOUT, "MFA reset for person #{$personId}");
if (!empty($person['display_name'])) {
    fwrite(STDOUT, " ({$person['display_name']})");
}
if (!empty($person['email'])) {
    fwrite(STDOUT, " <{$person['email']}>");
}
fwrite(STDOUT, "\n");
