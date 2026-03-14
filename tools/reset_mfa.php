<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

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
$prefix = (string) ($config['prefix'] ?? 'wp_');

$mysqli = mysqli_init();
if (!$mysqli) {
    fwrite(STDERR, "Failed to initialize MySQL client.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$connected = @$mysqli->real_connect($host, $user, $pass, $name, $port);
if (!$connected) {
    fwrite(STDERR, "Database connection failed: " . mysqli_connect_error() . "\n");
    exit(1);
}

$resolve_table = static function (mysqli $db, string $bare, string $legacy) use ($name): ?string {
    foreach ([$bare, $legacy] as $candidate) {
        $sql = "SHOW TABLES FROM `" . $db->real_escape_string($name) . "` LIKE '" . $db->real_escape_string($candidate) . "'";
        $result = $db->query($sql);
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_row();
            $result->free();
            if (is_array($row) && isset($row[0]) && $row[0] === $candidate) {
                return $candidate;
            }
        }
    }
    return null;
};

$peopleTable = $resolve_table($mysqli, 'metis_people', $prefix . 'metis_people');
$authTable = $resolve_table($mysqli, 'metis_auth_users', $prefix . 'metis_auth_users');

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
$personStmt = $mysqli->prepare($personSql);
if (!$personStmt) {
    fwrite(STDERR, "Failed to prepare person lookup.\n");
    exit(1);
}
$personStmt->bind_param('sss', $identifierLower, $identifierLower, $identifierUpper);
$personStmt->execute();
$person = $personStmt->get_result()?->fetch_assoc() ?: null;
$personStmt->close();

if (!$person && $authTable !== null) {
    $authSql = "SELECT a.person_id, a.user_login, a.user_email
                FROM `{$authTable}` a
                WHERE LOWER(a.user_login) = ?
                   OR LOWER(a.user_email) = ?
                LIMIT 1";
    $authStmt = $mysqli->prepare($authSql);
    if ($authStmt) {
        $authStmt->bind_param('ss', $identifierLower, $identifierLower);
        $authStmt->execute();
        $auth = $authStmt->get_result()?->fetch_assoc() ?: null;
        $authStmt->close();

        if (is_array($auth) && (int) ($auth['person_id'] ?? 0) > 0) {
            $personByIdSql = "SELECT id, pid, email, display_name, requires_2fa, totp_enabled, passkey_enabled
                              FROM `{$peopleTable}`
                              WHERE id = ?
                              LIMIT 1";
            $personByIdStmt = $mysqli->prepare($personByIdSql);
            if ($personByIdStmt) {
                $personId = (int) $auth['person_id'];
                $personByIdStmt->bind_param('i', $personId);
                $personByIdStmt->execute();
                $person = $personByIdStmt->get_result()?->fetch_assoc() ?: null;
                $personByIdStmt->close();
            }
        }
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
              WHERE id = ?";
$updateStmt = $mysqli->prepare($updateSql);
if (!$updateStmt) {
    fwrite(STDERR, "Failed to prepare MFA reset update.\n");
    exit(1);
}

$personId = (int) $person['id'];
$updateStmt->bind_param('i', $personId);
$ok = $updateStmt->execute();
$updateStmt->close();

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
