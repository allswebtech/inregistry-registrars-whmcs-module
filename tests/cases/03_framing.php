<?php

namespace AllsWeb\InRegistry\Tests;

use AllsWeb\InRegistry\EppException;

function greetingXml()
{
    return (new FakeRegistry())->greeting();
}

function bigResponse($bytes)
{
    $pad = str_repeat('x', $bytes);
    return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
        . '<result code="1000"><msg>' . $pad . '</msg></result><trID><svTRID>SV-1</svTRID></trID></response></epp>';
}

test('framing: commands and responses delivered 1-7 bytes at a time', function () {
    foreach ([1, 3, 7] as $chunk) {
        $r = new FakeRegistry();
        $r->addDomain('example.in', ['ns' => ['ns1.example.net', 'ns2.example.net']]);
        $c = client($r, $chunk, 5);
        $info = $c->domainInfo('example.in');
        eq(['ns1.example.net', 'ns2.example.net'], $info['domain']['ns']);
        $c->logout();
        eq(['login', 'info:domain', 'logout'], $r->keys());
    }
});

test('framing: 100 KB response read completely (chunked)', function () {
    $xml = bigResponse(100 * 1024);
    $c = new RawTransportClient(frame(greetingXml()) . frame($xml), 8192);
    eq($xml, $c->exposeReadFrame());
});

test('framing: short read / EOF throws', function () {
    $xml = bigResponse(20000);
    $truncated = substr(frame($xml), 0, 12000);
    $c = new RawTransportClient(frame(greetingXml()) . $truncated, 4096);
    throws(function () use ($c) {
        $c->exposeReadFrame();
    }, EppException::class, 'Connection closed');
    $c2 = new RawTransportClient(frame(greetingXml()) . "\x00\x00", 4096);
    throws(function () use ($c2) {
        $c2->exposeReadFrame();
    }, EppException::class, 'Connection closed');
});

test('framing: bogus length headers throw', function () {
    foreach ([0, 3, 0x7fffffff] as $len) {
        $c = new RawTransportClient(frame(greetingXml()) . pack('N', $len) . 'abc', 4096);
        throws(function () use ($c) {
            $c->exposeReadFrame();
        }, EppException::class, 'Invalid EPP frame length');
    }
    $c = new RawTransportClient(frame(greetingXml()) . pack('N', 4), 4096);
    eq('', $c->exposeReadFrame(), 'a 4-byte frame is an empty document');
});

/**
 * A real local TCP socket: a child PHP process writes $payload (in pieces)
 * then sleeps $sleep seconds; returns [client socket, process].
 */
function localSocketPair($payload, $sleep = 0)
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$server) {
        return null;
    }
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $file = tempnam(sys_get_temp_dir(), 'epp');
    file_put_contents($file, $payload);
    $code = '$s = stream_socket_client("tcp://127.0.0.1:" . $argv[1]); $d = file_get_contents($argv[2]);'
        . 'foreach (str_split($d, 3000) as $p) { fwrite($s, $p); usleep(1000); } sleep((int) $argv[3]); fclose($s);';
    $proc = proc_open([PHP_BINARY, '-r', $code, (string) $port, $file, (string) $sleep], [], $pipes);
    $sock = @stream_socket_accept($server, 10);
    fclose($server);
    return [$sock, $proc, $file];
}

test('framing: real TCP socket, partial reads, EOF and timeout', function () {
    $big = bigResponse(150 * 1024);
    $pair = localSocketPair(frame(greetingXml()) . frame($big), 0);
    if ($pair === null || !$pair[0]) {
        ok(true); // no local sockets in this environment; covered by the scripted tests
        return;
    }
    list($sock, $proc, $file) = $pair;
    $c = new SocketClient($sock, ['timeout' => 5]);
    eq(true, $c->getGreeting()['parsed']);
    eq($big, $c->exposeReadFrame());
    throws(function () use ($c) {
        $c->exposeReadFrame();
    }, EppException::class, 'Connection closed');
    $c->disconnect();
    proc_close($proc);
    unlink($file);

    list($sock, $proc, $file) = localSocketPair(frame(greetingXml()) . substr(frame($big), 0, 5000), 4);
    $c = new SocketClient($sock, ['timeout' => 1]);
    $start = microtime(true);
    throws(function () use ($c) {
        $c->exposeReadFrame();
    }, EppException::class, 'Timed out');
    ok(microtime(true) - $start < 3.5, 'timeout honoured');
    $c->disconnect();
    proc_terminate($proc);
    proc_close($proc);
    unlink($file);
});

test('framing: write to a closed connection throws, logout still closes quietly', function () {
    $r = new FakeRegistry();
    $r->failures['info:domain'] = ['drop' => true, 'code' => 0, 'msg' => ''];
    $c = client($r);
    throws(function () use ($c) {
        $c->domainInfo('example.in');
    }, EppException::class, 'Connection closed');
    eq(true, $c->logout());
    ok($c->closeCount >= 1);
    eq(false, $c->isLoggedIn());
});
