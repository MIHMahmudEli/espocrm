<?php
namespace Ratchet\Server;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\LoopInterface;
use React\Socket\Server as LegacySocketServer;
use React\Socket\SocketServer;

/**
 * @covers Ratchet\Server\IoServer
 */
class IoServerTest extends TestCase {
    protected $server;

    protected $app;

    protected $port;

    protected $reactor;

    protected function tickLoop(LoopInterface $loop) {
        $loop->futureTick(function () use ($loop) {
            $loop->stop();
        });

        $loop->run();
    }

    /**
     * @before
     */
    public function setUpServer() {
        $this->app = $this->getMockBuilder('Ratchet\\MessageComponentInterface')->getMock();

        $loop = new StreamSelectLoop;

        // prefer SocketServer (reactphp/socket v1.9+) over legacy \React\Socket\Server
        $this->reactor = class_exists('React\Socket\SocketServer') ? new SocketServer('127.0.0.1:0', [], $loop) : new LegacySocketServer('127.0.0.1:0', $loop);

        $uri = $this->reactor->getAddress();
        $this->port   = parse_url((strpos($uri, '://') === false ? 'tcp://' : '') . $uri, PHP_URL_PORT);
        $this->server = new IoServer($this->app, $this->reactor, $loop);
    }

    public function testCtorThrowsForInvalidLoop() {
        if (method_exists($this, 'expectException')) {
            $this->expectException('InvalidArgumentException');
            $this->expectExceptionMessage('Argument #3 ($loop) expected null|React\EventLoop\LoopInterface');
        } else {
            $this->setExpectedException('InvalidArgumentException', 'Argument #3 ($loop) expected null|React\EventLoop\LoopInterface');
        }
        new IoServer($this->app, $this->reactor, 'loop');
    }

    public function testOnOpen() {
        $this->app->expects($this->once())->method('onOpen')->with($this->isInstanceOf('Ratchet\\ConnectionInterface'));

        $client = stream_socket_client("tcp://localhost:{$this->port}");

        $this->tickLoop($this->server->loop);

        //$this->assertTrue(is_string($this->app->last['onOpen'][0]->remoteAddress));
        //$this->assertTrue(is_int($this->app->last['onOpen'][0]->resourceId));
    }

    public function testHandleOpenWithoutRemoteAddressAssignsEmptyRemoteAddress() {
        $this->app->expects($this->once())->method('onOpen')->with($this->isInstanceOf('Ratchet\\ConnectionInterface'));

        $conn = $this->getMockBuilder('React\\Socket\\ConnectionInterface')->getMock();
        $conn->expects($this->once())->method('getRemoteAddress')->willReturn(null);

        // assign dynamic property without raising notice on PHP 8.2+
        set_error_handler(function () { }, E_DEPRECATED);
        $conn->stream = STDOUT;
        restore_error_handler();

        $this->server->handleConnect($conn);

        $this->assertSame('', $conn->decor->remoteAddress);
        $this->assertSame((int) STDOUT, $conn->decor->resourceId);
    }

    /**
     * @requires extension sockets
     */
    public function testOnData() {
        $msg = 'Hello World!';

        $this->app->expects($this->once())->method('onMessage')->with(
            $this->isInstanceOf('Ratchet\\ConnectionInterface')
          , $msg
        );

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($client, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($client, SOL_SOCKET, SO_SNDBUF, 4096);
        socket_set_block($client);
        socket_connect($client, 'localhost', $this->port);

        $this->tickLoop($this->server->loop);

        socket_write($client, $msg);
        $this->tickLoop($this->server->loop);

        socket_shutdown($client, 1);
        socket_shutdown($client, 0);
        socket_close($client);

        $this->tickLoop($this->server->loop);
    }

    /**
     * @requires extension sockets
     */
    public function testOnClose() {
        $this->app->expects($this->once())->method('onClose')->with($this->isInstanceOf('Ratchet\\ConnectionInterface'));

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($client, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($client, SOL_SOCKET, SO_SNDBUF, 4096);
        socket_set_block($client);
        socket_connect($client, 'localhost', $this->port);

        $this->tickLoop($this->server->loop);

        socket_shutdown($client, 1);
        socket_shutdown($client, 0);
        socket_close($client);

        $this->tickLoop($this->server->loop);
    }

    public function testFactory() {
        $server = IoServer::factory($this->app, 0);
        $server->socket->close();

        $this->assertInstanceOf('Ratchet\\Server\\IoServer', $server);
    }

    public function testNoLoopProvidedError() {
        if (method_exists($this, 'expectException')) {
            $this->expectException('RuntimeException');
        } else {
            $this->setExpectedException('RuntimeException');
        }

        $io   = new IoServer($this->app, $this->reactor);
        $io->run();
    }

    public function testOnErrorPassesException() {
        $conn = $this->getMockBuilder('React\\Socket\\ConnectionInterface')->getMock();

        // assign dynamic property without raising notice on PHP 8.2+
        set_error_handler(function () { }, E_DEPRECATED);
        $conn->decor = $this->getMockBuilder('Ratchet\\ConnectionInterface')->getMock();
        restore_error_handler();

        $err  = new \Exception("Nope");

        $this->app->expects($this->once())->method('onError')->with($conn->decor, $err);

        $this->server->handleError($err, $conn);
    }

    public function onErrorCalledWhenExceptionThrown() {
        $this->markTestIncomplete("Need to learn how to throw an exception from a mock");

        $conn = $this->getMockBuilder('React\\Socket\\ConnectionInterface')->getMock();
        $this->server->handleConnect($conn);

        $e = new \Exception;
        $this->app->expects($this->once())->method('onMessage')->with($this->isInstanceOf('Ratchet\\ConnectionInterface'), 'f')->will($e);
        $this->app->expects($this->once())->method('onError')->with($this->instanceOf('Ratchet\\ConnectionInterface', $e));

        $this->server->handleData('f', $conn);
    }
}
