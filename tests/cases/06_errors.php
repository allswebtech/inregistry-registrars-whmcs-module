<?php

namespace AllsWeb\InRegistry\Tests;

use AllsWeb\InRegistry\EppException;

function eppResponse($results, $prefix = '')
{
    $p = $prefix === '' ? '' : $prefix . ':';
    $root = $prefix === '' ? '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">' : "<{$p}epp xmlns:{$prefix}=\"urn:ietf:params:xml:ns:epp-1.0\">";
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . $root . "<{$p}response>" . str_replace('{P}', $p, $results)
        . "<{$p}trID><{$p}svTRID>SV-1</{$p}svTRID></{$p}trID></{$p}response></{$p}epp>";
    Schema::check($xml, 'canned error response');
    return $xml;
}

function cannedError($response)
{
    $r = new FakeRegistry();
    $c = client($r);
    $r->canned[] = $response;
    try {
        $c->domainDelete('example.in');
    } catch (EppException $e) {
        $c->logout();
        return $e;
    }
    $c->logout();
    throw new AssertionFailed('no exception');
}

test('errors: single result >= 2000 with reason', function () {
    $e = cannedError(eppResponse('<{P}result code="2306"><{P}msg>Parameter value policy error</{P}msg>'
        . '<{P}extValue><{P}value><d:name xmlns:d="urn:ietf:params:xml:ns:domain-1.0">example.in</d:name></{P}value><{P}reason>Domain is locked</{P}reason></{P}extValue></{P}result>', 'e'));
    eq(2306, $e->getCode());
    eq('Parameter value policy error: Domain is locked; example.in (EPP 2306)', $e->getMessage());
});

test('errors: 2001 with no extValue has no trailing ": "', function () {
    $e = cannedError(eppResponse('<result code="2001"><msg>Command syntax error</msg></result>'));
    eq(2001, $e->getCode());
    eq('Command syntax error (EPP 2001)', $e->getMessage());

    $e = cannedError(eppResponse('<result code="2004"><msg lang="en">Parameter value range error</msg><value><c:voice xmlns:c="urn:ietf:params:xml:ns:contact-1.0">+91.1</c:voice></value></result>'));
    eq('Parameter value range error: +91.1 (EPP 2004)', $e->getMessage());
});

test('errors: several results are all reported, the first error code wins', function () {
    $e = cannedError(eppResponse('<result code="2004"><msg>Parameter value range error</msg><value><x:a xmlns:x="urn:x">bad</x:a></value></result>'
        . '<result code="2005"><msg>Parameter value syntax error</msg><extValue><value><x:b xmlns:x="urn:x">bad2</x:b></value><reason>Invalid e-mail</reason></extValue></result>'));
    eq(2004, $e->getCode());
    contains('Parameter value range error: bad (EPP 2004)', $e->getMessage());
    contains('Parameter value syntax error: Invalid e-mail; bad2 (EPP 2005)', $e->getMessage());
});

test('errors: secret values are never echoed into messages', function () {
    $e = cannedError(eppResponse('<result code="2201"><msg>Authorization error</msg><extValue><value><d:pw xmlns:d="urn:ietf:params:xml:ns:domain-1.0">TopSecret1</d:pw></value><reason>bad auth</reason></extValue></result>'));
    notContains('TopSecret1', $e->getMessage());
    contains('bad auth', $e->getMessage());
});

test('errors: unparsable, empty, non-EPP and result-less responses throw', function () {
    foreach (['<epp><response', '', '   ', '<html><body>502</body></html>', '<?xml version="1.0"?><!DOCTYPE epp [<!ENTITY x "y">]><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"/>', '<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response/></epp>'] as $bad) {
        $e = cannedError($bad);
        eq(0, $e->getCode());
        notContains('<', $e->getMessage());
    }
});

test('errors: EppException is an \\Exception and success codes pass', function () {
    ok(is_subclass_of(EppException::class, \Exception::class));
    $r = new FakeRegistry();
    $c = client($r);
    $r->canned[] = eppResponse('<result code="1001"><msg>Command completed successfully; action pending</msg></result>');
    $res = $c->domainDelete('example.in');
    eq(1001, $res['code']);
    eq('Command completed successfully; action pending', $res['msg']);
    eq('en', $res['lang']);
    $c->logout();
});

test('errors: module log masks passwords and auth codes, keeps domain:clID', function () {
    $r = new FakeRegistry();
    $r->addDomain('example.in', ['authInfo' => 'Dom-Auth-777']);
    $c = client($r);
    $c->domainInfo('example.in', 'Dom-Auth-777');
    $c->domainUpdateAuthinfo('example.in', 'New-Auth-888');
    $c->logout();
    $log = $GLOBALS['__modulelog'];
    ok(count($log) >= 4);
    $all = json_encode(array_map(function ($entry) {
        unset($entry['replace']);
        return $entry;
    }, $log));
    notContains('secret12', $all);
    notContains('Dom-Auth-777', $all);
    notContains('New-Auth-888', $all);
    eq('inregistry', $log[0]['module']);
    contains('<clID>***</clID>', $log[0]['request']);
    contains('<pw>***</pw>', $log[0]['request']);
    contains('<domain:clID>REGISTRAR1</domain:clID>', $log[1]['response']);
    contains('<domain:pw>***</domain:pw>', $log[1]['request']);
    contains('<domain:pw>***</domain:pw>', $log[1]['response']);
    ok(in_array('secret12', $log[0]['replace'], true), 'secrets passed as replaceVars');
    $masked = \AllsWeb\InRegistry\ok_epp_maskXml('<login><clID>R</clID><pw>a</pw><newPW>b</newPW></login><x:pw a="1">c</x:pw><loginSec:pw>long-password-xx</loginSec:pw><loginSec:newPW>n</loginSec:newPW><pw>[LOGIN-SECURITY]</pw>');
    eq('<login><clID>***</clID><pw>***</pw><newPW>***</newPW></login><x:pw a="1">***</x:pw><loginSec:pw>***</loginSec:pw><loginSec:newPW>***</loginSec:newPW><pw>[LOGIN-SECURITY]</pw>', $masked[0]);
});
