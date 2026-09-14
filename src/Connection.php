<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Server;

final class Connection
{
    public string $readBuffer = '';
    public string $writeBuffer = '';
    public bool $busy = false;
    public float $lastActivity;

    /** @param resource $stream */
    public function __construct(
        public readonly int $id,
        public $stream,
        public readonly string $remoteAddress = '',
        public readonly ?int $remotePort = null,
    ) {
        $this->lastActivity = \hrtime(true) / 1_000_000_000;
    }
}
