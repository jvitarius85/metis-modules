<?php
/**
 * Stripe Deposits Sync (Payouts → mwtools_deposits)
 *
 * Assumptions:
 * - Stripe PHP SDK is available (composer autoloaded somewhere in your plugin).
 * - wp_options has: mwtools_stripe_secret (already working for you)
 * - DB table: {$wpdb->prefix}mwtools_deposits
 *   Expected cols (minimum):
 *     provider, provider_ref, deposit_type, source, status, deposit_date, total_amount, batch_count, created_at, updated_at
 *   Recommended: UNIQUE KEY on (provider, provider_ref) or at least provider_ref.
 *
 * What it syncs:
 * - Stripe payouts (weekly bank deposits) via \Stripe\Payout::all()
 * - Writes/updates rows in mwtools_deposits using idempotent upsert.
 */

/**
 * Sync Stripe payouts into mwtools_deposits.
 *
 * @param array $args
 * @return array {success, inserted, updated, skipped, errors, last_cursor_ts}
 */
function mwtools_stripe_sync_deposits(array $args = []) {
    global $wpdb;

    $defaults = [
        'dry_run'      => true,    // set false to write
        'days'         => 60,       // if no since_ts provided
        'since_ts'     => 0,        // unix timestamp (seconds)
        'until_ts'     => 0,        // unix timestamp (seconds), 0 = now
        'page_limit'   => 100,      // Stripe page size
        'max_pages'    => 50,       // safety cap
        'store_cursor' => true,     // store last cursor in wp_options
        'cursor_key'   => 'mwtools_stripe_payout_cursor_ts', // option name
        'log'          => false,    // set true to error_log
    ];
    $a = array_merge($defaults, $args);

    $res = [
        'success'        => false,
        'inserted'       => 0,
        'updated'        => 0,
        'skipped'        => 0,
        'errors'         => [],
        'last_cursor_ts' => 0,
    ];

    // --- Stripe key ---
    $secret = get_option('mwtools_stripe_secret');
    if (empty($secret)) {
        $res['errors'][] = 'Stripe secret key missing (mwtools_stripe_secret).';
        return $res;
    }

    // --- Determine time window / cursor ---
    $now = time();
    $until = (int) ($a['until_ts'] > 0 ? $a['until_ts'] : $now);

    $cursor = 0;
    if (!empty($a['since_ts'])) {
        $cursor = (int) $a['since_ts'];
    } else {
        $stored = (int) get_option($a['cursor_key'], 0);
        if ($stored > 0) {
            $cursor = $stored;
        } else {
            $cursor = $until - ((int)$a['days'] * DAY_IN_SECONDS);
        }
    }

    // Ensure sane ordering
    if ($cursor > $until) {
        $cursor = $until - (7 * DAY_IN_SECONDS);
    }

    // --- Init Stripe ---
    try {
        if (class_exists('\Stripe\Stripe')) {
            \Stripe\Stripe::setApiKey($secret);
        } else {
            $res['errors'][] = 'Stripe SDK not loaded (class \\Stripe\\Stripe missing).';
            return $res;
        }
    } catch (\Throwable $e) {
        $res['errors'][] = 'Stripe init failed: ' . $e->getMessage();
        return $res;
    }

    $table = $wpdb->prefix . 'mwtools_deposits';

    // --- Page through payouts created within window ---
    $starting_after = null;
    $max_seen_created = $cursor;
    $page = 0;

    try {
        while (true) {
            $page++;
            if ($page > (int)$a['max_pages']) {
                $res['errors'][] = "Stopped: exceeded max_pages ({$a['max_pages']}).";
                break;
            }

            $params = [
                'limit'   => (int) $a['page_limit'],
                'created' => [
                    'gte' => $cursor,
                    'lte' => $until,
                ],
            ];
            if ($starting_after) {
                $params['starting_after'] = $starting_after;
            }

            /** @var \Stripe\Collection $payouts */
            $payouts = \Stripe\Payout::all($params);

            if (empty($payouts->data)) {
                break;
            }

            foreach ($payouts->data as $p) {
                // Track cursor (payout.created is the safest monotonic cursor)
                $p_created = (int) ($p->created ?? 0);
                if ($p_created > $max_seen_created) $max_seen_created = $p_created;

                $provider     = 'stripe';
                $provider_ref = (string) ($p->id ?? '');

                if ($provider_ref === '') {
                    $res['skipped']++;
                    continue;
                }

                // Stripe “deposit date” for bank arrival is arrival_date when present.
                // Fall back to created if arrival_date is missing.
                $deposit_ts = (int) ($p->arrival_date ?? $p_created);
                $deposit_date = gmdate('Y-m-d', $deposit_ts);

                $status = (string) ($p->status ?? 'unknown'); // paid, pending, in_transit, canceled, failed
                $amount = (int) ($p->amount ?? 0);            // cents
                $currency = strtolower((string) ($p->currency ?? 'usd'));

                // Convert cents->decimal string (avoid float drift)
                $total_amount = number_format($amount / 100, 2, '.', '');

                // Your schema fields
                $row = [
                    'provider'      => $provider,
                    'provider_ref'  => $provider_ref,
                    'deposit_type'  => 'digital',
                    'source'        => 'stripe',
                    'status'        => $status,
                    'deposit_date'  => $deposit_date,
                    'total_amount'  => $total_amount,
                    'batch_count'   => 0,
                    'updated_at'    => current_time('mysql'),
                ];

                // created_at should not change on updates
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(1) FROM {$table} WHERE provider=%s AND provider_ref=%s",
                        $provider,
                        $provider_ref
                    )
                );

                if ($a['dry_run']) {
                    if ($exists) $res['updated']++;
                    else $res['inserted']++;
                    continue;
                }

                if ($exists) {
                    $ok = $wpdb->update(
                        $table,
                        $row,
                        ['provider' => $provider, 'provider_ref' => $provider_ref],
                        ['%s','%s','%s','%s','%s','%s','%s','%d','%s'],
                        ['%s','%s']
                    );
                    if ($ok === false) {
                        $res['errors'][] = 'DB update failed for payout ' . $provider_ref . ': ' . $wpdb->last_error;
                    } else {
                        $res['updated']++;
                    }
                } else {
                    $row['created_at'] = current_time('mysql');
                    $ok = $wpdb->insert(
                        $table,
                        $row,
                        ['%s','%s','%s','%s','%s','%s','%s','%d','%s','%s']
                    );
                    if (!$ok) {
                        $res['errors'][] = 'DB insert failed for payout ' . $provider_ref . ': ' . $wpdb->last_error;
                    } else {
                        $res['inserted']++;
                    }
                }
            }

            // Pagination
            $last = end($payouts->data);
            $starting_after = $last ? (string) $last->id : null;

            if (!$payouts->has_more) {
                break;
            }
        }

        // Store cursor (next run starts after what we’ve seen)
        $res['last_cursor_ts'] = $max_seen_created;

        if (!$a['dry_run'] && $a['store_cursor'] && $max_seen_created > 0) {
            // Add 1 second so we don’t re-fetch the last record forever
            update_option($a['cursor_key'], $max_seen_created + 1, false);
        }

        $res['success'] = empty($res['errors']);

    } catch (\Stripe\Exception\ApiErrorException $e) {
        $res['errors'][] = 'Stripe API error: ' . $e->getMessage();
    } catch (\Throwable $e) {
        $res['errors'][] = 'Sync error: ' . $e->getMessage();
    }

    if ($a['log']) {
        if ( class_exists( 'Metis_Logger' ) ) {
            Metis_Logger::info( 'Stripe deposits sync result', $res );
        }
    }

    return $res;
}
