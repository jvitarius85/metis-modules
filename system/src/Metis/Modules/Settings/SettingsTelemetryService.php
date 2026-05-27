<?php
declare(strict_types=1);

namespace Metis\Modules\Settings;

final class SettingsTelemetryService {
    public static function stripeWebhookSnapshot( string $provider, int $days = 1 ): array {
        $snapshot = [
            'webhook_events' => 0,
            'webhook_processed' => 0,
            'webhook_failed' => 0,
            'provider_failure_count' => 0,
        ];

        try {
            if ( function_exists( 'metis_webhook_ensure_schema' ) ) {
                \metis_webhook_ensure_schema();
            }

            $table = class_exists( 'Metis_Tables' ) ? \Metis_Tables::get( 'webhook_events' ) : '';
            if ( $table !== '' ) {
                $cutoff = function_exists( 'metis_settings_recent_cutoff' )
                    ? \metis_settings_recent_cutoff( $days )
                    : gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, $days ) . ' day' ) );
                $snapshot['webhook_events'] = (int) \metis_db()->scalar(
                    "SELECT COUNT(1) FROM {$table} WHERE provider = %s AND created_at >= %s",
                    [ $provider, $cutoff ]
                );
                $snapshot['webhook_processed'] = (int) \metis_db()->scalar(
                    "SELECT COUNT(1) FROM {$table} WHERE provider = %s AND status = %s AND created_at >= %s",
                    [ $provider, 'processed', $cutoff ]
                );
                $snapshot['webhook_failed'] = (int) \metis_db()->scalar(
                    "SELECT COUNT(1) FROM {$table} WHERE provider = %s AND status = %s AND created_at >= %s",
                    [ $provider, 'failed', $cutoff ]
                );
            }
        } catch ( \Throwable $e ) {
            // Diagnostics must stay non-fatal.
        }

        if ( function_exists( 'metis_webhook_provider_failure_timestamps' ) ) {
            $snapshot['provider_failure_count'] = count( \metis_webhook_provider_failure_timestamps( $provider ) );
        }

        return $snapshot;
    }

    public static function codeLookupStatus(): array {
        if ( ! class_exists( '\Metis\Core\EntityCatalog' ) || ! function_exists( 'metis_entity_id_service' ) || ! class_exists( 'Metis_Tables' ) ) {
            return [
                'status' => 'fail',
                'message' => 'Code lookup registry cannot be inspected because entity services are unavailable.',
                'recommendation' => 'Verify core service registration, then run auto-remediate to rehydrate code lookup data.',
            ];
        }

        try {
            $service = \metis_entity_id_service();
            $service->ensureSchema();
            $db = \metis_db();
            $registry_table = \Metis_Tables::get( 'entity_registry' );
            $entity_rows = 0;
            $registry_rows = 0;
            $entity_types = 0;
            $missing_columns = [];

            foreach ( \Metis\Core\EntityCatalog::definitions() as $entity_type => $definition ) {
                $table_key = (string) ( $definition['table_key'] ?? '' );
                $uid_column = (string) ( $definition['uid_column'] ?? '' );
                if ( $table_key === '' || $uid_column === '' || ! \Metis_Tables::has( $table_key ) ) {
                    continue;
                }

                $table = \Metis_Tables::get( $table_key );
                if ( ! $service->tableExists( $table ) || ! $service->columnExists( $table, 'id' ) ) {
                    continue;
                }

                $entity_types++;
                if ( ! $service->columnExists( $table, $uid_column ) ) {
                    $missing_columns[] = $table . '.' . $uid_column;
                    continue;
                }

                $where = trim( (string) ( $definition['where'] ?? '' ) );
                $count_sql = "SELECT COUNT(1) FROM {$table} WHERE id > 0";
                if ( $where !== '' ) {
                    $count_sql .= " AND ({$where})";
                }

                $row_count = (int) $db->scalar( $count_sql );
                $entity_rows += $row_count;
                $registry_rows += (int) $db->scalar(
                    "SELECT COUNT(1)
                     FROM {$registry_table}
                     WHERE entity_type = %s
                       AND entity_table = %s",
                    [ (string) $entity_type, $table ]
                );
            }

            $registry_gap = max( 0, $entity_rows - $registry_rows );
            if ( ! empty( $missing_columns ) ) {
                return [
                    'status' => 'fail',
                    'message' => 'Code lookup registry is missing UID columns: ' . implode( ', ', array_slice( $missing_columns, 0, 5 ) ) . ( count( $missing_columns ) > 5 ? ', ...' : '' ) . '.',
                    'recommendation' => 'Run auto-remediate to repair entity schema and rehydrate code lookup records.',
                ];
            }

            if ( $entity_rows > 0 && $registry_rows < 1 ) {
                return [
                    'status' => 'fail',
                    'message' => sprintf( 'Code lookup registry has no rows for %s code-backed entity records.', number_format( $entity_rows ) ),
                    'recommendation' => 'Run auto-remediate to rehydrate the central code lookup registry.',
                ];
            }

            if ( $registry_gap > max( 25, (int) floor( $entity_rows * 0.1 ) ) ) {
                return [
                    'status' => 'warn',
                    'message' => sprintf(
                        'Code lookup registry trails source entities by %s rows across %s entity types (%s source rows, %s registry rows).',
                        number_format( $registry_gap ),
                        number_format( $entity_types ),
                        number_format( $entity_rows ),
                        number_format( $registry_rows )
                    ),
                    'recommendation' => 'Run code lookup rehydration to close the registry gap.',
                ];
            }

            return [
                'status' => 'pass',
                'message' => sprintf(
                    'Code lookup registry covers %s entity rows across %s entity types with %s registry rows.',
                    number_format( $entity_rows ),
                    number_format( $entity_types ),
                    number_format( $registry_rows )
                ),
                'recommendation' => '',
            ];
        } catch ( \Throwable $exception ) {
            return [
                'status' => 'fail',
                'message' => 'Code lookup registry inspection failed: ' . $exception->getMessage(),
                'recommendation' => 'Verify entity registry schema and rerun auto-remediate.',
            ];
        }
    }

    public static function securityPressureSummary( string $security_cutoff ): array {
        $summary = [
            'brute_force_total' => 0,
            'rate_limit_total' => 0,
            'brute_force_top' => [],
            'rate_limit_top' => [],
        ];

        if ( ! class_exists( 'Metis_Tables' ) ) {
            return $summary;
        }

        $security_table = \Metis_Tables::get( 'audit_security' );
        if ( $security_table === '' ) {
            return $summary;
        }

        $security_totals = \metis_db()->fetchAll(
            "SELECT
                SUM(
                    CASE
                        WHEN LOWER(action_type) = 'auth_failed_login'
                            OR LOWER(action_type) = 'auth_login_throttled'
                            OR LOWER(action_type) = 'login_failed'
                            OR LOWER(action_type) = 'login_failure'
                            OR LOWER(action_type) LIKE '%brute%'
                            OR LOWER(action_type) LIKE '%credential%'
                        THEN 1 ELSE 0
                    END
                ) AS brute_total,
                SUM(
                    CASE
                        WHEN LOWER(action_type) = 'security_rate_limit_triggered'
                            OR LOWER(action_type) = 'enclave.denied_rate_limit'
                            OR LOWER(action_type) = 'rate_limited'
                            OR LOWER(action_type) LIKE '%rate_limit%'
                            OR LOWER(action_type) LIKE '%rate-lim%'
                            OR LOWER(action_type) LIKE '%429%'
                        THEN 1 ELSE 0
                    END
                 ) AS rate_total
             FROM {$security_table}
             WHERE occurred_at >= %s",
            [ $security_cutoff ]
        );
        $security_totals_row = is_array( $security_totals ) && isset( $security_totals[0] ) && is_array( $security_totals[0] )
            ? $security_totals[0]
            : [];
        $summary['brute_force_total'] = (int) ( $security_totals_row['brute_total'] ?? 0 );
        $summary['rate_limit_total'] = (int) ( $security_totals_row['rate_total'] ?? 0 );

        $brute_force_top_rows = \metis_db()->fetchAll(
            "SELECT module_slug, action_type, resource_label, COUNT(*) AS total
             FROM {$security_table}
             WHERE occurred_at >= %s
               AND (
                    LOWER(action_type) = 'auth_failed_login'
                    OR LOWER(action_type) = 'auth_login_throttled'
                    OR LOWER(action_type) = 'login_failed'
                    OR LOWER(action_type) = 'login_failure'
                    OR LOWER(action_type) LIKE '%brute%'
                    OR LOWER(action_type) LIKE '%credential%'
               )
             GROUP BY module_slug, action_type, resource_label
             ORDER BY total DESC
             LIMIT 3",
            [ $security_cutoff ]
        ) ?: [];
        foreach ( $brute_force_top_rows as $offense_row ) {
            $descriptor = self::telemetryDescriptor( $offense_row );
            $count = (int) ( $offense_row['total'] ?? 0 );
            if ( $count > 0 && $descriptor !== '' ) {
                $summary['brute_force_top'][] = $descriptor . ': ' . $count;
            }
        }

        $rate_limit_top_rows = \metis_db()->fetchAll(
            "SELECT module_slug, action_type, resource_label, COUNT(*) AS total
             FROM {$security_table}
             WHERE occurred_at >= %s
               AND (
                    LOWER(action_type) = 'security_rate_limit_triggered'
                    OR LOWER(action_type) = 'enclave.denied_rate_limit'
                    OR LOWER(action_type) = 'rate_limited'
                    OR LOWER(action_type) LIKE '%rate_limit%'
                    OR LOWER(action_type) LIKE '%rate-lim%'
                    OR LOWER(action_type) LIKE '%429%'
               )
             GROUP BY module_slug, action_type, resource_label
             ORDER BY total DESC
             LIMIT 3",
            [ $security_cutoff ]
        ) ?: [];
        foreach ( $rate_limit_top_rows as $offense_row ) {
            $descriptor = self::telemetryDescriptor( $offense_row );
            $count = (int) ( $offense_row['total'] ?? 0 );
            if ( $count > 0 && $descriptor !== '' ) {
                $summary['rate_limit_top'][] = $descriptor . ': ' . $count;
            }
        }

        return $summary;
    }

    public static function webhookHealthSummary( int $days = 7 ): array {
        $summary = [
            'processed' => 0,
            'failed' => 0,
            'last_processed_at' => '',
        ];

        if ( ! function_exists( 'metis_webhook_ensure_schema' ) || ! class_exists( 'Metis_Tables' ) ) {
            return $summary;
        }

        \metis_webhook_ensure_schema();
        $webhook_table = \Metis_Tables::get( 'webhook_events' );
        if ( $webhook_table === '' ) {
            return $summary;
        }

        $webhook_cutoff = function_exists( 'metis_settings_recent_cutoff' )
            ? \metis_settings_recent_cutoff( $days )
            : gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, $days ) . ' day' ) );
        $webhook_summary_rows = \metis_db()->fetchAll(
            "SELECT status, COUNT(*) AS total
             FROM {$webhook_table}
             WHERE created_at >= %s
             GROUP BY status",
            [ $webhook_cutoff ]
        );
        foreach ( $webhook_summary_rows as $summary_row ) {
            $status = strtolower( (string) ( $summary_row['status'] ?? '' ) );
            $total = (int) ( $summary_row['total'] ?? 0 );
            if ( $status === 'processed' ) {
                $summary['processed'] += $total;
            } elseif ( $status === 'failed' ) {
                $summary['failed'] += $total;
            }
        }

        $summary['last_processed_at'] = (string) \metis_db()->scalar(
            "SELECT MAX(processed_at) FROM {$webhook_table} WHERE status = 'processed'"
        );

        return $summary;
    }

    public static function failedLoginSnapshot( string $date_format, string $time_format, string $timezone, int $limit = 25 ): array {
        $snapshot = [
            'rows' => [],
            'count_24h' => 0,
            'count_7d' => 0,
            'error' => '',
        ];

        if ( ! class_exists( 'Metis_Tables' ) ) {
            $snapshot['error'] = 'Audit table registry is unavailable.';
            return $snapshot;
        }

        try {
            if ( function_exists( 'metis_audit_ensure_schema' ) ) {
                \metis_audit_ensure_schema();
            }

            $table = \Metis_Tables::get( 'audit_security' );
            $events = [
                'auth_failed_login',
                'auth_login_throttled',
                'login_failed',
                'login_failure',
                'primary_auth_user_failed',
                'primary_password_failed',
            ];
            $placeholders = implode( ', ', array_fill( 0, count( $events ), '%s' ) );
            $limit = max( 5, min( 100, $limit ) );
            $cutoff_24h = function_exists( 'metis_settings_recent_cutoff' ) ? \metis_settings_recent_cutoff( 1 ) : gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
            $cutoff_7d = function_exists( 'metis_settings_recent_cutoff' ) ? \metis_settings_recent_cutoff( 7 ) : gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

            $snapshot['count_24h'] = (int) \metis_db()->scalar(
                "SELECT COUNT(1)
                 FROM {$table}
                 WHERE occurred_at >= %s
                   AND LOWER(action_type) IN ({$placeholders})",
                array_merge( [ $cutoff_24h ], $events )
            );
            $snapshot['count_7d'] = (int) \metis_db()->scalar(
                "SELECT COUNT(1)
                 FROM {$table}
                 WHERE occurred_at >= %s
                   AND LOWER(action_type) IN ({$placeholders})",
                array_merge( [ $cutoff_7d ], $events )
            );

            $rows = \metis_db()->fetchAll(
                "SELECT id, occurred_at, action_type, severity, outcome, resource_label, ip_address, user_agent, context_json
                 FROM {$table}
                 WHERE occurred_at >= %s
                   AND LOWER(action_type) IN ({$placeholders})
                 ORDER BY occurred_at DESC, id DESC
                 LIMIT %d",
                array_merge( [ $cutoff_7d ], $events, [ $limit ] )
            ) ?: [];

            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $context = [];
                $context_raw = (string) ( $row['context_json'] ?? '' );
                if ( $context_raw !== '' ) {
                    if ( function_exists( 'metis_audit_decode_context_json' ) ) {
                        $context = \metis_audit_decode_context_json( $context_raw );
                    } else {
                        $decoded = json_decode( $context_raw, true );
                        $context = is_array( $decoded ) ? $decoded : [];
                    }
                }

                $account = trim( (string) ( $row['resource_label'] ?? '' ) );
                if ( $account === '' ) {
                    $account = trim( (string) ( $context['username'] ?? $context['identifier'] ?? $context['subject'] ?? '' ) );
                }
                $request_context = is_array( $context['request'] ?? null ) ? (array) $context['request'] : [];
                if ( $account === '' && isset( $request_context['identifier'] ) ) {
                    $account = trim( (string) $request_context['identifier'] );
                }

                $subject_failures = isset( $context['subject_failures'] ) ? (int) $context['subject_failures'] : null;
                $ip_failures = isset( $context['ip_failures'] ) ? (int) $context['ip_failures'] : null;
                $score = isset( $context['score'] ) ? (int) $context['score'] : null;
                $attempts = [];
                if ( $subject_failures !== null ) {
                    $attempts[] = 'Subject ' . $subject_failures;
                }
                if ( $ip_failures !== null ) {
                    $attempts[] = 'IP ' . $ip_failures;
                }

                $snapshot['rows'][] = [
                    'id' => (int) ( $row['id'] ?? 0 ),
                    'occurred_at' => (string) ( $row['occurred_at'] ?? '' ),
                    'occurred_at_display' => function_exists( 'metis_settings_format_datetime_display' )
                        ? \metis_settings_format_datetime_display( (string) ( $row['occurred_at'] ?? '' ), $date_format, $time_format, $timezone )
                        : (string) ( $row['occurred_at'] ?? '' ),
                    'event' => (string) ( $row['action_type'] ?? '' ),
                    'severity' => (string) ( $row['severity'] ?? '' ),
                    'outcome' => (string) ( $row['outcome'] ?? '' ),
                    'account' => $account !== '' ? $account : 'Unknown',
                    'ip_address' => (string) ( $row['ip_address'] ?? '' ),
                    'user_agent' => (string) ( $row['user_agent'] ?? '' ),
                    'attempts' => implode( ' / ', $attempts ),
                    'risk_score' => $score,
                ];
            }
        } catch ( \Throwable $exception ) {
            $snapshot['error'] = 'Failed login snapshot failed: ' . $exception->getMessage();
        }

        return $snapshot;
    }

    public static function emailUsageSnapshot(): array {
        $db = \metis_db();
        $today = \metis_runtime_date( 'Y-m-d', time(), \metis_newsletter_resolved_timezone() );
        $daily_limit = function_exists( 'metis_newsletter_google_usage_daily_limit' ) ? \metis_newsletter_google_usage_daily_limit() : 2000;

        $messages_table = \Metis_Tables::get( 'newsletter_messages' );
        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $google_usage_table = \Metis_Tables::get( 'newsletter_google_usage_daily' );
        $service_usage_table = \Metis_Tables::get( 'email_usage_daily' );
        $service_events_table = \Metis_Tables::get( 'email_send_events' );

        $newsletter_sent_total = (int) $db->scalar( "SELECT COUNT(*) FROM {$messages_table} WHERE status='sent'" );
        $newsletter_30 = (int) $db->scalar(
            "SELECT COUNT(*) FROM {$messages_table} WHERE status='sent' AND sent_at >= %s",
            [ ( new \DateTimeImmutable( 'now', \metis_newsletter_resolved_timezone() ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' ) ]
        );
        $newsletter_campaigns = (int) $db->scalar( "SELECT COUNT(*) FROM {$campaigns_table}" );

        $google_today_sent = 0;
        $google_daily_rows = [];
        if ( function_exists( 'metis_newsletter_table_exists' ) && \metis_newsletter_table_exists( $google_usage_table ) ) {
            $google_today_sent = (int) $db->scalar(
                "SELECT COALESCE(SUM(sent_count), 0) FROM {$google_usage_table} WHERE usage_date = %s",
                [ $today ]
            );
            $google_daily_rows = $db->fetchAll(
                "SELECT usage_date, COALESCE(SUM(sent_count), 0) AS sent_total
                 FROM {$google_usage_table}
                 GROUP BY usage_date
                 ORDER BY usage_date DESC
                 LIMIT 30"
            ) ?: [];
        }
        $google_today_pct = $daily_limit > 0 ? min( 100, max( 0, (int) round( ( $google_today_sent / $daily_limit ) * 100 ) ) ) : 0;

        if ( class_exists( '\Metis\Core\Services\EmailService' ) ) {
            \Metis\Core\Services\EmailService::ensureUsageTrackingReady();
        }

        $service_module_rows = [];
        $service_today_total = 0;
        $service_30_total = 0;
        $service_all_total = 0;
        $service_usage_exists = false;
        $service_event_rows = [];
        try {
            $table_check = $db->fetchOne( 'SHOW TABLES LIKE %s', [ $service_usage_table ] );
            $service_usage_exists = ! empty( $table_check );
        } catch ( \Throwable $e ) {
            $service_usage_exists = false;
        }
        if ( $service_usage_exists ) {
            $service_module_rows = $db->fetchAll(
                "SELECT module_slug,
                        SUM(CASE WHEN usage_date = CURDATE() THEN sent_count ELSE 0 END) AS today_sent,
                        SUM(CASE WHEN usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN sent_count ELSE 0 END) AS sent_30d,
                        SUM(sent_count) AS sent_all,
                        SUM(failed_count) AS failed_all
                 FROM {$service_usage_table}
                 GROUP BY module_slug
                 ORDER BY sent_30d DESC, sent_all DESC, module_slug ASC"
            ) ?: [];
            $service_today_total = (int) $db->scalar( "SELECT COALESCE(SUM(sent_count), 0) FROM {$service_usage_table} WHERE usage_date = CURDATE()" );
            $service_30_total = (int) $db->scalar( "SELECT COALESCE(SUM(sent_count), 0) FROM {$service_usage_table} WHERE usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)" );
            $service_all_total = (int) $db->scalar( "SELECT COALESCE(SUM(sent_count), 0) FROM {$service_usage_table}" );
        }
        try {
            $event_table_check = $db->fetchOne( 'SHOW TABLES LIKE %s', [ $service_events_table ] );
            if ( ! empty( $event_table_check ) ) {
                $service_event_rows = $db->fetchAll(
                    "SELECT event_at, module_slug, status, provider, to_email, subject, error_message
                     FROM {$service_events_table}
                     ORDER BY event_at DESC
                     LIMIT 25"
                ) ?: [];
            }
        } catch ( \Throwable $e ) {
            $service_event_rows = [];
        }

        return [
            'google_today_sent' => $google_today_sent,
            'google_today_pct' => $google_today_pct,
            'google_daily_limit' => $daily_limit,
            'google_daily_rows' => $google_daily_rows,
            'newsletter_sent_total' => $newsletter_sent_total,
            'newsletter_30' => $newsletter_30,
            'newsletter_campaigns' => $newsletter_campaigns,
            'service_module_rows' => $service_module_rows,
            'service_event_rows' => $service_event_rows,
            'service_today_total' => $service_today_total,
            'service_30_total' => $service_30_total,
            'service_all_total' => $service_all_total,
        ];
    }

    private static function telemetryDescriptor( array $row ): string {
        $module = trim( (string) ( $row['module_slug'] ?? '' ) );
        $action = trim( (string) ( $row['action_type'] ?? '' ) );
        $resource = trim( (string) ( $row['resource_label'] ?? '' ) );
        $descriptor = ( $module !== '' ? $module : 'unknown-module' ) . '/' . ( $action !== '' ? $action : 'unknown-action' );
        if ( $resource !== '' ) {
            $descriptor .= ' [' . $resource . ']';
        }
        return $descriptor;
    }
}
