<?php

/**
 * Test runner: php tests/run.php [filter]
 * No dependencies. Any warning, notice or deprecation fails the test that raised it.
 * Exits non-zero on any failure.
 */

namespace AllsWeb\InRegistry\Tests;

use AllsWeb\InRegistry\ClientFactory;

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // silenced with @
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

define('WHMCS', true);
define('MODULE_DIR', dirname(__DIR__) . '/modules/registrars/inregistry');

require __DIR__ . '/stubs/whmcs.php';
require MODULE_DIR . '/inregistry.php';
require __DIR__ . '/lib/Schema.php';
require __DIR__ . '/lib/FakeRegistry.php';
require __DIR__ . '/lib/ScriptedEppClient.php';

final class AssertionFailed extends \Exception
{
}

final class T
{
    public static $tests = 0;
    public static $failed = [];
    public static $assertions = 0;
}

function test($name, callable $fn)
{
    global $argv;
    if (isset($argv[1]) && stripos($name, $argv[1]) === false) {
        return;
    }
    T::$tests++;
    Schema::$errors = [];
    $GLOBALS['__modulelog'] = [];
    $GLOBALS['__clients'] = [];
    \WHMCS\Database\Capsule::$tables = [];
    ClientFactory::$factory = null;
    \AllsWeb\InRegistry\WhmcsExpiry::$resolver = null;
    $_REQUEST = [];
    try {
        $fn();
        if (Schema::$errors) {
            throw new AssertionFailed("schema validation failed:\n" . implode("\n", Schema::$errors));
        }
    } catch (\Throwable $e) {
        $where = $e instanceof AssertionFailed ? '' : ' [' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && strpos($frame['file'], '/tests/cases/') !== false) {
                $where .= ' (' . basename($frame['file']) . ':' . $frame['line'] . ')';
                break;
            }
        }
        T::$failed[] = $name . ': ' . $e->getMessage() . $where;
    }
    ClientFactory::$factory = null;
}

function ok($condition, $message = 'expected true')
{
    T::$assertions++;
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function eq($expected, $actual, $message = '')
{
    T::$assertions++;
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function contains($needle, $haystack, $message = '')
{
    T::$assertions++;
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'expected to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

function notContains($needle, $haystack, $message = '')
{
    T::$assertions++;
    if (strpos((string) $haystack, (string) $needle) !== false) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '') . 'did not expect ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

/** Runs $fn and returns the exception it throws (fails when none). */
function throws(callable $fn, $class = \Exception::class, $messagePart = null)
{
    T::$assertions++;
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $class) {
            throw new AssertionFailed('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }
        if ($messagePart !== null && strpos($e->getMessage(), $messagePart) === false) {
            throw new AssertionFailed('exception message ' . var_export($e->getMessage(), true) . ' lacks ' . var_export($messagePart, true));
        }
        return $e;
    }
    throw new AssertionFailed('expected ' . $class . ' to be thrown');
}

/** Standard WHMCS-like params. */
function params(array $extra = [])
{
    return array_merge([
        'clid' => 'REGISTRAR1',
        'pw' => 'Pa$1\\2&<x>',
        'local_cert' => '',
        'local_pk' => '',
        'passphrase' => '',
        'identityprefix' => 'ABCD',
        'TestMode' => 'on',
        'domainid' => 123,
        'userid' => 7,
        'sld' => 'example',
        'tld' => 'in',
        'domainname' => 'example.in',
        'regperiod' => 1,
        'firstname' => 'Asha',
        'lastname' => 'Rao',
        'companyname' => 'Rao & Sons',
        'address1' => '12 MG Road',
        'address2' => '',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'postcode' => '560001',
        'country' => 'IN',
        'email' => 'asha@example.in',
        'fullphonenumber' => '+91.9876543210',
        'phonecc' => '91',
        'ns1' => 'ns1.example.net',
        'ns2' => 'ns2.example.net',
    ], $extra);
}

/** Installs a FakeRegistry behind the module's client factory. */
function fake($registry = null, $readChunk = 8192, $writeChunk = 0)
{
    $registry = $registry ?: new FakeRegistry();
    ClientFactory::$factory = function (array $p) use ($registry, $readChunk, $writeChunk) {
        $client = new ScriptedEppClient(\AllsWeb\InRegistry\ok_epp_buildConfig($p), $registry, $readChunk, $writeChunk);
        $client->login($p['clid'], $p['pw']);
        return $client;
    };
    return $registry;
}

/** A logged-in client on a FakeRegistry. */
function client($registry = null, $readChunk = 8192, $writeChunk = 0)
{
    $registry = $registry ?: new FakeRegistry();
    $client = new ScriptedEppClient(\AllsWeb\InRegistry\ok_epp_buildConfig(params()), $registry, $readChunk, $writeChunk);
    $client->login('REGISTRAR1', 'secret12');
    return $client;
}

/** XPath over a command the client sent. */
function xp($xml)
{
    $doc = new \DOMDocument();
    $doc->loadXML($xml);
    $xp = new \DOMXPath($doc);
    foreach (FakeRegistry::NS as $p => $uri) {
        $xp->registerNamespace($p, $uri);
    }
    return $xp;
}

function lastCommand(FakeRegistry $r, $key)
{
    $all = $r->commandsFor($key);
    if (!$all) {
        throw new AssertionFailed("no {$key} command was sent; sent: " . implode(', ', $r->keys()));
    }
    return end($all)['xml'];
}

$files = glob(__DIR__ . '/cases/*.php');
sort($files);
foreach ($files as $file) {
    require $file;
}

$summary = sprintf(
    '%d tests, %d assertions, %d schema validations, %d failed',
    T::$tests,
    T::$assertions,
    Schema::$validated,
    count(T::$failed)
);
foreach (T::$failed as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
echo (T::$failed ? 'FAILED: ' : 'OK: ') . $summary . ' (PHP ' . PHP_VERSION . ")\n";
exit(T::$failed || T::$tests === 0 ? 1 : 0);
