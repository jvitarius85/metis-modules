<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('METIS_STANDALONE', true);
define('METIS_PATH', dirname(__DIR__) . '/');

$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['HTTPS'] = 'on';
$GLOBALS['metis_runtime_config'] = [
    'app_key' => 'test-app-key',
    'base_path' => '/metis',
];

if (!class_exists('Metis_Cron_Manager')) {
    final class Metis_Cron_Manager {
        public static function register_task(string $slug, callable $callback, array $config = []): void {}
    }
}

require_once dirname(__DIR__) . '/includes/core/standalone_runtime.php';
require_once dirname(__DIR__) . '/includes/core/auth.php';
require_once dirname(__DIR__) . '/includes/modules/people/assets/people.ajax.php';

function assert_true(bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_false(bool $condition, string $message = 'Assertion failed'): void {
    assert_true(!$condition, $message);
}

function assert_same(mixed $expected, mixed $actual, string $message = 'Values are not equal'): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function test_portable_phpass_hash(string $password, string $salt = '12345678', int $count_log2 = 8): string {
    $itoa64 = metis_auth_portable_phpass_itoa64();
    $prefix = '$P$' . $itoa64[$count_log2] . $salt;
    $count = 1 << $count_log2;
    $digest = md5($salt . $password, true);
    do {
        $digest = md5($digest . $password, true);
    } while (--$count);

    return $prefix . metis_auth_portable_phpass_encode64($digest, 16);
}

function cleanup_upload(string $path): void {
    if (is_file($path)) {
        unlink($path);
    }
}

function write_temp_upload(string $contents, string $suffix): string {
    $path = tempnam(sys_get_temp_dir(), 'metis-upload-');
    if ($path === false) {
        throw new RuntimeException('Failed to create temporary upload file.');
    }

    $target = $path . $suffix;
    rename($path, $target);
    file_put_contents($target, $contents);

    return $target;
}

$tests = [];

$tests['nonce_round_trip_depends_on_action_and_session'] = function (): void {
    $_SESSION['metis_session_token'] = 'session-one';
    $nonce = wp_create_nonce('metis_profile');

    assert_true(wp_verify_nonce($nonce, 'metis_profile'), 'Nonce should validate for original action.');
    assert_false(wp_verify_nonce($nonce, 'metis_people'), 'Nonce should fail for different action.');

    $_SESSION['metis_session_token'] = 'session-two';
    assert_false(wp_verify_nonce($nonce, 'metis_profile'), 'Nonce should fail after session token rotates.');
};

$tests['nonce_expires_after_configured_window'] = function (): void {
    $_SESSION['metis_session_token'] = 'session-expiring';
    $GLOBALS['metis_runtime_config']['csrf_ttl'] = 60;

    $nonce = wp_create_nonce('metis_profile');
    assert_true(wp_verify_nonce($nonce, 'metis_profile'), 'Fresh nonce should validate.');

    $parts = explode(':', $nonce, 2);
    assert_true(count($parts) === 2, 'Standalone nonce should embed issue time.');
    $expired = (string) ((int) $parts[0] - 180) . ':' . (string) $parts[1];

    assert_false(wp_verify_nonce($expired, 'metis_profile'), 'Expired nonce should fail validation.');
    $GLOBALS['metis_runtime_config']['csrf_ttl'] = 7200;
};

$tests['password_checker_accepts_modern_and_legacy_hashes'] = function (): void {
    $password = 'CorrectHorseBatteryStaple!';

    $bcrypt = password_hash($password, PASSWORD_DEFAULT);
    assert_true(metis_auth_check_password($password, $bcrypt), 'Bcrypt hash should validate.');
    assert_false(metis_auth_check_password('wrong-password', $bcrypt), 'Bcrypt hash should reject wrong password.');

    $md5 = md5($password);
    assert_true(metis_auth_check_password($password, $md5), 'Legacy MD5 hash should validate.');
    assert_false(metis_auth_check_password('wrong-password', $md5), 'Legacy MD5 hash should reject wrong password.');

    $phpass = test_portable_phpass_hash($password);
    assert_true(metis_auth_check_password($password, $phpass), 'Portable phpass hash should validate.');
    assert_false(metis_auth_check_password('wrong-password', $phpass), 'Portable phpass hash should reject wrong password.');
};

$tests['totp_verifier_accepts_current_code_and_rejects_bad_code'] = function (): void {
    $secret = 'JBSWY3DPEHPK3PXP';
    $encrypted = metis_people_encrypt_secret($secret);
    $person = [
        'id' => 42,
        'totp_secret_enc' => $encrypted,
    ];

    $code = metis_people_totp_now($secret, 30, 6, time());
    assert_true($code !== '', 'TOTP generator should return a code.');
    assert_true(metis_auth_verify_totp_code($person, $code), 'Current TOTP code should verify.');
    assert_false(metis_auth_verify_totp_code($person, '000000'), 'Invalid TOTP code should fail.');
};

$tests['totp_verifier_accepts_small_clock_skew'] = function (): void {
    $secret = 'JBSWY3DPEHPK3PXP';
    $encrypted = metis_people_encrypt_secret($secret);
    $person = [
        'id' => 43,
        'totp_secret_enc' => $encrypted,
    ];

    $code = metis_people_totp_now($secret, 30, 6, time() + 60);
    assert_true(metis_auth_verify_totp_code($person, $code), 'TOTP code should verify with small clock skew.');
};

$tests['totp_secret_decrypts_with_legacy_key_derivation'] = function (): void {
    $secret = 'JBSWY3DPEHPK3PXP';
    $legacy_key = hash('sha256', 'test-app-key' . 'test-app-key', true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($secret, 'AES-256-CBC', $legacy_key, OPENSSL_RAW_DATA, $iv);
    assert_true($cipher !== false, 'Legacy encryption should succeed.');

    $encoded = base64_encode($iv . $cipher);
    assert_same($secret, metis_auth_decrypt_secret($encoded), 'Legacy-encrypted secret should still decrypt.');
};

$tests['pending_mfa_login_persists_redirect_target'] = function (): void {
    $_SESSION['metis_pending_auth'] = [];
    metis_auth_pending_login_start([
        'id' => 7,
        'person_id' => 42,
    ], 'https://example.test/metis/calendar/');

    assert_same('https://example.test/metis/calendar/', metis_auth_pending_login_redirect(), 'Pending MFA redirect target should be preserved.');
};

$tests['mfa_url_marks_explicit_mfa_step'] = function (): void {
    $url = metis_auth_mfa_url('https://example.test/metis/calendar/');

    assert_true(str_contains($url, 'step=mfa'), 'MFA URL should include explicit MFA step.');
    assert_true(str_contains($url, 'redirect_to='), 'MFA URL should preserve redirect target.');
};

$tests['upload_bits_writes_inside_metis_upload_root_and_sanitizes_name'] = function (): void {
    $payload = "avatar upload body\n";
    $result = metis_upload_bits('../avatar test.png', null, $payload);

    assert_same(false, $result['error'], 'Upload should succeed.');
    $file = (string) $result['file'];
    $url = (string) $result['url'];
    $expectedBaseDir = dirname(__DIR__) . '/storage/uploads/';

    assert_true(is_file($file), 'Uploaded file should exist.');
    assert_same($payload, file_get_contents($file), 'Uploaded file contents should match.');
    assert_true(str_starts_with($file, $expectedBaseDir), 'Upload should stay inside Metis upload storage.');
    assert_true((bool) preg_match('/^[a-f0-9]{32}\.txt$/', basename($file)), 'Uploads should be renamed to randomized filenames.');
    assert_true(str_contains($url, '/metis/storage/uploads/'), 'Upload URL should stay under the mounted Metis base path.');

    cleanup_upload($file);
};

$tests['upload_bits_avoids_filename_collisions'] = function (): void {
    $first = metis_upload_bits('collision.txt', null, 'one');
    $second = metis_upload_bits('collision.txt', null, 'two');

    assert_same(false, $first['error'], 'First upload should succeed.');
    assert_same(false, $second['error'], 'Second upload should succeed.');
    assert_true($first['file'] !== $second['file'], 'Second upload should get a unique filename.');
    assert_same('one', file_get_contents((string) $first['file']), 'First upload contents should be preserved.');
    assert_same('two', file_get_contents((string) $second['file']), 'Second upload contents should be preserved.');

    cleanup_upload((string) $first['file']);
    cleanup_upload((string) $second['file']);
};

$tests['handle_upload_renames_files_and_uses_detected_mime_type'] = function (): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK1cAAAAASUVORK5CYII=', true);
    assert_true($png !== false, 'PNG fixture should decode.');

    $tmp = write_temp_upload((string) $png, '.upload');
    $result = metis_handle_upload([
        'name' => '../avatar.php',
        'tmp_name' => $tmp,
        'size' => strlen((string) $png),
    ], [
        'mimes' => [
            'png' => 'image/png',
            'jpg|jpeg' => 'image/jpeg',
        ],
    ]);

    assert_true(empty($result['error']), 'PNG upload should succeed.');
    $file = (string) ($result['file'] ?? '');
    assert_true(is_file($file), 'Moved upload should exist.');
    assert_same('image/png', (string) ($result['type'] ?? ''), 'Upload type should come from MIME detection.');
    assert_true((bool) preg_match('/^[a-f0-9]{32}\.png$/', basename($file)), 'Upload filename should be randomized and use the detected extension.');

    cleanup_upload($file);
    cleanup_upload($tmp);
};

$tests['handle_upload_rejects_spoofed_file_types'] = function (): void {
    $tmp = write_temp_upload("<?php echo 'bad';", '.upload');
    $result = metis_handle_upload([
        'name' => 'shell.jpg',
        'tmp_name' => $tmp,
        'size' => 18,
    ], [
        'mimes' => [
            'jpg|jpeg' => 'image/jpeg',
            'png' => 'image/png',
        ],
    ]);

    assert_same('Uploaded file type is not allowed.', (string) ($result['error'] ?? ''), 'Spoofed image upload should be rejected by MIME validation.');
    cleanup_upload($tmp);
};

foreach ($tests as $name => $test) {
    $test();
}

echo "All auth and upload security tests passed.\n";
