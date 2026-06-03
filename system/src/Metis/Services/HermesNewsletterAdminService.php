<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Modules\Newsletter\CampaignService;
use Metis\Modules\Newsletter\NewsletterModule;
use Metis\Modules\Newsletter\QueueService;

final class HermesNewsletterAdminService {
    public function createCampaign( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $title = trim( (string) ( $request['title'] ?? $request['name'] ?? '' ) );
        if ( $title === '' ) {
            throw new \RuntimeException( 'A campaign name is required.' );
        }

        NewsletterModule::ensureSchema();
        $result = CampaignService::save(
            0,
            $this->campaignPayload( $request, true ),
            $this->campaignPayloadFormats( $request, true ),
            $this->normalizedListIds( $request )
        );

        if ( empty( $result['success'] ) || (int) ( $result['campaign_id'] ?? 0 ) < 1 ) {
            throw new \RuntimeException( 'Failed to create newsletter campaign.' );
        }

        $campaign = (array) ( $result['campaign'] ?? [] );
        return [
            'status' => 'success',
            'campaign' => $this->campaignSummary( $campaign, (int) ( $result['campaign_id'] ?? 0 ) ),
            'message' => sprintf( 'Created campaign "%s".', (string) ( $campaign['name'] ?? $title ) ),
        ];
    }

    public function updateCampaign( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $campaignId = $this->requireCampaignId( $request );
        NewsletterModule::ensureSchema();

        $payload = $this->campaignPayload( $request, false );
        if ( $payload === [] ) {
            throw new \RuntimeException( 'No campaign fields were provided to update.' );
        }

        $result = CampaignService::save(
            $campaignId,
            $payload,
            $this->campaignPayloadFormatsFromPayload( $payload ),
            array_key_exists( 'list_ids', $request )
                ? $this->normalizedListIds( $request )
                : CampaignService::listIds( $campaignId )
        );

        if ( empty( $result['success'] ) ) {
            throw new \RuntimeException( 'Failed to update newsletter campaign.' );
        }

        $campaign = (array) ( $result['campaign'] ?? [] );
        return [
            'status' => 'success',
            'campaign' => $this->campaignSummary( $campaign, $campaignId ),
            'updated_fields' => array_keys( $payload ),
            'message' => sprintf( 'Updated campaign "%s".', (string) ( $campaign['name'] ?? $request['subject'] ?? 'campaign' ) ),
        ];
    }

    public function sendCampaign( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $campaignId = $this->requireCampaignId( $request );
        NewsletterModule::ensureSchema();

        $saved = CampaignService::save(
            $campaignId,
            [
                'scheduled_at' => null,
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ '%s', '%s' ],
            CampaignService::listIds( $campaignId )
        );
        if ( empty( $saved['success'] ) ) {
            throw new \RuntimeException( 'Failed to prepare the campaign for sending.' );
        }

        $queued = QueueService::queueCampaignMessages( $campaignId );
        if ( empty( $queued['ok'] ) ) {
            throw new \RuntimeException( (string) ( $queued['message'] ?? 'Failed to queue campaign messages.' ) );
        }

        $campaign = (array) ( CampaignService::get( $campaignId ) ?? [] );
        return [
            'status' => 'success',
            'campaign' => $this->campaignSummary( $campaign, $campaignId ),
            'queued' => (int) ( $queued['queued'] ?? 0 ),
            'message' => sprintf( 'Queued campaign "%s" for sending.', (string) ( $campaign['name'] ?? $request['subject'] ?? 'campaign' ) ),
        ];
    }

    public function scheduleCampaign( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $campaignId = $this->requireCampaignId( $request );
        $scheduledAt = trim( (string) ( $request['scheduled_at'] ?? '' ) );
        if ( $scheduledAt === '' ) {
            throw new \RuntimeException( 'A scheduled send time is required.' );
        }

        NewsletterModule::ensureSchema();
        $saved = CampaignService::save(
            $campaignId,
            [
                'scheduled_at' => $scheduledAt,
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ '%s', '%s' ],
            CampaignService::listIds( $campaignId )
        );
        if ( empty( $saved['success'] ) ) {
            throw new \RuntimeException( 'Failed to save the campaign schedule.' );
        }

        $queued = QueueService::queueCampaignMessages( $campaignId );
        if ( empty( $queued['ok'] ) ) {
            throw new \RuntimeException( (string) ( $queued['message'] ?? 'Failed to queue scheduled campaign messages.' ) );
        }

        $campaign = (array) ( CampaignService::get( $campaignId ) ?? [] );
        return [
            'status' => 'success',
            'campaign' => $this->campaignSummary( $campaign, $campaignId ),
            'scheduled_at' => $scheduledAt,
            'queued' => (int) ( $queued['queued'] ?? 0 ),
            'message' => sprintf( 'Scheduled campaign "%s".', (string) ( $campaign['name'] ?? $request['subject'] ?? 'campaign' ) ),
        ];
    }

    public function cancelCampaign( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $campaignId = $this->requireCampaignId( $request );
        NewsletterModule::ensureSchema();

        $campaign = (array) ( CampaignService::rawById( $campaignId ) ?? [] );
        if ( $campaign === [] ) {
            throw new \RuntimeException( 'Campaign not found.' );
        }

        $status = strtolower( trim( (string) ( $campaign['status'] ?? 'draft' ) ) );
        if ( in_array( $status, [ 'sending', 'sent', 'archived' ], true ) ) {
            throw new \RuntimeException( 'Sent, sending, or archived campaigns cannot be canceled.' );
        }
        if ( ! in_array( $status, [ 'scheduled', 'queued' ], true ) ) {
            throw new \RuntimeException( 'Only queued or scheduled campaigns can be canceled.' );
        }

        $db = \metis_db();
        $messagesTable = \Metis_Tables::get( 'newsletter_messages' );
        $queuedDeleted = (int) $db->execute(
            $db->prepare(
                "DELETE FROM {$messagesTable}
                 WHERE campaign_id = %d
                   AND status = %s",
                $campaignId,
                'queued'
            )
        );

        $saved = CampaignService::save(
            $campaignId,
            [
                'status' => 'draft',
                'scheduled_at' => null,
                'queued_at' => null,
                'sent_at' => null,
                'total_recipients' => 0,
                'sent_count' => 0,
                'failed_count' => 0,
                'bounced_count' => 0,
                'rejected_count' => 0,
                'last_error' => null,
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ],
            CampaignService::listIds( $campaignId )
        );
        if ( empty( $saved['success'] ) ) {
            throw new \RuntimeException( 'Failed to cancel the scheduled campaign.' );
        }

        $updatedCampaign = (array) ( $saved['campaign'] ?? [] );
        return [
            'status' => 'success',
            'campaign' => $this->campaignSummary( $updatedCampaign, $campaignId ),
            'canceled_messages' => $queuedDeleted,
            'message' => sprintf( 'Canceled newsletter "%s".', (string) ( $updatedCampaign['name'] ?? $request['subject'] ?? 'campaign' ) ),
        ];
    }

    public function archiveCampaign( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $campaignId = $this->requireCampaignId( $request );
        NewsletterModule::ensureSchema();

        if ( ! CampaignService::archive( $campaignId ) ) {
            throw new \RuntimeException( 'Failed to archive the campaign.' );
        }

        $campaign = (array) ( CampaignService::get( $campaignId ) ?? [] );
        return [
            'status' => 'success',
            'campaign' => $this->campaignSummary( $campaign, $campaignId ),
            'message' => sprintf( 'Archived campaign "%s".', (string) ( $campaign['name'] ?? $request['subject'] ?? 'campaign' ) ),
        ];
    }

    public function deleteCampaign( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $campaignId = $this->requireCampaignId( $request );
        NewsletterModule::ensureSchema();

        $result = CampaignService::delete( $campaignId );
        if ( empty( $result['success'] ) ) {
            throw new \RuntimeException( (string) ( $result['message'] ?? 'Failed to delete the campaign.' ) );
        }

        return [
            'status' => 'success',
            'campaign_id' => $campaignId,
            'message' => (string) ( $result['message'] ?? 'Campaign deleted.' ),
        ];
    }

    private function requireCampaignId( array $request ): int {
        $subject = trim( (string) ( $request['subject'] ?? $request['campaign_code'] ?? $request['campaign_id'] ?? '' ) );
        $campaignId = $this->resolveCampaignId( $subject );
        if ( $campaignId < 1 ) {
            throw new \RuntimeException( 'Specify a valid campaign name, code, or ID.' );
        }

        return $campaignId;
    }

    private function resolveCampaignId( string $reference ): int {
        $reference = trim( $reference );
        if ( $reference === '' ) {
            return 0;
        }

        if ( ctype_digit( $reference ) ) {
            return (int) $reference;
        }

        $campaignId = CampaignService::resolveId( $reference );
        if ( $campaignId > 0 ) {
            return $campaignId;
        }

        $table = \Metis_Tables::get( 'newsletter_campaigns' );
        return (int) \metis_db()->scalar(
            "SELECT id
             FROM {$table}
             WHERE LOWER(COALESCE(name, '')) = %s
             ORDER BY id DESC
             LIMIT 1",
            [ strtolower( $reference ) ]
        );
    }

    private function campaignPayload( array $request, bool $includeRequired ): array {
        $payload = [];
        $map = [
            'title' => 'name',
            'name' => 'name',
            'subject_line' => 'subject',
            'subject' => 'subject',
            'from_name' => 'from_name',
            'from_email' => 'from_email',
            'reply_to' => 'reply_to',
            'preheader' => 'preheader',
            'doc_json' => 'doc_json',
            'editor_body_html' => 'editor_body_html',
            'html_body' => 'html_body',
            'text_body' => 'text_body',
            'scheduled_at' => 'scheduled_at',
        ];

        foreach ( $map as $inputKey => $field ) {
            if ( ! array_key_exists( $inputKey, $request ) ) {
                continue;
            }

            $value = $request[ $inputKey ];
            if ( is_string( $value ) ) {
                $value = trim( $value );
            }
            $payload[ $field ] = $value;
        }

        if ( array_key_exists( 'audience', $request ) ) {
            $payload['audience_json'] = is_string( $request['audience'] )
                ? trim( (string) $request['audience'] )
                : \metis_json_encode( $request['audience'] );
        }

        if ( array_key_exists( 'attachments', $request ) ) {
            $payload['attachments_json'] = is_string( $request['attachments'] )
                ? trim( (string) $request['attachments'] )
                : \metis_json_encode( $request['attachments'] );
        }

        if ( $includeRequired && ! isset( $payload['name'] ) ) {
            $payload['name'] = trim( (string) ( $request['title'] ?? $request['name'] ?? '' ) );
        }

        if ( $includeRequired && ! isset( $payload['status'] ) ) {
            $payload['status'] = 'draft';
        } elseif ( array_key_exists( 'status', $request ) ) {
            $payload['status'] = \metis_key_clean( (string) $request['status'] ) ?: 'draft';
        }

        $payload['updated_at'] = \metis_current_time( 'mysql' );
        return $payload;
    }

    private function campaignPayloadFormats( array $request, bool $includeRequired ): array {
        return $this->campaignPayloadFormatsFromPayload( $this->campaignPayload( $request, $includeRequired ) );
    }

    private function campaignPayloadFormatsFromPayload( array $payload ): array {
        $fieldFormats = [
            'template_id' => '%d',
            'name' => '%s',
            'subject' => '%s',
            'from_name' => '%s',
            'from_email' => '%s',
            'reply_to' => '%s',
            'preheader' => '%s',
            'doc_json' => '%s',
            'editor_body_html' => '%s',
            'status' => '%s',
            'scheduled_at' => '%s',
            'audience_json' => '%s',
            'attachments_json' => '%s',
            'updated_at' => '%s',
            'html_body' => '%s',
            'text_body' => '%s',
        ];

        $formats = [];
        foreach ( array_keys( $payload ) as $field ) {
            $formats[] = $fieldFormats[ $field ] ?? '%s';
        }

        return $formats;
    }

    /**
     * @return array<int,int>
     */
    private function normalizedListIds( array $request ): array {
        $raw = (array) ( $request['list_ids'] ?? [] );
        return CampaignService::normalizeListIds( $raw );
    }

    /**
     * @return array<string,mixed>
     */
    private function campaignSummary( array $campaign, int $campaignId ): array {
        return [
            'id' => (int) ( $campaign['id'] ?? $campaignId ),
            'campaign_code' => (string) ( $campaign['campaign_code'] ?? '' ),
            'name' => (string) ( $campaign['name'] ?? '' ),
            'subject' => (string) ( $campaign['subject'] ?? '' ),
            'status' => (string) ( $campaign['status'] ?? '' ),
            'scheduled_at' => (string) ( $campaign['scheduled_at'] ?? '' ),
        ];
    }
}
