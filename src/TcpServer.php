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
use Puff\Server\Protocol\TcpInterface;
use Throwable;

final class TcpServer
{
    /** @var array<string, mixed> */
    private array $config;
    /** @var resource|null */
    private $socket = null;
    private ?string $acceptWatcher = null;
    private EventLoopInterface $loop;
    private string $address;
    private string $host;
    private int $port;
    /** @var array<int, Connection> */
    private array $connections = [];
    /** @var array<int, string> */
    private array $readWatchers = [];
    /** @var array<int, string> */
    private array $writeWatchers = [];
    /** @var array<int, string> */
    private array $idleTimers = [];
    /** @var array<int, true> */
    private array $closeAfterWrite = [];
    private ?Closure $errorHandler;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly TcpInterface $protocol,
        array $config = [],
        ?EventLoopInterface $loop = null,
        ?callable $errorHandler = null,
    ) {
        $this->loop = $loop ?? EventLoop::get();
        $this->errorHandler = $errorHandler === null ? null : Closure::fromCallable($errorHandler);
        $this->config = \array_replace([
            'addr' => '127.0.0.1:8620',
            'backlog' => 4096,
            'accept_batch' => 256,
            'idle_timeout' => 60.0,
            'max_read_buffer' => 2 * 1024 * 1024,
            'max_write_buffer' => 2 * 1024 * 1024,
        ], $config);
        $this->setAddress((string) $this->config['addr']);
        $this->validateConfig();
    }

    public function start(): void
    {
        if (\is_resource($this->socket)) {
            return;
        }
        $context = \stream_context_create(['socket' => [
            'backlog' => (int) $this->config['backlog'],
            'so_reuseaddr' => true,
            'so_reuseport' => true,
        ]]);
        $uri = 'tcp://' . $this->address;
        $socket = @\stream_socket_server($uri, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($socket === false) {
            throw new \RuntimeException("Unable to listen on {$uri}: {$error} ({$errno})");
        }
        \stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->setAddress((string) \stream_socket_get_name($socket, false));
        $this->acceptWatcher = $this->loop->onReadable($socket, fn () => $this->accept());
    }

    public function stop(): void
    {
        if ($this->acceptWatcher !== null) {
            $this->loop->cancel($this->acceptWatcher);
        }
        $this->acceptWatcher = null;
        foreach ($this->connections as $connection) {
            $this->close($connection);
        }
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }
        $this->socket = null;
    }

    public function send(Connection $connection, string $data, bool $close = false): bool
    {
        if (!$this->isActive($connection)) {
            return false;
        }
        if (\strlen($connection->writeBuffer) + \strlen($data) > (int) $this->config['max_write_buffer']) {
            $this->close($connection);
            return false;
        }
        if ($data === '') {
            if ($close) {
                $this->close($connection);
            }
            return true;
        }
        $connection->writeBuffer .= $data;
        if ($close) {
            $this->closeAfterWrite[$connection->id] = true;
        }
        $this->pauseReading($connection);
        if (!isset($this->writeWatchers[$connection->id])) {
            $this->writeWatchers[$connection->id] = $this->loop->onWritable(
                $connection->stream,
                fn () => $this->write($connection),
            );
        }
        return true;
    }

    public function close(Connection $connection): void
    {
        if (($this->connections[$connection->id] ?? null) !== $connection) {
            return;
        }
        foreach ([
            $this->readWatchers[$connection->id] ?? null,
            $this->writeWatchers[$connection->id] ?? null,
            $this->idleTimers[$connection->id] ?? null,
        ] as $watcher) {
            if ($watcher !== null) {
                $this->loop->cancel($watcher);
            }
        }
        if (\is_resource($connection->stream)) {
            @\fclose($connection->stream);
        }
        unset(
            $this->connections[$connection->id],
            $this->readWatchers[$connection->id],
            $this->writeWatchers[$connection->id],
            $this->idleTimers[$connection->id],
            $this->closeAfterWrite[$connection->id],
        );
        try {
            $this->protocol->closed($connection, $this);
        } catch (Throwable $exception) {
            $this->report($exception);
        }
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

    public function connectionCount(): int
    {
        return \count($this->connections);
    }

    private function setAddress(string $address): void
    {
        try {
            $endpoint = Endpoint::parse($address);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException("Invalid TCP address [{$address}].", 0, $exception);
        }
        $this->host = $endpoint->host;
        $this->port = $endpoint->port;
        $this->address = (string) $endpoint;
    }

    private function accept(): void
    {
        $socket = $this->socket;
        if (!\is_resource($socket)) {
            return;
        }
        for ($accepted = 0; $accepted < (int) $this->config['accept_batch']; ++$accepted) {
            \set_error_handler(static fn (): bool => true);
            try {
                $stream = \stream_socket_accept($socket, 0);
            } finally {
                \restore_error_handler();
            }
            if ($stream === false) {
                return;
            }
            \stream_set_blocking($stream, false);
            \stream_set_read_buffer($stream, 0);
            \stream_set_write_buffer($stream, 0);
            [$remoteAddress, $remotePort] = $this->peer((string) \stream_socket_get_name($stream, true));
            $connection = new Connection((int) $stream, $stream, $remoteAddress, $remotePort);
            $this->connections[$connection->id] = $connection;
            $this->resumeReading($connection);
            $this->touch($connection);
            try {
                $this->protocol->connected($connection, $this);
            } catch (Throwable $exception) {
                $this->report($exception);
                $this->close($connection);
            }
        }
    }

    private function read(Connection $connection): void
    {
        if (!$this->isActive($connection)) {
            return;
        }
        $data = @\fread($connection->stream, 65536);
        if ($data === false || ($data === '' && \feof($connection->stream))) {
            $this->close($connection);
            return;
        }
        if ($data === '') {
            return;
        }
        $connection->readBuffer .= $data;
        if (\strlen($connection->readBuffer) > (int) $this->config['max_read_buffer']) {
            $this->close($connection);
            return;
        }
        $this->touch($connection);
        try {
            $this->protocol->receive($connection, $this);
        } catch (Throwable $exception) {
            $this->report($exception);
            $this->close($connection);
            return;
        }
        if ($connection->busy || $connection->writeBuffer !== '') {
            $this->pauseReading($connection);
        }
    }

    private function write(Connection $connection): void
    {
        if (!$this->isActive($connection)) {
            return;
        }
        $written = @\fwrite($connection->stream, $connection->writeBuffer);
        if ($written === false) {
            $this->close($connection);
            return;
        }
        if ($written > 0) {
            $connection->writeBuffer = (string) \substr($connection->writeBuffer, $written);
        }
        if ($connection->writeBuffer !== '') {
            return;
        }
        if (isset($this->writeWatchers[$connection->id])) {
            $this->loop->cancel($this->writeWatchers[$connection->id]);
        }
        unset($this->writeWatchers[$connection->id]);
        $connection->busy = false;
        if (isset($this->closeAfterWrite[$connection->id])) {
            $this->close($connection);
            return;
        }
        $this->touch($connection);
        $this->resumeReading($connection);
        if ($connection->readBuffer !== '') {
            try {
                $this->protocol->receive($connection, $this);
            } catch (Throwable $exception) {
                $this->report($exception);
                $this->close($connection);
            }
        }
    }

    private function touch(Connection $connection): void
    {
        $connection->lastActivity = \hrtime(true) / 1_000_000_000;
        if (!isset($this->idleTimers[$connection->id])) {
            $this->scheduleIdleCheck($connection, (float) $this->config['idle_timeout']);
        }
    }

    private function scheduleIdleCheck(Connection $connection, float $delay): void
    {
        $this->idleTimers[$connection->id] = $this->loop->delay($delay, function () use ($connection): void {
            unset($this->idleTimers[$connection->id]);
            if (!isset($this->connections[$connection->id])) {
                return;
            }
            $timeout = (float) $this->config['idle_timeout'];
            $idle = \hrtime(true) / 1_000_000_000 - $connection->lastActivity;
            if ($idle >= $timeout) {
                $this->close($connection);
                return;
            }
            $this->scheduleIdleCheck($connection, $timeout - $idle);
        });
    }

    private function isActive(Connection $connection): bool
    {
        return ($this->connections[$connection->id] ?? null) === $connection
            && \is_resource($connection->stream);
    }

    /** @return array{string, int|null} */
    private function peer(string $peer): array
    {
        try {
            $endpoint = Endpoint::parse($peer);
            return [$endpoint->host, $endpoint->port];
        } catch (\InvalidArgumentException) {
            return [$peer, null];
        }
    }

    private function pauseReading(Connection $connection): void
    {
        if (!isset($this->readWatchers[$connection->id])) {
            return;
        }
        $this->loop->cancel($this->readWatchers[$connection->id]);
        unset($this->readWatchers[$connection->id]);
    }

    private function resumeReading(Connection $connection): void
    {
        if (!$this->isActive($connection) || isset($this->readWatchers[$connection->id])) {
            return;
        }
        $this->readWatchers[$connection->id] = $this->loop->onReadable(
            $connection->stream,
            fn () => $this->read($connection),
        );
    }

    private function validateConfig(): void
    {
        foreach (['backlog', 'accept_batch', 'max_read_buffer', 'max_write_buffer'] as $name) {
            if ((int) $this->config[$name] < 1) {
                throw new \InvalidArgumentException("TCP {$name} must be at least 1.");
            }
        }
        if ((float) $this->config['idle_timeout'] <= 0.0) {
            throw new \InvalidArgumentException('TCP idle_timeout must be greater than zero.');
        }
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
