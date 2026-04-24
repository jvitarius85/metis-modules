<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

final class Repository {
    public static function blankForm(): array {
        return FormDefinitionRepository::blankForm();
    }

    public static function adminOptions(): array {
        return FormDefinitionRepository::adminOptions();
    }

    public static function listForms( int $limit = 100 ): array {
        return FormDefinitionRepository::listForms( $limit );
    }

    public static function getFormById( int $form_id, bool $publishedOnly = false ): ?array {
        return FormDefinitionRepository::getFormById( $form_id, $publishedOnly );
    }

    public static function getFormBySlug( string $slug, bool $publishedOnly = true ): ?array {
        return FormDefinitionRepository::getFormBySlug( $slug, $publishedOnly );
    }

    public static function saveForm( array $payload, int $user_id = 0 ): array {
        return FormDefinitionRepository::saveForm( $payload, $user_id );
    }

    public static function publishForm( int $form_id, ?array $payload = null, int $user_id = 0 ): array {
        return FormDefinitionRepository::publishForm( $form_id, $payload, $user_id );
    }

    public static function duplicateForm( int $form_id, int $user_id = 0 ): array {
        return FormDefinitionRepository::duplicateForm( $form_id, $user_id );
    }

    public static function canonicalizeFormPayload( array $payload ): array {
        return FormDefinitionRepository::canonicalizeFormPayload( $payload );
    }

    public static function deleteForm( int $form_id ): array {
        return FormDefinitionRepository::deleteForm( $form_id );
    }

    public static function listSubmissions( int $form_id, int $limit = 200 ): array {
        return FormSubmissionRepository::listSubmissions( $form_id, $limit );
    }

    public static function summarizeSubmissions( int $form_id ): array {
        return FormSubmissionRepository::summarizeSubmissions( $form_id );
    }

    public static function exportSubmissionsCsv( int $form_id ): string {
        return FormSubmissionRepository::exportSubmissionsCsv( $form_id );
    }

    public static function resolveDynamicOptions( array $source, ?string $parent_value = null ): array {
        return FormSubmissionRepository::resolveDynamicOptions( $source, $parent_value );
    }

    public static function publicAvailability( array $form, array $input = [] ): array {
        return FormSubmissionRepository::publicAvailability( $form, $input );
    }

    public static function formSupportsPayments( array $form ): bool {
        return FormSubmissionRepository::formSupportsPayments( $form );
    }

    public static function submitForm( array $form, array $payload, array $files = [], string $source_url = '' ): array {
        return FormSubmissionRepository::submitForm( $form, $payload, $files, $source_url );
    }

    public static function preparePublicPayment( array $form, array $payload, array $files = [], string $source_url = '' ): array {
        return FormSubmissionRepository::preparePublicPayment( $form, $payload, $files, $source_url );
    }

    public static function finalizePaymentSession( string $session_key, ?string $payment_intent_id = null ): array {
        return FormSubmissionRepository::finalizePaymentSession( $session_key, $payment_intent_id );
    }
}
