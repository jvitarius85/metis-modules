<?php
declare(strict_types=1);

namespace Metis\Core\Security;

final class NonceManager {
    public function __construct(
        private readonly CsrfManager $csrf = new CsrfManager()
    ) {}

    public function generate(string $action): string {
        return $this->csrf->generate($action);
    }

    public function verify(string $nonce, string $action): bool {
        return $nonce !== '' && $action !== '' && $this->csrf->validate($nonce, $action);
    }

    public function extract(array $input): string {
        return $this->csrf->tokenFrom($input);
    }
}
