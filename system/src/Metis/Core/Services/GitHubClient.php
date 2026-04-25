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

    public function downloadRepositoryZipball(string $owner, string $repo, string $tag, string $destination, string $token = ''): array {
        $owner = trim($owner);
        $repo = trim($repo);
        $tag = trim($tag);
        if ($owner === '' || $repo === '' || $tag === '') {
            throw new \RuntimeException('GitHub archive download requires an owner, repository, and tag.');
        }

        $url = sprintf(
            'https://codeload.github.com/%s/%s/zip/refs/tags/%s',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($tag)
        );

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create release archive directory.');
        }

        $headers = [
            'Accept: application/zip',
            'User-Agent: Metis-Release-Updater/1.0',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if (function_exists('curl_init')) {
            $fp = @fopen($destination, 'wb');
            if (!is_resource($fp)) {
                throw new \RuntimeException('Unable to create release archive file.');
            }

            $ch = curl_init($url);
            if ($ch === false) {
                fclose($fp);
                throw new \RuntimeException('Unable to initialize GitHub archive download.');
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (\defined('CURLOPT_PROTOCOLS') && \defined('CURLPROTO_HTTP') && \defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            if (\defined('CURLOPT_REDIR_PROTOCOLS') && \defined('CURLPROTO_HTTP') && \defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }

            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            if (\PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            fclose($fp);

            if ($ok !== true || $status >= 400 || !is_file($destination) || filesize($destination) < 1) {
                @unlink($destination);
                throw new \RuntimeException($error !== '' ? $error : sprintf('GitHub archive download failed with status [%d].', $status));
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'ignore_errors' => true,
                    'timeout' => 120,
                    'follow_location' => 1,
                    'max_redirects' => 3,
                ],
            ]);
            $data = @file_get_contents($url, false, $context);
            if (!is_string($data) || $data === '') {
                throw new \RuntimeException('GitHub archive download failed.');
            }
            if (@file_put_contents($destination, $data, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to save release archive.');
            }
        }

        return [
            'url' => $url,
            'path' => $destination,
            'bytes' => (int) filesize($destination),
            'sha256' => (string) hash_file('sha256', $destination),
        ];
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
