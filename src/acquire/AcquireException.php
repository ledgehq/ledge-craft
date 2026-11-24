<?php

declare(strict_types=1);

namespace ledgehq\craftledge\acquire;

use RuntimeException;

class AcquireException extends RuntimeException
{
    public function __construct(
        public string $reason,
        public int $httpStatus = 400,
        public ?string $detail = null,
        public array $context = [],
    ) {
        parent::__construct($detail !== null ? "{$reason}: {$detail}" : $reason);
    }
}
