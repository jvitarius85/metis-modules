<?php
/**
 * Plugin Name: Metis Newsletter Archive Exporter
 * Description: Exports archived and sent newsletters from The Newsletter Plugin into the Metis newsletter archive import format.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'metis_newsletter_archive_exporter_boot' ) ) {
    function metis_newsletter_archive_exporter_boot(): void {
        add_action( 'admin_menu', 'metis_newsletter_archive_exporter_register_page' );
        add_action( 'admin_post_metis_newsletter_archive_export', 'metis_newsletter_archive_exporter_handle_download' );
    }

    function metis_newsletter_archive_exporter_register_page(): void {
        add_management_page(
            'Metis Newsletter Export',
            'Metis Newsletter Export',
            'manage_options',
            'metis-newsletter-export',
            'metis_newsletter_archive_exporter_render_page'
        );
    }

    function metis_newsletter_archive_exporter_render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to export newsletters.', 'metis' ) );
        }

        ?>
        <div class="wrap">
            <h1>Metis Newsletter Archive Export</h1>
            <p>Exports sent newsletters from The Newsletter Plugin into the JSON format used by the Metis import module.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="metis_newsletter_archive_export">
                <?php wp_nonce_field( 'metis_newsletter_archive_export' ); ?>
                <p>
                    <label for="metis-export-list-name"><strong>Archive list name</strong></label><br>
                    <input id="metis-export-list-name" name="metis_export_list_name" type="text" class="regular-text" value="Imported Newsletter Archive">
                </p>
                <p>
                    <label for="metis-export-list-ref"><strong>Archive list ref</strong></label><br>
                    <input id="metis-export-list-ref" name="metis_export_list_ref" type="text" class="regular-text" value="wp_newsletter_archive">
                </p>
                <p>
                    <button type="submit" name="metis_newsletter_archive_export" class="button button-primary">Download Export</button>
                </p>
            </form>
        </div>
        <?php
    }

    function metis_newsletter_archive_exporter_handle_download(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to export newsletters.', 'metis' ) );
        }

        check_admin_referer( 'metis_newsletter_archive_export' );
        metis_newsletter_archive_exporter_download();
    }

    function metis_newsletter_archive_exporter_download(): void {
        global $wpdb;

        $emails_table = defined( 'NEWSLETTER_EMAILS_TABLE' ) ? NEWSLETTER_EMAILS_TABLE : $wpdb->prefix . 'newsletter_emails';
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$emails_table}", 0 );
        if ( ! is_array( $columns ) || $columns === [] ) {
            wp_die( esc_html__( 'The Newsletter Plugin emails table was not found.', 'metis' ) );
        }

        $required = [ 'id', 'subject', 'status' ];
        foreach ( $required as $column ) {
            if ( ! in_array( $column, $columns, true ) ) {
                wp_die( esc_html( sprintf( 'Required newsletter export column missing: %s', $column ) ) );
            }
        }

        $html_column = metis_newsletter_archive_exporter_pick_column( $columns, [ 'message', 'message_html', 'html', 'body', 'content' ] );
        if ( $html_column === '' ) {
            wp_die( esc_html__( 'No newsletter HTML body column was found in the emails table.', 'metis' ) );
        }

        $text_column = metis_newsletter_archive_exporter_pick_column( $columns, [ 'message_text', 'text', 'text_body' ] );
        $preheader_column = metis_newsletter_archive_exporter_pick_column( $columns, [ 'excerpt', 'preheader', 'preview' ] );
        $from_name_column = metis_newsletter_archive_exporter_pick_column( $columns, [ 'from_name', 'sender_name' ] );
        $from_email_column = metis_newsletter_archive_exporter_pick_column( $columns, [ 'from_email', 'sender_email' ] );
        $reply_to_column = metis_newsletter_archive_exporter_pick_column( $columns, [ 'reply_to', 'reply_email' ] );
        $sent_column = metis_newsletter_archive_exporter_pick_column( $columns, [ 'sent_at', 'send_on', 'updated_at', 'created_at' ] );
        $updated_column = metis_newsletter_archive_exporter_pick_column( $columns, [ 'updated_at', 'modified_at', 'created_at', 'send_on' ] );
        $title_column = metis_newsletter_archive_exporter_pick_column( $columns, [ 'name', 'title' ] );

        $select_columns = [ 'id', 'subject', 'status', $html_column ];
        foreach ( [ $text_column, $preheader_column, $from_name_column, $from_email_column, $reply_to_column, $sent_column, $updated_column, $title_column ] as $column ) {
            if ( $column !== '' && ! in_array( $column, $select_columns, true ) ) {
                $select_columns[] = $column;
            }
        }

        $rows = $wpdb->get_results(
            "SELECT " . implode( ', ', array_map( static fn ( string $column ): string => "`{$column}`", $select_columns ) ) . "
             FROM {$emails_table}
             WHERE status IN ('sent', 'archived')
             ORDER BY id DESC",
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            wp_die( esc_html__( 'Unable to read newsletters from The Newsletter Plugin.', 'metis' ) );
        }

        $list_name = sanitize_text_field( wp_unslash( (string) filter_input( INPUT_POST, 'metis_export_list_name', FILTER_UNSAFE_RAW ) ?: 'Imported Newsletter Archive' ) );
        $list_ref = sanitize_key( wp_unslash( (string) filter_input( INPUT_POST, 'metis_export_list_ref', FILTER_UNSAFE_RAW ) ?: 'wp_newsletter_archive' ) );
        if ( $list_name === '' ) {
            $list_name = 'Imported Newsletter Archive';
        }
        if ( $list_ref === '' ) {
            $list_ref = 'wp_newsletter_archive';
        }

        $newsletters = [];
        foreach ( $rows as $row ) {
            $status = strtolower( trim( (string) ( $row['status'] ?? '' ) ) );
            $subject = trim( (string) ( $row['subject'] ?? '' ) );
            $html_body = trim( (string) ( $row[ $html_column ] ?? '' ) );
            if ( $subject === '' || $html_body === '' ) {
                continue;
            }

            $newsletters[] = [
                'source_id' => (int) ( $row['id'] ?? 0 ),
                'uid' => strtoupper( substr( sha1( home_url( '/' ) . '|' . (string) ( $row['id'] ?? '' ) . '|' . $subject ), 0, 16 ) ),
                'title' => $title_column !== '' ? trim( (string) ( $row[ $title_column ] ?? '' ) ) : $subject,
                'subject' => $subject,
                'preheader' => $preheader_column !== '' ? trim( (string) ( $row[ $preheader_column ] ?? '' ) ) : '',
                'from_name' => $from_name_column !== '' ? trim( (string) ( $row[ $from_name_column ] ?? '' ) ) : '',
                'from_email' => $from_email_column !== '' ? trim( (string) ( $row[ $from_email_column ] ?? '' ) ) : '',
                'reply_to' => $reply_to_column !== '' ? trim( (string) ( $row[ $reply_to_column ] ?? '' ) ) : '',
                'html_body' => $html_body,
                'text_body' => $text_column !== '' ? trim( (string) ( $row[ $text_column ] ?? '' ) ) : wp_strip_all_tags( $html_body ),
                'sent_at' => $sent_column !== '' ? trim( (string) ( $row[ $sent_column ] ?? '' ) ) : '',
                'updated_at' => $updated_column !== '' ? trim( (string) ( $row[ $updated_column ] ?? '' ) ) : '',
                'source_status' => $status,
                'list_refs' => [ $list_ref ],
                'list_names' => [ $list_name ],
            ];
        }

        $payload = [
            'format' => 'metis.wordpress.newsletter_archive.v1',
            'source' => [
                'generator' => 'The Newsletter Plugin',
                'site_title' => get_bloginfo( 'name' ),
                'site_url' => home_url( '/' ),
                'language' => get_bloginfo( 'language' ),
                'exported_at' => gmdate( 'c' ),
            ],
            'default_list' => [
                'ref' => $list_ref,
                'name' => $list_name,
                'description' => 'Archived newsletters exported from WordPress.',
            ],
            'newsletters' => $newsletters,
        ];

        $filename = 'metis-newsletter-archive-' . gmdate( 'Ymd-His' ) . '.json';
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        exit;
    }

    function metis_newsletter_archive_exporter_pick_column( array $columns, array $candidates ): string {
        foreach ( $candidates as $candidate ) {
            if ( in_array( $candidate, $columns, true ) ) {
                return $candidate;
            }
        }

        return '';
    }

    metis_newsletter_archive_exporter_boot();
}
