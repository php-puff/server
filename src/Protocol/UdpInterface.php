<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Server\Protocol;

use Puff\Server\Datagram;
use Puff\Server\UdpServer;

interface UdpInterface
{
    public function receive(Datagram $datagram, UdpServer $server): void;
}
