<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Server\Protocol;

use Puff\Server\Connection;
use Puff\Server\TcpServer;

interface TcpInterface
{
    public function connected(Connection $connection, TcpServer $server): void;

    public function receive(Connection $connection, TcpServer $server): void;

    public function closed(Connection $connection, TcpServer $server): void;
}
