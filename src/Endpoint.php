<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Server;

final readonly class Endpoint
{
    public function __construct(
        public string $host,
        public int $port,
    ) {
        if (\filter_var($host, FILTER_VALIDATE_IP) === false || $port < 0 || $port > 65_535) {
            throw new \InvalidArgumentException("Invalid network endpoint [{$host}:{$port}].");
        }
    }

    public static function parse(string $endpoint): self
    {
        if (\preg_match('/^\[([^]]+)]:(\d+)$/D', $endpoint, $matches) === 1) {
            return new self($matches[1], self::port($matches[2], $endpoint));
        }

        $position = \strrpos($endpoint, ':');
        if ($position === false) {
            throw new \InvalidArgumentException("Invalid network endpoint [{$endpoint}].");
        }

        return new self(
            \substr($endpoint, 0, $position),
            self::port(\substr($endpoint, $position + 1), $endpoint),
        );
    }

    public function __toString(): string
    {
        return \str_contains($this->host, ':')
            ? "[{$this->host}]:{$this->port}"
            : "{$this->host}:{$this->port}";
    }

    private static function port(string $port, string $endpoint): int
    {
        if ($port === '' || !\ctype_digit($port)) {
            throw new \InvalidArgumentException("Invalid network endpoint [{$endpoint}].");
        }
        return (int) $port;
    }
}
