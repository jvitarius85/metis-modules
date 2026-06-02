<?php
declare(strict_types=1);

namespace Metis\Hermes\Conversation;

final class PendingQuestion {
    public function __construct(
        private readonly string $kind,
        private readonly string $prompt,
        private readonly array $payload = []
    ) {}

    public static function forWorkflow( array $workflow ): ?self {
        $step = (string) ( $workflow['step'] ?? '' );
        if ( $step === '' ) {
            return null;
        }

        $prompt = match ( $step ) {
            'display_name' => 'What is the user\'s name?',
            'email' => 'What is the user\'s email?',
            'roles' => 'What role should be assigned? You can say "no role".',
            default => 'Please provide the next workflow detail.',
        };

        return new self( 'workflow', $prompt, $workflow );
    }

    public static function forDisambiguation( array $disambiguation ): ?self {
        $candidates = array_values( (array) ( $disambiguation['candidates'] ?? [] ) );
        if ( $candidates === [] ) {
            return null;
        }

        $lines = [ 'I found multiple matches:' ];
        foreach ( $candidates as $index => $candidate ) {
            $name = trim( (string) ( $candidate['name'] ?? '' ) );
            $email = trim( (string) ( $candidate['email'] ?? '' ) );
            $suffix = $email !== '' ? ' (' . $email . ')' : '';
            $lines[] = sprintf( '%d. %s%s', $index + 1, $name !== '' ? $name : 'Unknown', $suffix );
        }
        $lines[] = 'Which person would you like?';

        return new self( 'disambiguation', implode( "\n", $lines ), $disambiguation );
    }

    public function toArray(): array {
        return [
            'kind' => $this->kind,
            'prompt' => $this->prompt,
            'payload' => $this->payload,
        ];
    }
}
