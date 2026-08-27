<?php

/*
 * PHP Fiber Framework
 * https://github.com/php-puff/server
 * https://github.com/php-puff/server/issues
 * Copyright (c) Puff
 */

declare(strict_types=1);

namespace Puff\Server\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Puff\Async\EventLoop;
use Puff\Server\Connection;
use Puff\Server\Protocol\TcpInterface;
use Puff\Server\TcpServer;

final class TcpServerTest extends TestCase
{
    protected function tearDown(): void
    {
        EventLoop::reset();
    }

    public function testOwnsCanonicalAddress(): void
    {
        $server = new TcpServer($this->protocol(), ['addr' => '127.0.0.1:8620']);

        self::assertSame('127.0.0.1:8620', $server->address());
        self::assertSame('127.0.0.1', $server->host());
        self::assertSame(8620, $server->port());
    }

    public function testRejectsIncompleteAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TcpServer($this->protocol(), ['addr' => ':8620']);
    }

    public function testAcceptsReadsWritesAndExposesPeer(): void
    {
        $protocol = new class () implements TcpInterface {
            public string $received = '';
            public ?string $peer = null;

            public function connected(Connection $connection, TcpServer $server): void
            {
                $this->peer = $connection->remoteAddress;
            }

            public function receive(Connection $connection, TcpServer $server): void
            {
                $this->received .= $connection->readBuffer;
                $connection->readBuffer = '';
                $server->send($connection, 'pong', true);
            }

            public function closed(Connection $connection, TcpServer $server): void
            {
            }
        };
        $loop = EventLoop::get();
        $server = new TcpServer($protocol, ['addr' => '127.0.0.1:0'], $loop);
        $server->start();
        $client = \stream_socket_client('tcp://' . $server->address(), $errorCode, $error, 1.0);
        self::assertIsResource($client, $error ?? 'Unable to create TCP client.');
        \stream_set_timeout($client, 1);
        \fwrite($client, 'ping');

        $loop->runUntil(static fn (): bool => $protocol->received === 'ping' && $server->connectionCount() === 0);

        self::assertSame('pong', \stream_get_contents($client));
        self::assertSame('127.0.0.1', $protocol->peer);
        \fclose($client);
        $server->stop();
    }

    public function testClosesConnectionWhenReadBufferLimitIsExceeded(): void
    {
        $loop = EventLoop::get();
        $server = new TcpServer($this->protocol(), [
            'addr' => '127.0.0.1:0',
            'max_read_buffer' => 4,
        ], $loop);
        $server->start();
        $client = \stream_socket_client('tcp://' . $server->address(), $errorCode, $error, 1.0);
        self::assertIsResource($client, $error ?? 'Unable to create TCP client.');
        \fwrite($client, '12345');

        $loop->runUntil(static fn (): bool => $server->connectionCount() === 0);

        self::assertSame(0, $server->connectionCount());
        \fclose($client);
        $server->stop();
    }

    public function testProtocolExceptionIsReportedAndConnectionIsClosed(): void
    {
        $exceptions = [];
        $protocol = new class () implements TcpInterface {
            public function connected(Connection $connection, TcpServer $server): void
            {
            }

            public function receive(Connection $connection, TcpServer $server): void
            {
                throw new \RuntimeException('broken protocol');
            }

            public function closed(Connection $connection, TcpServer $server): void
            {
            }
        };
        $loop = EventLoop::get();
        $server = new TcpServer(
            $protocol,
            ['addr' => '127.0.0.1:0'],
            $loop,
            static function (\Throwable $exception) use (&$exceptions): void {
                $exceptions[] = $exception->getMessage();
            },
        );
        $server->start();
        $client = \stream_socket_client('tcp://' . $server->address(), $errorCode, $error, 1.0);
        self::assertIsResource($client, $error ?? 'Unable to create TCP client.');
        \fwrite($client, 'data');

        $loop->runUntil(static function () use (&$exceptions, $server): bool {
            return $exceptions !== [] && $server->connectionCount() === 0;
        });

        self::assertSame(['broken protocol'], $exceptions);
        \fclose($client);
        $server->stop();
    }

    #[DataProvider('invalidConfigProvider')]
    public function testRejectsInvalidConfiguration(string $name, int|float $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TcpServer($this->protocol(), [$name => $value]);
    }

    /** @return iterable<string, array{string, int|float}> */
    public static function invalidConfigProvider(): iterable
    {
        yield 'backlog' => ['backlog', 0];
        yield 'accept batch' => ['accept_batch', 0];
        yield 'idle timeout' => ['idle_timeout', 0.0];
        yield 'read buffer' => ['max_read_buffer', 0];
        yield 'write buffer' => ['max_write_buffer', 0];
    }

    private function protocol(): TcpInterface
    {
        return new class () implements TcpInterface {
            public function connected(Connection $connection, TcpServer $server): void
            {
            }
            public function receive(Connection $connection, TcpServer $server): void
            {
            }
            public function closed(Connection $connection, TcpServer $server): void
            {
            }
        };
    }
}
