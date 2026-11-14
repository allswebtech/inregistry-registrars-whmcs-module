<?php

namespace AllsWeb\InRegistry\Tests;

use AllsWeb\InRegistry\EppClient;
use AllsWeb\InRegistry\EppException;

function frame($xml)
{
    return pack('N', strlen($xml) + 4) . $xml;
}

/**
 * EppClient whose socket is replaced by an in-memory transport wired to a
 * FakeRegistry. Reads come back in chunks of at most $readChunk bytes and
 * writes are accepted at most $writeChunk bytes at a time, so the framing
 * loops are exercised.
 */
class ScriptedEppClient extends EppClient
{
    public $registry;
    public $readChunk;
    public $writeChunk;
    public $sent = [];
    public $closed = false;
    public $closeCount = 0;
    private $in = '';
    private $out = '';

    public function __construct(array $config, FakeRegistry $registry, $readChunk = 8192, $writeChunk = 0)
    {
        $this->registry = $registry;
        $this->readChunk = $readChunk;
        $this->writeChunk = $writeChunk;
        parent::__construct($config);
    }

    protected function openSocket($timeout)
    {
        $this->closed = false;
        $this->in = frame($this->registry->greeting());
        return 'scripted';
    }

    protected function readChunk($length)
    {
        if ($this->in === '') {
            throw new EppException('Connection closed by the registry');
        }
        $n = min($length, $this->readChunk);
        $data = substr($this->in, 0, $n);
        $this->in = (string) substr($this->in, $n);
        return $data;
    }

    protected function writeChunk($data)
    {
        if ($this->closed) {
            throw new EppException('Error writing to the registry connection');
        }
        $n = $this->writeChunk > 0 ? min(strlen($data), $this->writeChunk) : strlen($data);
        $this->out .= substr($data, 0, $n);
        while (strlen($this->out) >= 4) {
            $len = unpack('N', substr($this->out, 0, 4))[1];
            if (strlen($this->out) < $len) {
                break;
            }
            $xml = substr($this->out, 4, $len - 4);
            $this->out = (string) substr($this->out, $len);
            $this->sent[] = $xml;
            $response = $this->registry->handle($xml);
            if ($response === null) {
                $this->closed = true; // registry dropped the connection
                $this->in = '';
            } else {
                $this->in .= frame($response);
            }
        }
        return $n;
    }

    public function disconnect()
    {
        $this->closeCount++;
        $this->closed = true;
        return parent::disconnect();
    }

    public function exposeRender($action, $template, array $values = [], array $raw = [], array $optional = [])
    {
        return $this->render($action, $template, $values, $raw, $optional);
    }

    public function exposeClTRID($action)
    {
        return $this->newClTRID($action);
    }
}

/** Client fed a fixed byte string ("the server side"); writes are discarded. */
class RawTransportClient extends EppClient
{
    private $bytes;
    private $chunk;

    public function __construct($bytes, $chunk = 8192)
    {
        $this->bytes = $bytes;
        $this->chunk = $chunk;
        parent::__construct(['host' => 'test.invalid', 'port' => 700, 'identityprefix' => 'T']);
    }

    protected function openSocket($timeout)
    {
        return 'raw';
    }

    protected function readChunk($length)
    {
        if ($this->bytes === '') {
            throw new EppException('Connection closed by the registry');
        }
        $n = min($length, $this->chunk);
        $data = substr($this->bytes, 0, $n);
        $this->bytes = (string) substr($this->bytes, $n);
        return $data;
    }

    protected function writeChunk($data)
    {
        return strlen($data);
    }

    public function exposeReadFrame()
    {
        return $this->readFrame();
    }
}

/** Client on a real local TCP socket (accepted from a test server). */
class SocketClient extends EppClient
{
    private $preset;

    public function __construct($socket, array $config)
    {
        $this->preset = $socket;
        parent::__construct($config);
    }

    protected function openSocket($timeout)
    {
        stream_set_timeout($this->preset, (int) $this->getConfig('timeout', 60));
        return $this->preset;
    }

    public function exposeReadFrame()
    {
        return $this->readFrame();
    }

    public function exposeWriteFrame($xml)
    {
        $this->writeFrame($xml);
    }
}
