<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

return [
    // HTTP server example.
    // [
    //     'type' => 'http',
    //     'addr' => '0.0.0.0:8620',
    //     'workers' => 1,
    //     'routes' => [\dirname(__DIR__) . '/app/routes.php'],
    //     'pipeline' => [],
    //     'trusted_proxies' => [],
    // ],

    // WebSocket server example.
    // [
    //     'type' => 'websocket',
    //     'addr' => '127.0.0.1:8791',
    //     'workers' => 1,
    //     'routes' => [\dirname(__DIR__) . '/app/websocket.php'],
    //     'allowed_origins' => [],
    //     'protocols' => [],
    // ],

    // MCP server example. Its remaining options are provided by puff/mcp-server.
    // [
    //     'type' => 'mcp',
    //     'addr' => '127.0.0.1:9090',
    //     'workers' => 1,
    // ],
];
