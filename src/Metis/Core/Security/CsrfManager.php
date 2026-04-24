<?php
declare(strict_types=1);

namespace Metis\Core\Security;

use Metis\Core\Services\CsrfService;

final class CsrfManager {
    public function __construct(
        private readonly CsrfService $csrf = new CsrfService()
    ) {}

    public function generate(string $action): string {
        return $this->csrf->token($action);
    }

    public function validate(string $token, string $action): bool {
        return $this->csrf->isValid($token, $action);
    }

    public function hiddenFields(string $action, string $tokenField = 'csrf_token', string $actionField = 'metis_csrf_action'): string {
        return $this->csrf->hiddenFields($action, $tokenField, $actionField);
    }

    public function tokenFrom(array $input): string {
        return $this->csrf->tokenFrom($input);
    }
}
