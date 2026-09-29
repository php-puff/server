<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

// Configure the shared worker count in config/config.php.
return [
    // HTTP server example.
    // [
    //     'type' => 'http',
    //     'addr' => '0.0.0.0:8620',
    //     'routes' => [\dirname(__DIR__) . '/web/routes.php'],
    //     'pipeline' => [],
    //     'trusted_proxies' => [],
    //     'resource' => \dirname(__DIR__) . '/www/assets',
    // ],

    // WebSocket server example.
    // [
    //     'type' => 'websocket',
    //     'addr' => '127.0.0.1:8791',
    //     'routes' => [\dirname(__DIR__) . '/wss/websocket.php'],
    //     'allowed_origins' => [],
    //     'protocols' => [],
    // ],

    // MCP server example. Its remaining options are provided by puff/mcp-server.
    // [
    //     'type' => 'mcp',
    //     'addr' => '0.0.0.0:8120',
    // ],
];
