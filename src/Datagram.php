<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Server;

final readonly class Datagram
{
    public function __construct(
        public string $payload,
        public string $remoteAddress,
        public int $remotePort,
    ) {
    }
}
