<?php

namespace AllsWeb\InRegistry\Tests;

use function AllsWeb\InRegistry\ok_epp_xmlEscape;

test('substitution: login password with $1 \\2 & < > reaches the wire byte-exact', function () {
    $r = new FakeRegistry();
    fake($r);
    $con = \AllsWeb\InRegistry\ok_epp_startEppClient(params(['pw' => 'Pa$1\\2&<x>']));
    eq('Pa$1\\2&<x>', $r->loginPw);
    contains('<pw>Pa$1\\2&amp;&lt;x&gt;</pw>', lastCommand($r, 'login'));
    $con->logout();
});

test('substitution: auth code and contact values with back-references survive', function () {
    $r = new FakeRegistry();
    $c = client($r);
    $c->domainTransfer('example.in', 'request', '$1\\1${1}\\\\2&amp;');
    $pw = xp(lastCommand($r, 'transfer:domain'))->query('//domain:pw')->item(0)->textContent;
    // "&amp;" is treated as an already-encoded "&" (WHMCS API 1.0 compatibility).
    eq('$1\\1${1}\\\\2&', $pw);
    $c->logout();
});

test('substitution: a value that looks like a placeholder is not re-substituted', function () {
    $r = new FakeRegistry();
    $c = client($r);
    $c->contactCreate('ABCD_P1', [
        'firstname' => '{{ email }}', 'address1' => '{{ clTRID }}', 'city' => 'C', 'country' => 'IN',
        'email' => 'e@x.in', 'fullphonenumber' => '+91.9876543210',
    ]);
    eq('{{ email }}', $r->contacts['ABCD_P1']['postal'][0]['name']);
    eq(['{{ clTRID }}'], $r->contacts['ABCD_P1']['postal'][0]['street']);
    $c->logout();
});

test('escape: idempotent, attribute-safe, UTF-8, control characters dropped', function () {
    $samples = ['a & b', 'a &amp; b', '<x>', '"q" \'s\'', 'José Ñ', "tab\tok\x01bad", '$1 \\2', '&lt;already&gt;'];
    foreach ($samples as $s) {
        $once = ok_epp_xmlEscape($s);
        eq($once, ok_epp_xmlEscape($once), "idempotent for {$s}");
        $doc = new \DOMDocument();
        ok($doc->loadXML('<r a="' . $once . '">' . $once . '</r>'), "well-formed for {$s}");
    }
    eq('a &amp; b', ok_epp_xmlEscape('a & b'));
    eq('a &amp; b', ok_epp_xmlEscape('a &amp; b'), 'WHMCS API 1.0 entity-encoded input is not double-encoded');
    eq('&quot;q&quot; &apos;s&apos;', ok_epp_xmlEscape('"q" \'s\''));
    eq("tab\tokbad", ok_epp_xmlEscape("tab\tok\x01bad"));
    eq('José', ok_epp_xmlEscape('José'));
});

test('escape: only the WHMCS encoding is undone, other entities stay literal text', function () {
    eq('&amp;copy; caf&amp;eacute; &amp;nbsp; &amp;reg;', ok_epp_xmlEscape('&copy; caf&eacute; &nbsp; &reg;'));
    eq('AT&amp;T', ok_epp_xmlEscape('AT&amp;T'));
    eq('AT&amp;T', ok_epp_xmlEscape('AT&T'));
    eq('&apos;x&apos; &apos;y&apos; &apos;z&apos;', ok_epp_xmlEscape('&#039;x&#039; &#39;y&#39; &apos;z&apos;'));
    eq('&amp;amp;lt;', ok_epp_xmlEscape('&amp;amp;lt;'), 'decoded once (to &amp;lt; text), not recursively');

    $r = new FakeRegistry();
    $c = client($r);
    $c->contactCreate('ABCD_E1', [
        'firstname' => 'Café &copy; caf&eacute;', 'companyname' => 'AT&amp;T', 'address1' => 'AT&T', 'city' => 'C',
        'country' => 'IN', 'email' => 'e@x.in', 'fullphonenumber' => '+91.9876543210',
    ]);
    $xml = lastCommand($r, 'create:contact');
    contains('<contact:name>Café &amp;copy; caf&amp;eacute;</contact:name>', $xml);
    contains('<contact:org>AT&amp;T</contact:org>', $xml);
    contains('<contact:street>AT&amp;T</contact:street>', $xml);
    eq('Café &copy; caf&eacute;', $r->contacts['ABCD_E1']['postal'][0]['name']);
    $c->logout();

    // Canonical compare uses the same narrow decode.
    $a = \AllsWeb\InRegistry\ok_epp_contactCanonical(['companyname' => 'AT&amp;T', 'firstname' => '&copy;']);
    $b = \AllsWeb\InRegistry\ok_epp_contactCanonical(['companyname' => 'AT&T', 'firstname' => '©']);
    eq('AT&T', $a['companyname']);
    ok($a['firstname'] !== $b['firstname'], '&copy; is not ©');
});

test('render: empty optional elements removed, listed ones only', function () {
    $c = client(new FakeRegistry());
    $xml = $c->exposeRender('t', '<a><b:x>{{ x }}</b:x><b:y>{{ y }}</b:y><b:z>{{ z }}</b:z><b:w></b:w></a>', ['x' => '', 'y' => '  ', 'z' => 'v'], [], ['b:x', 'b:y']);
    eq('<a><b:z>v</b:z><b:w></b:w></a>', $xml);
    $c->logout();
});

test('clTRID: unique, 3-64 token characters', function () {
    $c = client(new FakeRegistry());
    $seen = [];
    for ($i = 0; $i < 2000; $i++) {
        $id = $c->exposeClTRID('domain-transferUpdate-with-a-long-action-name');
        ok(preg_match('/^[A-Za-z0-9\-]{3,64}$/', $id) === 1, "clTRID format: {$id}");
        $seen[$id] = true;
    }
    eq(2000, count($seen));
    $c->logout();
});

test('clTRID: label of domainUpdateAuthinfo is updateAuthinfo', function () {
    $r = new FakeRegistry();
    $r->addDomain('example.in');
    $c = client($r);
    $c->domainUpdateAuthinfo('example.in', 'Abc-12345');
    $id = xp(lastCommand($r, 'update:domain'))->query('//epp:clTRID')->item(0)->textContent;
    contains('domain-updateAuthinfo', $id);
    $c->logout();
});
