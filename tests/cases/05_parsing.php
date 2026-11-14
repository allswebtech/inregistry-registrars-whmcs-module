<?php

namespace AllsWeb\InRegistry\Tests;

const STYLES = ['canonical', 'alt', 'default'];

test('parsing: domain info with 0/1/many ns, 1/3 contacts, statuses, rgp, in every prefix style', function () {
    foreach (STYLES as $style) {
        $r = new FakeRegistry();
        $r->style = $style;
        $r->addDomain('zero.in', ['ns' => [], 'contacts' => [['tech', 'TEC1']], 'status' => ['ok'], 'registrant' => '']);
        $r->addDomain('one.in', ['ns' => ['NS1.Example.NET'], 'status' => [['clientHold', 'Payment due']]]);
        $r->addDomain('many.in', [
            'ns' => ['ns3.b.in', 'ns1.a.in', 'ns2.a.in'],
            'hosts' => ['ns1.many.in', 'ns2.many.in'],
            'contacts' => [['admin', 'ADM1'], ['tech', 'TEC1'], ['billing', 'BIL1'], ['tech', 'TEC2']],
            'status' => ['clientTransferProhibited', ['clientUpdateProhibited', 'locked'], 'serverDeleteProhibited'],
            'rgp' => ['autoRenewPeriod'],
            'authInfo' => '',
        ]);
        $c = client($r);

        $zero = $c->domainInfo('zero.in')['domain'];
        eq([], $zero['ns'], $style);
        eq(['ok'], $zero['status'], $style);
        eq('', $zero['registrant'], $style);
        eq(['tech' => 'TEC1'], $zero['contact'], $style);
        eq([], $zero['rgpStatus'], $style);
        eq('2027-01-10T10:00:00.0Z', $zero['exDate'], $style);
        eq('Auth-Code+123', $zero['authInfo'], $style);
        eq('REGISTRAR1', $zero['clID'], $style);

        $one = $c->domainInfo('one.in')['domain'];
        eq(['NS1.Example.NET'], $one['ns'], $style);
        eq(['clientHold'], $one['status'], "{$style}: status with text content");
        eq(['admin' => 'CON1', 'tech' => 'CON1', 'billing' => 'CON1'], $one['contact'], $style);
        eq('CON1', $one['registrant'], $style);

        $many = $c->domainInfo('many.in')['domain'];
        eq(['ns1.a.in', 'ns2.a.in', 'ns3.b.in'], $many['ns'], $style);
        eq(['ns1.many.in', 'ns2.many.in'], $many['host'], $style);
        eq(['admin' => 'ADM1', 'tech' => 'TEC1', 'billing' => 'BIL1'], $many['contact'], $style);
        eq(4, count($many['contacts']), $style);
        eq(['clientTransferProhibited', 'clientUpdateProhibited', 'serverDeleteProhibited'], $many['status'], $style);
        eq(['autoRenewPeriod'], $many['rgpStatus'], $style);
        eq('', $many['authInfo'], $style);

        $req = xp(lastCommand($r, 'info:domain'));
        eq('all', $req->query('//domain:name')->item(0)->getAttribute('hosts'));
        $c->logout();
    }
});

test('parsing: hostAttr nameservers are accepted', function () {
    $r = new FakeRegistry();
    $c = client($r);
    $r->canned[] = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
        . '<resData><d:infData xmlns:d="urn:ietf:params:xml:ns:domain-1.0"><d:name>attr.in</d:name><d:roid>D1-IN</d:roid><d:status s="ok"/>'
        . '<d:ns><d:hostAttr><d:hostName>ns2.attr.in</d:hostName><d:hostAddr ip="v4">192.0.2.1</d:hostAddr></d:hostAttr>'
        . '<d:hostAttr><d:hostName>ns1.other.net</d:hostName></d:hostAttr></d:ns><d:clID>R</d:clID></d:infData></resData>'
        . '<trID><svTRID>SV-1</svTRID></trID></response></epp>';
    $info = $c->domainInfo('attr.in')['domain'];
    eq(['ns1.other.net', 'ns2.attr.in'], $info['ns']);
    eq([], $info['contact']);
    eq('', $info['exDate']);
    $c->logout();
});

test('parsing: contact info with 1 or 2 postalInfo, 1-3 streets, missing optionals', function () {
    foreach (STYLES as $style) {
        $r = new FakeRegistry();
        $r->style = $style;
        $r->addContact('ONE', ['postal' => [['type' => 'loc', 'name' => 'Local Name', 'org' => '', 'street' => ['Only street'], 'city' => 'Delhi', 'sp' => '', 'pc' => '', 'cc' => 'IN']], 'voice' => '', 'status' => ['ok', 'linked']]);
        $r->addContact('TWO', [
            'postal' => [
                ['type' => 'loc', 'name' => 'स्थानीय', 'org' => 'स्थानीय', 'street' => ['a'], 'city' => 'दिल्ली', 'sp' => '', 'pc' => '', 'cc' => 'IN'],
                ['type' => 'int', 'name' => 'Intl Name', 'org' => 'Intl Org', 'street' => ['s1', 's2', 's3'], 'city' => 'Delhi', 'sp' => 'DL', 'pc' => '110001', 'cc' => 'IN'],
            ],
            'authInfo' => 'cAuth-123',
        ]);
        $c = client($r);
        $one = $c->contactInfo('ONE')['contact'];
        eq('Local Name', $one['postalInfo']['name'], $style);
        eq('loc', $one['postalInfo']['type'], $style);
        eq(['Only street'], $one['postalInfo']['addr']['street'], $style);
        eq('Only street', $one['postalInfo']['addr']['street1'], $style);
        ok(!isset($one['postalInfo']['addr']['street2']), $style);
        eq('', $one['postalInfo']['org'], $style);
        eq('', $one['postalInfo']['addr']['state'], $style);
        eq('', $one['postalInfo']['addr']['postal'], $style);
        eq('', $one['voice'], $style);
        eq('', $one['authInfo'], $style);
        eq(['ok', 'linked'], $one['status'], $style);

        $two = $c->contactInfo('TWO')['contact'];
        eq('Intl Name', $two['postalInfo']['name'], "{$style}: int preferred");
        eq('int', $two['postalInfo']['type'], $style);
        eq('s3', $two['postalInfo']['addr']['street3'], $style);
        eq('110001', $two['postalInfo']['addr']['postal'], $style);
        eq('+91.9876543210', $two['voice'], $style);
        eq('cAuth-123', $two['authInfo'], $style);
        eq(['ok'], $two['status'], $style);
        $c->logout();
    }
});

test('parsing: check responses with avail as 1/0 and true/false', function () {
    foreach ([['true', 'false'], ['1', '0']] as $words) {
        foreach (STYLES as $style) {
            $r = new FakeRegistry();
            $r->style = $style;
            $r->availWords = $words;
            $r->addDomain('taken.in');
            $r->reserved['blocked.in'] = 'Reserved name';
            $r->addHost('ns1.used.in');
            $r->addContact('USED1');
            $c = client($r);
            $res = $c->domainCheck(['free.in', 'taken.in', 'blocked.in']);
            eq([true, false, false], array_column($res['domain'], 'avail'), "{$style}/{$words[0]}");
            eq(['available', 'unavailable', 'unavailable'], array_column($res['domain'], 'status'));
            eq(['', 'In use', 'Reserved name'], array_column($res['domain'], 'reason'));
            eq(['free.in', 'taken.in', 'blocked.in'], array_column($res['domain'], 'name'));
            eq(1000, $res['code']);
            eq([false, true], array_column($c->hostCheck(['ns1.used.in', 'ns2.free.in'])['host'], 'avail'));
            eq([false, true], array_column($c->contactCheck(['USED1', 'FREE1'])['contact'], 'avail'));
            $c->logout();
        }
    }
});

test('parsing: domainCheck sends plain RFC 5731 check, no ARI extension', function () {
    $r = new FakeRegistry();
    $c = client($r);
    $c->domainCheck('example.in');
    $xml = lastCommand($r, 'check:domain');
    notContains('urn:ar:params', $xml);
    eq(0, xp($xml)->query('//epp:extension')->length);
    $c->logout();
});
