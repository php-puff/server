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
use Puff\Async\EventLoop;
use Puff\Server\Datagram;
use Puff\Server\Protocol\UdpInterface;
use Puff\Server\UdpServer;

final class UdpServerTest extends TestCase
{
    protected function tearDown(): void
    {
        EventLoop::reset();
    }

    public function testReceivesAndRepliesToDatagram(): void
    {
        $protocol = new class () implements UdpInterface {
            public ?Datagram $received = null;

            public function receive(Datagram $datagram, UdpServer $server): void
            {
                $this->received = $datagram;
                $server->reply($datagram, 'pong');
            }
        };
        $loop = EventLoop::get();
        $server = new UdpServer($protocol, ['addr' => '127.0.0.1:0'], $loop);
        $server->start();
        $client = \stream_socket_client('udp://' . $server->address(), $errorCode, $error, 1.0);
        self::assertIsResource($client, $error ?? 'Unable to create UDP client.');
        \stream_set_timeout($client, 1);
        \fwrite($client, 'ping');

        $loop->runUntil(static fn (): bool => $protocol->received instanceof Datagram);

        $datagram = $protocol->received;
        if ($datagram === null) {
            self::fail('The UDP server did not receive a datagram.');
        }
        self::assertSame('ping', $datagram->payload);
        self::assertSame('pong', \stream_socket_recvfrom($client, 4));
        self::assertSame('127.0.0.1', $server->host());
        self::assertGreaterThan(0, $server->port());
        \fclose($client);
        $server->stop();
        $server->stop();
    }

    public function testSendsToExplicitEndpoint(): void
    {
        $protocol = new class () implements UdpInterface {
            public function receive(Datagram $datagram, UdpServer $server): void
            {
            }
        };
        $receiver = \stream_socket_server('udp://127.0.0.1:0', $errorCode, $error, STREAM_SERVER_BIND);
        self::assertIsResource($receiver, $error ?? 'Unable to create UDP receiver.');
        \stream_set_timeout($receiver, 1);
        $peer = (string) \stream_socket_get_name($receiver, false);
        [, $port] = \explode(':', $peer, 2);
        $server = new UdpServer($protocol, ['addr' => '127.0.0.1:0']);
        $server->start();

        $server->send('message', '127.0.0.1', (int) $port);

        self::assertSame('message', \stream_socket_recvfrom($receiver, 1024));
        \fclose($receiver);
        $server->stop();
    }

    public function testReceivesMultipleDatagramsInOneReadableCycle(): void
    {
        $protocol = new class () implements UdpInterface {
            /** @var list<string> */
            public array $payloads = [];

            public function receive(Datagram $datagram, UdpServer $server): void
            {
                $this->payloads[] = $datagram->payload;
            }
        };
        $loop = EventLoop::get();
        $server = new UdpServer($protocol, ['addr' => '127.0.0.1:0', 'receive_batch' => 2], $loop);
        $server->start();
        $client = \stream_socket_client('udp://' . $server->address(), $errorCode, $error, 1.0);
        self::assertIsResource($client, $error ?? 'Unable to create UDP client.');
        \fwrite($client, 'first');
        \fwrite($client, 'second');

        $loop->runUntil(static fn (): bool => \count($protocol->payloads) === 2);

        self::assertSame(['first', 'second'], $protocol->payloads);
        \fclose($client);
        $server->stop();
    }

    public function testSupportsCanonicalIpv6Address(): void
    {
        $server = new UdpServer($this->protocol(), ['addr' => '[::1]:8620']);

        self::assertSame('[::1]:8620', $server->address());
        self::assertSame('::1', $server->host());
        self::assertSame(8620, $server->port());
    }

    public function testRejectsInactiveSend(): void
    {
        $server = new UdpServer($this->protocol());

        $this->expectException(\LogicException::class);
        $server->send('message', '127.0.0.1', 8620);
    }

    public function testRejectsInvalidConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new UdpServer($this->protocol(), ['receive_batch' => 0]);
    }

    public function testDatagramAllowsEmptyPayload(): void
    {
        $datagram = new Datagram('', '::1', 1883);

        self::assertSame('', $datagram->payload);
        self::assertSame('::1', $datagram->remoteAddress);
        self::assertSame(1883, $datagram->remotePort);
    }

    public function testProtocolExceptionDoesNotStopFollowingDatagrams(): void
    {
        $errors = [];
        $protocol = new class () implements UdpInterface {
            /** @var list<string> */
            public array $received = [];

            public function receive(Datagram $datagram, UdpServer $server): void
            {
                if ($datagram->payload === 'bad') {
                    throw new \RuntimeException('bad datagram');
                }
                $this->received[] = $datagram->payload;
            }
        };
        $loop = EventLoop::get();
        $server = new UdpServer(
            $protocol,
            ['addr' => '127.0.0.1:0', 'receive_batch' => 2],
            $loop,
            static function (\Throwable $exception) use (&$errors): void {
                $errors[] = $exception->getMessage();
            },
        );
        $server->start();
        $client = \stream_socket_client('udp://' . $server->address(), $errorCode, $error, 1.0);
        self::assertIsResource($client, $error ?? 'Unable to create UDP client.');
        \fwrite($client, 'bad');
        \fwrite($client, 'good');

        $loop->runUntil(static fn (): bool => $protocol->received === ['good']);

        self::assertSame(['bad datagram'], $errors);
        \fclose($client);
        $server->stop();
    }

    private function protocol(): UdpInterface
    {
        return new class () implements UdpInterface {
            public function receive(Datagram $datagram, UdpServer $server): void
            {
            }
        };
    }
}
