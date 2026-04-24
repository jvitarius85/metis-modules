<?php
declare(strict_types=1);

namespace Metis\Auth;

use Metis\Core\Services\ConfigService;
use Metis\Core\Services\HttpClient;

final class GoogleWorkspaceProvider {
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function __construct(
        private readonly ConfigService $config = new ConfigService(),
        private readonly HttpClient $http = new HttpClient()
    ) {}

    public function isConfigured(): bool {
        $config = $this->settings();
        return $config['client_id'] !== '' && $config['client_secret'] !== '';
    }

    public function hostedDomain(): string {
        return (string) $this->settings()['hosted_domain'];
    }

    public function authorizationUrl(string $redirectUri, string $state = ''): string {
        $config = $this->settings();
        $query = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'access_type' => 'online',
            'state' => $state,
            'hd' => $config['hosted_domain'] !== '' ? $config['hosted_domain'] : null,
        ]);

        return self::AUTH_ENDPOINT . '?' . $query;
    }

    public function authenticate(string $code, string $redirectUri): array {
        $tokens = $this->exchangeCode($code, $redirectUri);
        $profile = $this->userProfile((string) ($tokens['access_token'] ?? ''));
        $this->assertHostedDomain($profile);

        return [
            'status' => 'authenticated',
            'email' => (string) ($profile['email'] ?? ''),
            'name' => (string) ($profile['name'] ?? ''),
            'given_name' => (string) ($profile['given_name'] ?? ''),
            'family_name' => (string) ($profile['family_name'] ?? ''),
            'picture' => (string) ($profile['picture'] ?? ''),
            'hd' => (string) ($profile['hd'] ?? ''),
            'tokens' => $tokens,
        ];
    }

    public function exchangeCode(string $code, string $redirectUri): array {
        $config = $this->settings();
        $response = $this->http->request(
            'POST',
            self::TOKEN_ENDPOINT,
            [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            http_build_query([
                'code' => $code,
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ])
        );

        if ((int) ($response['status'] ?? 0) >= 400) {
            throw new \RuntimeException('Google token exchange failed.');
        }

        $tokens = is_array($response['json'] ?? null) ? $response['json'] : [];
        if ((string) ($tokens['access_token'] ?? '') === '') {
            throw new \RuntimeException('Google token exchange did not return an access token.');
        }

        return $tokens;
    }

    public function userProfile(string $accessToken): array {
        $response = $this->http->get(self::USERINFO_ENDPOINT, [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        if ((int) ($response['status'] ?? 0) >= 400) {
            throw new \RuntimeException('Google user profile lookup failed.');
        }

        return is_array($response['json'] ?? null) ? $response['json'] : [];
    }

    private function assertHostedDomain(array $profile): void {
        $config = $this->settings();
        $expected = $config['hosted_domain'];
        if ($expected === '') {
            return;
        }

        $actual = strtolower((string) ($profile['hd'] ?? ''));
        if ($actual !== strtolower($expected)) {
            throw new \RuntimeException('Google Workspace hosted domain validation failed.');
        }
    }

    private function settings(): array {
        $settings = [
            'client_id' => '',
            'client_secret' => '',
            'hosted_domain' => '',
        ];

        if (\class_exists('Core_Settings_Service')) {
            $settings['client_id'] = trim((string) \Core_Settings_Service::get('workspace_google_sso_client_id', ''));
            $settings['client_secret'] = trim((string) \Metis\Core\Services\CredentialService::getBySetting('workspace_google_sso_client_secret'));
            $settings['hosted_domain'] = trim((string) \Core_Settings_Service::get('workspace_google_sso_hosted_domain', ''));
        }

        if ($settings['client_id'] !== '' && $settings['client_secret'] !== '') {
            return $settings;
        }

        $file = $this->config->loadFile('config/auth/google_workspace.php', []);
        return [
            'client_id' => (string) ($file['client_id'] ?? ''),
            'client_secret' => (string) ($file['client_secret'] ?? ''),
            'hosted_domain' => (string) ($file['hosted_domain'] ?? ''),
        ];
    }
}
