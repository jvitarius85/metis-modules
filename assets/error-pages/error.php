<?php
declare(strict_types=1);

$requestedCode = $metisErrorStatus
    ?? $_GET['code']
    ?? ($_SERVER['REDIRECT_STATUS'] ?? $_SERVER['REQUEST_STATUS'] ?? '500');
$statusCode = (int) $requestedCode;

$pages = [
    403 => [
        'kicker' => 'Metis Security Notice',
        'chip' => 'Connection screened',
        'system_copy' => 'Metis actively screens requests to protect sensitive areas of the platform. When a request appears unsafe or unauthorized, access is stopped before the page is rendered.',
        'title' => 'Your connection was blocked for safety.',
        'lead' => 'The request reached the site, but access was blocked because the session, request pattern, or security checks suggested a potential threat or unauthorized action.',
        'what_label' => 'What this means',
        'what_value' => 'This can happen when a session has expired, a nonce or permission check fails, or the Secure Enclave detects activity that should not continue.',
        'action_label' => 'Recommended action',
        'action_value' => 'Return to the previous page, sign in again if needed, and retry more slowly. If you believe this was a mistake, contact an administrator with the time of the block.',
        'action_text' => 'Go back',
        'action_href' => 'javascript:history.back()',
        'footer' => 'Error type: Security block',
    ],
    404 => [
        'kicker' => 'Metis Route Notice',
        'chip' => 'Address not found',
        'system_copy' => 'The platform is reachable, but the requested route does not map to an active page, module surface, or public endpoint.',
        'title' => 'The page or endpoint could not be found.',
        'lead' => 'The request reached Metis successfully, but there is no active route for this address. The link may be outdated, the path may be mistyped, or the resource may have been moved.',
        'what_label' => 'What this means',
        'what_value' => 'The application did not find a matching route or resource for the requested path.',
        'action_label' => 'Recommended action',
        'action_value' => 'Return to a known page, check the address for typos, or start again from the dashboard or home page.',
        'action_text' => 'Go home',
        'action_href' => '/',
        'footer' => 'Error type: Route not found',
    ],
    500 => [
        'kicker' => 'Metis Recovery Notice',
        'chip' => 'Protected failure',
        'system_copy' => 'Metis prefers to fail safely. If a request cannot complete cleanly, the platform returns a controlled response instead of exposing a raw server error or blank white page.',
        'title' => 'The request could not be completed safely.',
        'lead' => 'Something interrupted processing before the site could return the expected page. In some cases this is a server fault, and in others a protective control may have stopped execution to prevent unsafe behavior.',
        'what_label' => 'What this means',
        'what_value' => 'An internal service, dependency, or security check stopped the request before a normal response could be rendered.',
        'action_label' => 'Recommended action',
        'action_value' => 'Refresh once. If the problem continues, review logs and recent changes, or contact support with the time and page you were trying to access.',
        'action_text' => 'Retry request',
        'action_href' => 'javascript:location.reload()',
        'footer' => 'Error type: Internal application failure',
    ],
    503 => [
        'kicker' => 'Metis Availability Notice',
        'chip' => 'Temporary protection',
        'system_copy' => 'Metis may temporarily restrict access while services recover, restart, or protective controls reduce load. The goal is to keep the platform stable without exposing a generic server failure.',
        'title' => 'The service is temporarily unavailable.',
        'lead' => 'The site is reachable, but this request cannot be served right now. A maintenance window, restart, or temporary security response may be limiting access until conditions return to normal.',
        'what_label' => 'What this means',
        'what_value' => 'A dependent service may be restarting, maintenance may be underway, or access may be temporarily throttled while the platform recovers from suspicious or heavy traffic.',
        'action_label' => 'Recommended action',
        'action_value' => 'Wait a moment and retry. If the interruption lasts, publish or check a status notice so users know access is being restored intentionally.',
        'action_text' => 'Retry request',
        'action_href' => 'javascript:location.reload()',
        'footer' => 'Error type: Temporary service interruption',
    ],
];

if ( ! isset( $pages[ $statusCode ] ) ) {
    $statusCode = 500;
}

$page = $pages[ $statusCode ];
$requestUri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
$displayUri = $requestUri === '' ? '/' : $requestUri;
$traceId = (string) ( $metisErrorTraceId ?? '' );
$runtimeMessage = trim( (string) ( $metisErrorMessage ?? '' ) );

if ( isset( $metisErrorTitle ) && trim( (string) $metisErrorTitle ) !== '' ) {
    $page['title'] = trim( (string) $metisErrorTitle );
}

$assetBase = dirname($_SERVER['SCRIPT_NAME']).'/assets/error-pages/';
$cssHref = $assetBase . 'metis-errors.css';
$logoSrc = $assetBase . 'metis-shield-logo.png';

if ( ! headers_sent() ) {
    http_response_code( $statusCode );
    header( 'Content-Type: text/html; charset=utf-8' );
}

$escape = static function ( string $value ): string {
    return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $escape((string) $statusCode) ?> | Metis</title>
  <link rel="stylesheet" href="<?= $escape($cssHref) ?>">
</head>
<body>
  <main class="page">
    <section class="card">
      <aside class="brand-panel">
        <p class="brand-kicker"><?= $escape($page['kicker']) ?></p>
        <div class="logo-wrap">
          <img src="<?= $escape($logoSrc) ?>" alt="Metis logo">
          <span class="status-chip"><?= $escape($page['chip']) ?></span>
          <p class="system-copy"><?= $escape($page['system_copy']) ?></p>
        </div>
      </aside>
      <div class="content-panel">
        <h1 class="code"><?= $escape((string) $statusCode) ?></h1>
        <h2 class="title"><?= $escape($page['title']) ?></h2>
        <p class="lead"><?= $escape($page['lead']) ?></p>
        <div class="meta">
          <div class="meta-item">
            <span class="meta-label"><?= $escape($page['what_label']) ?></span>
            <div class="meta-value"><?= $escape($page['what_value']) ?></div>
          </div>
          <div class="meta-item">
            <span class="meta-label"><?= $escape($page['action_label']) ?></span>
            <div class="meta-value"><?= $escape($page['action_value']) ?></div>
          </div>
          <div class="meta-item">
            <span class="meta-label">Requested route</span>
            <div class="meta-value"><?= $escape($displayUri) ?></div>
          </div>
          <?php if ($runtimeMessage !== ''): ?>
          <div class="meta-item">
            <span class="meta-label">Details</span>
            <div class="meta-value"><?= $escape($runtimeMessage) ?></div>
          </div>
          <?php endif; ?>
          <?php if ($traceId !== ''): ?>
          <div class="meta-item">
            <span class="meta-label">Reference ID</span>
            <div class="meta-value"><?= $escape($traceId) ?></div>
          </div>
          <?php endif; ?>
        </div>
        <div class="actions">
          <a class="btn btn-secondary" href="<?= $escape($page['action_href']) ?>"><?= $escape($page['action_text']) ?></a>
        </div>
        <p class="footer-note"><?= $escape($page['footer']) ?></p>
      </div>
    </section>
  </main>
</body>
</html>
