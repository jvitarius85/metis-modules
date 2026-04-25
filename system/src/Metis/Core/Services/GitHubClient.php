<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class GitHubClient {
    public function __construct(
        private readonly HttpClient $http = new HttpClient()
    ) {}

    public function latestRelease(string $owner, string $repo, string $token = ''): array {
        $headers = [
            'Accept' => 'application/vnd.github+json',
        ];

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', rawurlencode($owner), rawurlencode($repo));
        $response = $this->http->get($url, $headers);

        if (($response['status'] ?? 0) === 404) {
            $fallback = $this->latestTagRelease($owner, $repo, $headers);
            if ($fallback !== []) {
                return $fallback;
            }
        }

        if (($response['status'] ?? 0) >= 400) {
            throw new \RuntimeException(sprintf('GitHub release lookup failed with status [%d].', (int) ($response['status'] ?? 0)));
        }

        return is_array($response['json'] ?? null) ? $response['json'] : [];
    }

    public function repositoryJsonFile(string $owner, string $repo, string $path, string $ref = '', string $token = ''): array {
        $headers = [
            'Accept' => 'application/vnd.github+json',
        ];

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            str_replace('%2F', '/', rawurlencode(ltrim($path, '/')))
        );

        if ($ref !== '') {
            $url .= '?ref=' . rawurlencode($ref);
        }

        $response = $this->http->get($url, $headers);
        if (($response['status'] ?? 0) >= 400) {
            throw new \RuntimeException(sprintf('GitHub contents lookup failed with status [%d].', (int) ($response['status'] ?? 0)));
        }

        $payload = is_array($response['json'] ?? null) ? $response['json'] : [];
        $content = (string) ($payload['content'] ?? '');
        if ($content === '') {
            return [];
        }

        $decoded = base64_decode(str_replace(["\r", "\n"], '', $content), true);
        if (!is_string($decoded) || $decoded === '') {
            return [];
        }

        $json = json_decode($decoded, true);
        return is_array($json) ? $json : [];
    }

    public function repositoryTags(string $owner, string $repo, string $token = ''): array {
        $headers = [
            'Accept' => 'application/vnd.github+json',
        ];

        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $url = sprintf('https://api.github.com/repos/%s/%s/tags?per_page=100', rawurlencode($owner), rawurlencode($repo));
        $response = $this->http->get($url, $headers);

        if (($response['status'] ?? 0) >= 400) {
            throw new \RuntimeException(sprintf('GitHub tag lookup failed with status [%d].', (int) ($response['status'] ?? 0)));
        }

        return is_array($response['json'] ?? null) ? $response['json'] : [];
    }

    private function latestTagRelease(string $owner, string $repo, array $headers): array {
        try {
            $tags = $this->repositoryTags(
                $owner,
                $repo,
                str_replace('Bearer ', '', (string) ($headers['Authorization'] ?? ''))
            );
        } catch (\RuntimeException) {
            return [];
        }

        $best = null;
        $bestVersion = null;

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $tagName = (string) ($tag['name'] ?? '');
            $version = ltrim($tagName, 'v');
            if ($tagName === '' || !preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?$/', $version)) {
                continue;
            }

            if ($bestVersion === null || version_compare($version, $bestVersion, '>')) {
                $bestVersion = $version;
                $best = [
                    'tag_name' => $tagName,
                    'name' => $tagName,
                    'body' => '',
                    'zipball_url' => (string) ($tag['zipball_url'] ?? ''),
                    'published_at' => '',
                ];
            }
        }

        return $best ?? [];
    }
}
