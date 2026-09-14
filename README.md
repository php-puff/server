# Puff Server

Protocol-independent Fiber TCP and UDP transports for PHP Unison Fiber Framework.

`puff/server` owns sockets, normalized IPv4/IPv6 endpoints, TCP connections, UDP datagrams, buffered writes and EventLoop watchers. Higher-level packages implement the transport-specific protocol interfaces:

- `puff/http-server` implements HTTP.
- `puff/websocket-server` implements WebSocket.
- An optional MQTT package can implement a broker without moving protocol behavior into this package.

## Requirements

- PHP 8.2+
- `puff/async`

## Protocol

```php
use Puff\Server\Connection;
use Puff\Server\Protocol\TcpInterface;
use Puff\Server\TcpServer;

$protocol = new class implements TcpInterface {
    public function connected(Connection $connection, TcpServer $server): void {}

    public function receive(Connection $connection, TcpServer $server): void
    {
        $server->send($connection, $connection->readBuffer, true);
        $connection->readBuffer = '';
    }

    public function closed(Connection $connection, TcpServer $server): void {}
};

$server = new TcpServer($protocol, [
    'addr' => '0.0.0.0:8620',
    'backlog' => 4096,
    'accept_batch' => 256,
    'idle_timeout' => 60.0,
    'max_read_buffer' => 2 * 1024 * 1024,
    'max_write_buffer' => 2 * 1024 * 1024,
]);
$server->start();
Puff\Async\EventLoop::get()->run();
```

UDP uses a datagram-specific API because it has no connection lifecycle:

```php
use Puff\Server\Datagram;
use Puff\Server\Protocol\UdpInterface;
use Puff\Server\UdpServer;

$protocol = new class implements UdpInterface {
    public function receive(Datagram $datagram, UdpServer $server): void
    {
        $server->reply($datagram, 'received');
    }
};

$server = new UdpServer($protocol, ['addr' => '0.0.0.0:8620']);
$server->start();
Puff\Async\EventLoop::get()->run();
```

HTTP, WebSocket and MQTT application behavior belongs to their protocol packages. Process orchestration belongs to `puff/application`.

`TcpServer::send()` returns `false` when the connection is no longer active or when the configured write-buffer limit is exceeded. Buffer overflow closes the connection to keep long-running workers bounded. TCP protocol callback failures close only the affected connection; UDP callback failures discard only the affected datagram.

Both servers accept an optional error handler as the fourth constructor argument:

```php
$server = new TcpServer($protocol, $config, $loop, function (Throwable $error): void {
    error_log((string) $error);
});
```

## Tests

```bash
composer test
composer analyse
composer lint
```
