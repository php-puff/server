<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Server;

use Closure;
use Puff\Async\EventLoop;
use Puff\Async\EventLoopInterface;
use Puff\Server\Protocol\UdpInterface;
use Throwable;

final class UdpServer
{
    /** @var resource|null */
    private $socket = null;
    private ?string $readWatcher = null;
    private string $address;
    private string $host;
    private int $port;
    /** @var array<string, mixed> */
    private array $config;
    private EventLoopInterface $loop;
    private ?Closure $errorHandler;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly UdpInterface $protocol,
        array $config = [],
        ?EventLoopInterface $loop = null,
        ?callable $errorHandler = null,
    ) {
        $this->config = \array_replace([
            'addr' => '127.0.0.1:8620',
            'receive_batch' => 256,
            'max_datagram_size' => 65_507,
        ], $config);
        $this->loop = $loop ?? EventLoop::get();
        $this->errorHandler = $errorHandler === null ? null : Closure::fromCallable($errorHandler);
        $this->setAddress((string) $this->config['addr']);
        if ((int) $this->config['receive_batch'] < 1) {
            throw new \InvalidArgumentException('UDP receive_batch must be at least 1.');
        }
        $maxSize = (int) $this->config['max_datagram_size'];
        if ($maxSize < 1 || $maxSize > 65_507) {
            throw new \InvalidArgumentException('UDP max_datagram_size must be between 1 and 65507.');
        }
    }

    public function start(): void
    {
        if (\is_resource($this->socket)) {
            return;
        }
        $context = \stream_context_create(['socket' => [
            'so_reuseaddr' => true,
            'so_reuseport' => true,
        ]]);
        $uri = 'udp://' . $this->address;
        $socket = @\stream_socket_server($uri, $errorCode, $error, STREAM_SERVER_BIND, $context);
        if ($socket === false) {
            throw new \RuntimeException("Unable to listen on {$uri}: {$error} ({$errorCode})");
        }
        \stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->setAddress((string) \stream_socket_get_name($socket, false));
        $this->readWatcher = $this->loop->onReadable($socket, fn () => $this->read());
    }

    public function stop(): void
    {
        if ($this->readWatcher !== null) {
            $this->loop->cancel($this->readWatcher);
        }
        $this->readWatcher = null;
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
        $this->socket = null;
    }

    public function send(string $payload, string $address, int $port): void
    {
        if (!\is_resource($this->socket)) {
            throw new \LogicException('UDP server has not been started.');
        }
        if ($port < 1) {
            throw new \InvalidArgumentException('UDP destination port must be at least 1.');
        }
        $target = (string) new Endpoint($address, $port);
        $written = @\stream_socket_sendto($this->socket, $payload, 0, $target);
        if ($written === false || $written !== \strlen($payload)) {
            throw new \RuntimeException("Unable to send UDP datagram to {$target}.");
        }
    }

    public function reply(Datagram $datagram, string $payload): void
    {
        $this->send($payload, $datagram->remoteAddress, $datagram->remotePort);
    }

    public function address(): string
    {
        return $this->address;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    private function read(): void
    {
        $socket = $this->socket;
        if (!\is_resource($socket)) {
            return;
        }
        for ($received = 0; $received < (int) $this->config['receive_batch']; ++$received) {
            $peer = '';
            $payload = @\stream_socket_recvfrom(
                $socket,
                (int) $this->config['max_datagram_size'],
                0,
                $peer,
            );
            if ($payload === false) {
                return;
            }
            try {
                $endpoint = Endpoint::parse($peer);
                $this->protocol->receive(new Datagram($payload, $endpoint->host, $endpoint->port), $this);
            } catch (Throwable $exception) {
                $this->report($exception);
            }
        }
    }

    private function setAddress(string $address): void
    {
        try {
            $endpoint = Endpoint::parse($address);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException("Invalid UDP address [{$address}].", 0, $exception);
        }
        $this->host = $endpoint->host;
        $this->port = $endpoint->port;
        $this->address = (string) $endpoint;
    }

    private function report(Throwable $exception): void
    {
        if ($this->errorHandler !== null) {
            try {
                ($this->errorHandler)($exception);
                return;
            } catch (Throwable $handlerException) {
                \error_log((string) $handlerException);
            }
        }
        \error_log((string) $exception);
    }
}
