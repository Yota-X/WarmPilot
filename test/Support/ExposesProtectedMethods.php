<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Support;

trait ExposesProtectedMethods {
    public function call(string $method, mixed ...$arguments): mixed {
        return $this->{$method}(...$arguments);
    }
}
