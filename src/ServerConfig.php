<?php

/*
 * PHP Unison Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Server;

final class ServerConfig
{
    /**
     * @param  mixed                    $config
     * @return list<array<string, mixed>>
     */
    public static function all(mixed $config, int $workers = 1): array
    {
        if (!\is_array($config) || !\array_is_list($config)) {
            throw new \InvalidArgumentException('Server configuration must be a list.');
        }
        if ($workers < 1) {
            throw new \InvalidArgumentException('Server workers must be a positive integer.');
        }

        $addresses = [];
        $servers = [];
        foreach ($config as $index => $server) {
            if (!\is_array($server)) {
                throw new \InvalidArgumentException("Server configuration at index [{$index}] must be an array.");
            }
            $type = $server['type'] ?? null;
            $address = $server['addr'] ?? null;
            if (!\is_string($type) || $type === '' || !\is_string($address) || $address === '') {
                throw new \InvalidArgumentException("Server configuration at index [{$index}] requires type and addr.");
            }
            $address = (string) Endpoint::parse($address);
            if (isset($addresses[$address])) {
                throw new \InvalidArgumentException("Server address [{$address}] is configured more than once.");
            }
            if (\array_key_exists('workers', $server)) {
                throw new \InvalidArgumentException("Server [{$address}] workers must be configured in config.php.");
            }
            $server['addr'] = $address;
            $server['workers'] = $workers;
            $addresses[$address] = true;
            $servers[] = $server;
        }

        return $servers;
    }
}
