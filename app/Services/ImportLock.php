<?php

namespace App\Services;

final class ImportLock
{
    public function __construct(
        private readonly ImportLockService $service,
        public readonly string $type,
        private readonly string $token
    ) {
    }

    public function release(): void
    {
        $this->service->release($this->type, $this->token);
    }
}
