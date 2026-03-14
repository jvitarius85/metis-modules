<?php
declare(strict_types=1);

namespace Metis\Http;

final class Route {
    public function __construct(
        public readonly string $name,
        public readonly array $methods,
        public $matcher,
        public $handler,
        public readonly array $middleware = []
    ) {}
}
