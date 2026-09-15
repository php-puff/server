<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Server\Tests;

use PHPUnit\Framework\TestCase;
use Puff\Server\ServerConfig;

final class ServerConfigTest extends TestCase
{
    public function testNormalizesFlatServerDefinitions(): void
    {
        $servers = ServerConfig::all([[
            'type' => 'http',
            'addr' => '[::1]:8620',
        ]]);

        self::assertSame('[::1]:8620', $servers[0]['addr']);
        self::assertSame(1, $servers[0]['workers']);
    }

    public function testRejectsDuplicateAddresses(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ServerConfig::all([
            ['type' => 'http', 'addr' => '127.0.0.1:8620'],
            ['type' => 'websocket', 'addr' => '127.0.0.1:8620'],
        ]);
    }
}
