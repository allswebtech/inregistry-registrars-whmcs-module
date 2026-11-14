<?php

namespace AllsWeb\InRegistry\Tests;

// Every command the client can build is sent to the FakeRegistry, which
// validates it against the official XSDs (extension content included).
// A schema error fails the test.

const NASTY = [
    'firstname' => "José \\1 Ñúñez \$1",
    'lastname' => '"Q" O\'Brien',
    'companyname' => 'Rao & Sons <Pvt> $1 \\2',
    'address1' => 'Flat #3, "Shanti" <Kunj> & Co',
    'address2' => '',
    'address3' => "Near \$0 temple",
    'city' => 'Pune $1',
    'state' => '',
    'postcode' => '',
    'country' => 'in',
    'email' => 'a+b@example.in',
    'fullphonenumber' => '+91 98765-43210',
    'phonecc' => '91',
    'fax' => 'not a number',
];

test('schema: login, logout, hello-less session', function () {
    $r = new FakeRegistry();
    $c = client($r);
    $c->logout();
    eq(['login', 'logout'], $r->keys());
});

test('schema: domain check/info/create/renew/delete', function () {
    $r = new FakeRegistry();
    $r->addContact('CON1');
    $r->addHost('ns1.example.net');
    $r->addHost('ns2.example.net');
    $c = client($r);
    $c->domainCheck(['a.in', 'b.co.in', 'x&y.in']);
    $c->domainCheck('single.in');
    $c->domainCreate('new.in', 2, ['registrant' => 'CON1', 'admin' => 'CON1', 'tech' => 'CON1', 'billing' => 'CON1'], ['ns1.example.net', 'ns2.example.net'], 'Au$1\\2&<x>"\'');
    $c->domainCreate('nons.in', '', ['registrant' => 'CON1'], [], null);
    $c->domainInfo('new.in');
    $c->domainInfo('new.in', 'Au$1\\2&<x>"\'');
    $c->domainRenew('new.in', 1);
    $c->domainRenew('new.in', 3, $r->domains['new.in']['exDate'] ? gmdate('Y-m-d\TH:i:s.0\Z', $r->domains['new.in']['exDate']) : null);
    $c->domainDelete('nons.in');
    ok(!isset($r->domains['nons.in']));
    $c->logout();
});

test('schema: domain updates (ns, status, contact, authinfo, transfer-update)', function () {
    $r = new FakeRegistry();
    $r->addContact('CON1');
    $r->addContact('CON2');
    $r->addHost('ns1.example.net');
    $r->addHost('ns2.example.net');
    $r->addHost('ns3.example.net');
    $r->addDomain('example.in', ['ns' => ['ns1.example.net', 'ns2.example.net']]);
    $c = client($r);
    $c->domainUpdateNS('example.in', ['ns3.example.net'], []);
    $c->domainUpdateNS('example.in', [], ['ns1.example.net']);
    $c->domainUpdateNS('example.in', ['ns1.example.net'], ['ns3.example.net']);
    $c->domainUpdateStatus('example.in', ['clientTransferProhibited', 'clientUpdateProhibited'], 'add');
    $c->domainUpdateStatus('example.in', 'clientUpdateProhibited', 'rem');
    $c->domainUpdateContact('example.in', 'registrant', 'CON2');
    $c->domainUpdateContact('example.in', 'admin', 'CON2', 'CON1');
    $c->domainUpdateAuthinfo('example.in', 'New$1\\2&<x>');
    eq('New$1\\2&<x>', $r->domains['example.in']['authInfo']);
    $c->domainTransferUpdate('example.in', 'CON2');
    $c->logout();
});

test('schema: domain transfer, all operations', function () {
    $r = new FakeRegistry();
    $c = client($r);
    $req = $c->domainTransfer('example.in', 'request', 'Tr$1\\2&<x>');
    eq('pending', $req['domain']['trStatus']);
    foreach (['query', 'approve', 'reject', 'cancel'] as $op) {
        $c->domainTransfer('example.in', $op);
    }
    $req = xp(lastCommand($r, 'transfer:domain'));
    eq('cancel', $req->query('//epp:transfer')->item(0)->getAttribute('op'));
    throws(function () use ($c) {
        $c->domainTransfer('example.in', 'steal');
    }, \Exception::class, 'transfer:op');
    $c->logout();
});

test('schema: restore request and report, DNSSEC create and update', function () {
    $r = new FakeRegistry();
    $r->addContact('CON1');
    $r->addDomain('example.in', ['rgp' => ['redemptionPeriod']]);
    $c = client($r);
    $restore = $c->domainRestore('example.in');
    eq(true, $restore['domain']['success']);
    $c->domainRestoreReport('example.in', [
        'preData' => 'Pre <data> & $1',
        'postData' => 'Post data',
        'delTime' => '2026-10-01T22:00:00.0Z',
        'resTime' => '2026-10-20T22:00:00Z',
        'resReason' => 'Registrant error.',
        'statements' => ['Statement one.', 'Statement two.'],
    ]);
    throws(function () use ($c) {
        $c->domainRestoreReport('example.in', ['preData' => 'x']);
    }, \Exception::class, 'postData');
    throws(function () use ($c) {
        $c->domainRestoreReport('example.in', ['preData' => 'a', 'postData' => 'b', 'delTime' => 'yesterday', 'resTime' => '2026-10-20T22:00:00Z', 'resReason' => 'r', 'statements' => ['s']]);
    }, \Exception::class, 'delTime');

    $ds1 = ['keyTag' => '12345', 'alg' => '13', 'digestType' => '2', 'digest' => '49FD46E6C4B45C55D4AC49FD46E6C4B45C55D4AC49FD46E6C4B45C55D4AC1234'];
    $ds2 = ['keyTag' => 1, 'alg' => 8, 'digestType' => 1, 'digest' => 'aabbccddeeff00112233445566778899aabbccdd'];
    $c->domainCreateDNSSEC('signed.in', 1, ['registrant' => 'CON1', 'tech' => 'CON1'], [], [$ds1, $ds2]);
    $c->domainUpdateDNSSEC('example.in', [$ds1]);
    $c->domainUpdateDNSSEC('example.in', [], [$ds1]);
    $c->domainUpdateDNSSEC('example.in', [$ds2], [$ds1]);
    $c->domainUpdateDNSSEC('example.in', [$ds2], [], true);
    $before = count($r->commands);
    $c->domainUpdateDNSSEC('example.in');
    eq($before, count($r->commands), 'empty DNSSEC update sends nothing');
    throws(function () use ($c) {
        $c->domainUpdateDNSSEC('example.in', [['keyTag' => 'x', 'alg' => 1, 'digestType' => 1, 'digest' => 'zz']]);
    }, \Exception::class, 'Invalid DS record');
    $ext = xp(lastCommand($r, 'create:domain'));
    eq(1, $ext->query('/epp:epp/epp:command/epp:extension/secDNS:create')->length, 'secDNS:create is a sibling of <create>');
    eq(2, $ext->query('//secDNS:dsData')->length);
    $c->logout();
});

test('schema: DNSSEC and restore refused when the registry does not offer the extension', function () {
    $r = new FakeRegistry();
    $r->extURIs = [];
    $c = client($r);
    throws(function () use ($c) {
        $c->domainUpdateDNSSEC('example.in', [['keyTag' => 1, 'alg' => 8, 'digestType' => 1, 'digest' => 'AA']]);
    }, \AllsWeb\InRegistry\EppException::class, 'secDNS-1.1');
    throws(function () use ($c) {
        $c->domainRestore('example.in');
    }, \AllsWeb\InRegistry\EppException::class, 'rgp-1.0');
    eq(['login'], $r->keys());
});

test('schema: contact check/info/create/update/delete with nasty values', function () {
    $r = new FakeRegistry();
    $c = client($r);
    $c->contactCheck(['abcd_1', 'ABCD_2']);
    $created = $c->contactCreate('abcd_x1', NASTY);
    eq('ABCD_X1', $created['contact']['id']);
    $stored = $r->contacts['ABCD_X1'];
    eq('José \\1 Ñúñez $1 "Q" O\'Brien', $stored['postal'][0]['name']);
    eq('Rao & Sons <Pvt> $1 \\2', $stored['postal'][0]['org']);
    eq(['Flat #3, "Shanti" <Kunj> & Co', 'Near $0 temple'], $stored['postal'][0]['street']);
    eq('Pune $1', $stored['postal'][0]['city']);
    eq('IN', $stored['postal'][0]['cc']);
    eq('+91.9876543210', $stored['voice']);
    eq('', $stored['fax'], 'invalid fax dropped');
    $minimal = ['firstname' => ' Solo ', 'address1' => 'Street', 'city' => 'City', 'country' => 'US', 'email' => 'x@y.us', 'phonenumber' => '4155550100'];
    $c->contactCreate('ABCD_X2', $minimal);
    eq('Solo', $r->contacts['ABCD_X2']['postal'][0]['name']);
    eq('+1.4155550100', $r->contacts['ABCD_X2']['voice']);
    $c->contactUpdate('ABCD_X2', NASTY);
    eq('Pune $1', $r->contacts['ABCD_X2']['postal'][0]['city']);
    $info = $c->contactInfo('ABCD_X2');
    eq('Rao & Sons <Pvt> $1 \\2', $info['contact']['postalInfo']['org']);
    $c->contactDelete('ABCD_X2');
    ok(!isset($r->contacts['ABCD_X2']));
    $c->logout();
});

test('contact: required fields are named, optional fields may be missing', function () {
    $c = client(new FakeRegistry());
    $base = ['firstname' => 'A', 'address1' => 'S', 'city' => 'C', 'country' => 'IN', 'email' => 'e@x.in', 'fullphonenumber' => '+91.9876543210'];
    foreach (['firstname' => 'name', 'address1' => 'address 1', 'city' => 'city', 'country' => 'country', 'email' => 'email'] as $key => $label) {
        $p = $base;
        unset($p[$key]);
        throws(function () use ($c, $p) {
            $c->contactCreate('ABCD_R1', $p);
        }, \AllsWeb\InRegistry\EppException::class, $label);
    }
    throws(function () use ($c, $base) {
        $c->contactCreate('ABCD_R1', array_merge($base, ['fullphonenumber' => '123']));
    }, \AllsWeb\InRegistry\EppException::class, 'Invalid phone number');
    throws(function () use ($c, $base) {
        $c->contactCreate('ABCD_R1', array_merge($base, ['country' => 'India']));
    }, \AllsWeb\InRegistry\EppException::class, 'country');
    throws(function () use ($c, $base) {
        $c->contactCreate('THIS_ID_IS_TOO_LONG_X', $base);
    }, \AllsWeb\InRegistry\EppException::class, 'contact ID');
    $c->contactCreate('ABCD_R1', $base);
    $c->logout();
});

test('contact: empty phone omits <contact:voice>; on update the number stays', function () {
    $r = new FakeRegistry();
    $c = client($r);
    $base = ['firstname' => 'A', 'address1' => 'S', 'city' => 'C', 'country' => 'IN', 'email' => 'e@x.in'];
    $c->contactCreate('ABCD_V1', $base);
    eq(0, xp(lastCommand($r, 'create:contact'))->query('//contact:voice')->length);
    eq('', $r->contacts['ABCD_V1']['voice']);
    $c->contactCreate('ABCD_V2', array_merge($base, ['fullphonenumber' => '+91.9876543210']));
    $c->contactUpdate('ABCD_V2', array_merge($base, ['city' => 'Pune', 'fullphonenumber' => '', 'phonenumber' => '']));
    eq(0, xp(lastCommand($r, 'update:contact'))->query('//contact:voice')->length);
    eq('+91.9876543210', $r->contacts['ABCD_V2']['voice'], 'existing number not blanked');
    eq('Pune', $r->contacts['ABCD_V2']['postal'][0]['city']);
    throws(function () use ($c, $base) {
        $c->contactUpdate('ABCD_V2', array_merge($base, ['phonenumber' => 'call me']));
    }, \AllsWeb\InRegistry\EppException::class, 'Invalid phone number');
    $c->logout();
});

test('schema: host check/info/create/update/delete, poll req/ack', function () {
    $r = new FakeRegistry();
    $c = client($r);
    $check = $c->hostCheck(['ns1.example.in', 'ns2.example.in']);
    eq([true, true], array_column($check['host'], 'avail'));
    $c->hostCreate('ns1.example.in', ['192.0.2.1', '2001:db8::1', 'not-an-ip']);
    eq(['192.0.2.1', '2001:db8::1'], $r->hosts['ns1.example.in']['addr']);
    $c->hostCreate('ns2.example.in');
    $info = $c->hostInfo('ns1.example.in');
    eq(['192.0.2.1', '2001:db8::1'], $info['host']['addr']);
    $c->hostUpdate('ns1.example.in', ['192.0.2.2'], ['192.0.2.1']);
    eq(['2001:db8::1', '192.0.2.2'], $r->hosts['ns1.example.in']['addr']);
    $before = count($r->commands);
    $c->hostUpdate('ns1.example.in', [], []);
    eq($before, count($r->commands), 'empty host update sends nothing');
    $c->hostDelete('ns2.example.in');
    $empty = $c->pollReq();
    eq(1300, $empty['code']);
    $r->pollQueue[] = ['id' => '42', 'msg' => 'Transfer approved'];
    $poll = $c->pollReq();
    eq('42', $poll['poll']['msg_last_id']);
    eq('Transfer approved', $poll['poll']['msg']);
    eq('1', $poll['poll']['msg_count']);
    $c->pollAck('42');
    $c->logout();
});

test('schema: empty status list sends no update', function () {
    $r = new FakeRegistry();
    $r->addDomain('example.in');
    $c = client($r);
    $res = $c->domainUpdateStatus('example.in', []);
    eq(true, $res['domain']['success']);
    $c->domainUpdateNS('example.in', [], []);
    eq(['login'], $r->keys());
    $c->logout();
});
