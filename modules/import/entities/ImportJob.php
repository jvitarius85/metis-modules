<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Entities;

/**
 * Import Job Entity
 * 
 * Tracks WXR/Beaver Builder import operations
 */
final class ImportJob {
    public ?int $id = null;
    public ?string $job_code = null;
    public string $source_type = 'wxr_xml';
    public ?string $uploaded_file = null;
    public ?string $parse_report_json = null;
    public ?string $conversion_report_json = null;
    public ?string $preview_json = null;
    public string $status = 'pending';
    public ?int $created_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public static function fromRow( array $row ): self {
        $job = new self();
        $job->id = isset( $row['id'] ) ? (int) $row['id'] : null;
        $job->job_code = $row['job_code'] ?? null;
        $job->source_type = (string) ( $row['source_type'] ?? 'wxr_xml' );
        $job->uploaded_file = $row['uploaded_file'] ?? null;
        $job->parse_report_json = $row['parse_report_json'] ?? null;
        $job->conversion_report_json = $row['conversion_report_json'] ?? null;
        $job->preview_json = $row['preview_json'] ?? null;
        $job->status = (string) ( $row['status'] ?? 'pending' );
        $job->created_by = isset( $row['created_by'] ) ? (int) $row['created_by'] : null;
        $job->created_at = $row['created_at'] ?? null;
        $job->updated_at = $row['updated_at'] ?? null;

        return $job;
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'job_code' => $this->job_code,
            'source_type' => $this->source_type,
            'uploaded_file' => $this->uploaded_file,
            'parse_report_json' => $this->parse_report_json,
            'conversion_report_json' => $this->conversion_report_json,
            'preview_json' => $this->preview_json,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public function getParseReport(): array {
        if ( $this->parse_report_json === null ) {
            return [];
        }

        $decoded = json_decode( $this->parse_report_json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    public function getConversionReport(): array {
        if ( $this->conversion_report_json === null ) {
            return [];
        }

        $decoded = json_decode( $this->conversion_report_json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    public function getPreview(): array {
        if ( $this->preview_json === null ) {
            return [];
        }

        $decoded = json_decode( $this->preview_json, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
