<?php

namespace AllsWeb\InRegistry\Tests;

use AllsWeb\InRegistry as IR;

function registryWithDomain(array $domain = [])
{
    $r = new FakeRegistry();
    $r->addContact('CON1');
    $r->addHost('ns1.example.net');
    $r->addHost('ns2.example.net');
    $r->addDomain('example.in', array_merge(['ns' => ['ns1.example.net', 'ns2.example.net']], $domain));
    fake($r);
    return $r;
}

function logActions()
{
    return array_column($GLOBALS['__modulelog'], 'action');
}

test('flow: register domain (contact, hosts, create, lock)', function () {
    $r = fake();
    $res = IR\ok_epp_RegisterDomain(params(['regperiod' => 2]), 'example.in', ['ns1.example.net', 'ns2.example.net']);
    eq(['success' => true], $res);
    eq(['login', 'check:domain', 'check:contact', 'create:contact', 'check:host', 'create:host', 'create:host', 'create:domain', 'update:domain', 'logout'], $r->keys());
    $d = $r->domains['example.in'];
    eq(['clientTransferProhibited'], $d['status']);
    eq(['ns1.example.net', 'ns2.example.net'], $d['ns']);
    $cid = $d['registrant'];
    ok(preg_match('/^ABCD_[A-Z0-9]{4}123$/', $cid) === 1, "contact id {$cid}");
    eq([['admin', $cid], ['tech', $cid], ['billing', $cid]], $d['contacts']);
    eq('2028-11-14', gmdate('Y-m-d', $d['exDate']));
    eq('Asha Rao', $r->contacts[$cid]['postal'][0]['name']);
});

test('flow: register succeeds when the lock fails after create', function () {
    $r = fake();
    $r->failures['update:domain'] = ['code' => 2304, 'msg' => 'Object status prohibits operation'];
    $res = IR\ok_epp_RegisterDomain(params(), 'example.in', []);
    eq(['success' => true], $res);
    ok(isset($r->domains['example.in']));
    contains('RegisterDomain-lock', implode(',', logActions()));
});

test('flow: register succeeds when logout fails (connection dropped)', function () {
    $r = fake();
    $r->dropOnLogout = true;
    eq(['success' => true], IR\ok_epp_RegisterDomain(params(), 'example.in', []));
});

test('flow: domain create failure deletes the orphan contact', function () {
    $r = fake();
    $r->failures['create:domain'] = ['code' => 2306, 'msg' => 'Parameter value policy error', 'reason' => 'Name not allowed'];
    $res = IR\ok_epp_RegisterDomain(params(), 'example.in', ['ns1.example.net']);
    eq('Parameter value policy error: Name not allowed (EPP 2306)', $res['error']);
    eq(['login', 'check:domain', 'check:contact', 'create:contact', 'check:host', 'create:host', 'create:domain', 'delete:contact', 'logout'], $r->keys());
    eq([], $r->contacts);

    $r = fake();
    $r->failures['create:host'] = ['code' => 2005, 'msg' => 'Parameter value syntax error'];
    $res = IR\ok_epp_RegisterDomain(params(), 'example.in', ['ns1.example.org']);
    contains('EPP 2005', $res['error']);
    eq([], $r->contacts, 'host failure also cleans up');
});

test('flow: unavailable domain reports the registry reason', function () {
    $r = fake();
    $r->reserved['example.in'] = 'Reserved by registry policy';
    eq(['error' => 'Domain is not available: Reserved by registry policy'], IR\ok_epp_RegisterDomain(params(), 'example.in', []));
    eq(['login', 'check:domain', 'logout'], $r->keys());

    $r = fake();
    $r->addDomain('example.in');
    eq(['error' => 'Domain is not available: In use'], IR\ok_epp_RegisterDomain(params(), 'example.in', []));
});

test('flow: in-zone nameserver that does not exist stops register before any contact', function () {
    $r = fake();
    $res = IR\ok_epp_RegisterDomain(params(), 'example.in', ['ns1.example.net', 'NS1.Example.IN']);
    eq(['error' => 'Nameserver ns1.example.in is under example.in: register it as a private nameserver with its IP address first.'], $res);
    eq(['login', 'check:domain', 'check:host', 'logout'], $r->keys());
    eq([], $r->contacts);
    eq([], $r->hosts);

    // Existing in-zone host (and the domain name itself as a host) is fine when it exists;
    // look-alikes such as ns1.myexample.in are not under example.in.
    $r = fake();
    $r->addHost('ns1.myexample.in');
    $r->addHost('ns2.otherexample.in');
    eq(['success' => true], IR\ok_epp_RegisterDomain(params(), 'example.in', ['ns1.myexample.in', 'ns2.otherexample.in']));
});

test('flow: SaveNameservers refuses a missing in-zone nameserver, accepts an existing one', function () {
    $r = registryWithDomain();
    $res = IR\ok_epp_SaveNameservers(params(), 'example.in', ['ns1.example.net', 'ns9.example.in']);
    eq(['error' => 'Nameserver ns9.example.in is under example.in: register it as a private nameserver with its IP address first.'], $res);
    eq([], $r->commandsFor('create:host'));
    eq([], $r->commandsFor('update:domain'));
    $r->addHost('ns9.example.in', ['192.0.2.9']);
    eq(['success' => true], IR\ok_epp_SaveNameservers(params(), 'example.in', ['ns1.example.net', 'ns9.example.in']));
    eq(['ns1.example.net', 'ns9.example.in'], $r->domains['example.in']['ns']);
    ok(IR\ok_epp_isSubordinateHost('example.in.', 'Example.IN'));
    ok(!IR\ok_epp_isSubordinateHost('ns1.myexample.in', 'example.in'));
});

test('flow: register a zone outside the old allow-list reaches the registry', function () {
    $r = fake();
    eq(['success' => true], IR\ok_epp_RegisterDomain(params(['tld' => 'edu.in']), 'example.edu.in', []));
    ok(isset($r->domains['example.edu.in']));
});

test('flow: contact ID collisions retry, then give up', function () {
    $c = client(new FakeRegistry());
    $calls = 0;
    $id = IR\ok_epp_generateUniqueContactId(new class($c, $calls) {
        private $c;
        private $n;
        public function __construct($c, &$n)
        {
            $this->c = $c;
            $this->n = &$n;
        }
        public function contactCheck($id)
        {
            $this->n++;
            $res = $this->c->contactCheck($id);
            if ($this->n < 3) {
                $res['contact'][0]['avail'] = false;
            }
            return $res;
        }
    }, 'ABCD', 99);
    eq(3, $calls);
    ok(strlen($id) <= 16);
    throws(function () use ($c) {
        IR\ok_epp_generateUniqueContactId(new class {
            public function contactCheck($id)
            {
                return ['contact' => [['id' => $id, 'avail' => false]]];
            }
        }, 'ABCD', 1);
    }, IR\EppException::class, '5 attempts');
    $c->logout();
});

test('flow: SaveNameservers compares case-insensitively and creates missing hosts', function () {
    $r = registryWithDomain(['ns' => ['NS1.Example.NET', 'ns2.example.net']]);
    $res = IR\ok_epp_SaveNameservers(params(), 'example.in', ['ns1.example.net.', 'NS2.EXAMPLE.NET', 'ns3.new.net']);
    eq(['success' => true], $res);
    eq(['login', 'info:domain', 'check:host', 'create:host', 'update:domain', 'logout'], $r->keys());
    $up = xp(lastCommand($r, 'update:domain'));
    eq(['ns3.new.net'], array_map(function ($n) {
        return $n->textContent;
    }, iterator_to_array($up->query('//domain:add//domain:hostObj'))));
    eq(0, $up->query('//domain:rem')->length);

    $res = IR\ok_epp_SaveNameservers(params(), 'example.in', ['ns3.new.net']);
    eq(['success' => true], $res);
    $up = xp(lastCommand($r, 'update:domain'));
    eq(['NS1.Example.NET', 'ns2.example.net'], array_map(function ($n) {
        return $n->textContent;
    }, iterator_to_array($up->query('//domain:rem//domain:hostObj'))), 'removed with the registry spelling');
    eq(['ns3.new.net'], $r->domains['example.in']['ns']);

    $before = count($r->commands);
    eq(['success' => true], IR\ok_epp_SaveNameservers(params(), 'example.in', ['NS3.NEW.NET']));
    eq(['login', 'info:domain', 'logout'], array_slice($r->keys(), $before));

    eq(['error' => 'Empty Save Nameservers'], IR\ok_epp_SaveNameservers(params(), 'example.in', ['', ' ']));
});

test('flow: registrar lock only changes what differs', function () {
    $r = registryWithDomain(['status' => ['clientTransferProhibited']]);
    eq(['success' => true], IR\ok_epp_SaveRegistrarLock(params(), 'example.in', 'locked'));
    eq(['login', 'info:domain', 'logout'], $r->keys());
    eq('locked', IR\ok_epp_GetRegistrarLock(params(), 'example.in'));
    eq(['success' => true], IR\ok_epp_SaveRegistrarLock(params(), 'example.in', 'unlocked'));
    contains('<domain:rem><domain:status s="clientTransferProhibited"/></domain:rem>', lastCommand($r, 'update:domain'));
    eq('unlocked', IR\ok_epp_GetRegistrarLock(params(), 'example.in'));
    $n = count($r->commandsFor('update:domain'));
    eq(['success' => true], IR\ok_epp_SaveRegistrarLock(params(), 'example.in', 'unlocked'));
    eq($n, count($r->commandsFor('update:domain')));
});

test('flow: GetEPPCode returns the stored code or sets a new one', function () {
    $r = registryWithDomain(['authInfo' => 'Stored-Code-1']);
    eq(['eppcode' => 'Stored-Code-1'], IR\ok_epp_GetEPPCode(params(), 'example.in'));
    $r->domains['example.in']['authInfo'] = '';
    $res = IR\ok_epp_GetEPPCode(params(), 'example.in');
    ok(isset($res['eppcode']) && strlen($res['eppcode']) === 16);
    eq($res['eppcode'], $r->domains['example.in']['authInfo']);
});

test('flow: renew without auto-renew sends N years with curExpDate', function () {
    $r = registryWithDomain();
    eq(['success' => true], IR\ok_epp_RenewDomain(params(['regperiod' => 2]), 'example.in'));
    $xp = xp(lastCommand($r, 'renew:domain'));
    eq('2', $xp->query('//domain:period')->item(0)->textContent);
    eq('2027-01-10', $xp->query('//domain:curExpDate')->item(0)->textContent);
    eq('2029-01-10', gmdate('Y-m-d', $r->domains['example.in']['exDate']));
});

test('flow: renew during autoRenewPeriod: N=1 sends nothing, N=3 renews 2', function () {
    $r = registryWithDomain(['rgp' => ['autoRenewPeriod'], 'exDate' => strtotime('2027-11-01 10:00:00 UTC')]);
    eq(['success' => true], IR\ok_epp_RenewDomain(params(['regperiod' => 1]), 'example.in'));
    eq([], $r->commandsFor('renew:domain'));
    contains('RenewDomain-autoRenew', implode(',', logActions()));

    eq(['success' => true], IR\ok_epp_RenewDomain(params(['regperiod' => 3]), 'example.in'));
    $xp = xp(lastCommand($r, 'renew:domain'));
    eq('2', $xp->query('//domain:period')->item(0)->textContent);
    eq('2027-11-01', $xp->query('//domain:curExpDate')->item(0)->textContent);
});

function whmcsExpiry($date)
{
    IR\WhmcsExpiry::$resolver = function () use (&$date) {
        return $date;
    };
}

test('flow: auto-renew grace, unpaid -> paid 1y -> second paid 1y', function () {
    // Auto-renewed 10 days ago: the registry exDate is already a year ahead.
    $r = registryWithDomain(['rgp' => ['autoRenewPeriod'], 'exDate' => strtotime('+355 days')]);
    $e = gmdate('Y-m-d', $r->domains['example.in']['exDate']);
    $eMinus1 = gmdate('Y-m-d', strtotime('-1 year', $r->domains['example.in']['exDate']));
    whmcsExpiry($eMinus1); // WHMCS still has the old paid-through date

    eq(['expired' => true, 'expirydate' => $eMinus1], IR\ok_epp_SyncDomain(params(), 'example.in'));
    eq($eMinus1, inregistry_GetDomainInformation(params())->values['ExpiryDate']->toDateString());

    // Customer pays 1 year: the auto-renewed year is accepted, no command.
    eq(['success' => true], IR\ok_epp_RenewDomain(params(['regperiod' => 1]), 'example.in'));
    eq([], $r->commandsFor('renew:domain'));

    // WHMCS moved its expiry date forward by the year it billed: W = E.
    whmcsExpiry($e);
    eq(['active' => true, 'expirydate' => $e], IR\ok_epp_SyncDomain(params(), 'example.in'));
    eq($e, inregistry_GetDomainInformation(params())->values['ExpiryDate']->toDateString());

    // A second paid year inside the same grace is a real renew.
    eq(['success' => true], IR\ok_epp_RenewDomain(params(['regperiod' => 1]), 'example.in'));
    $xp = xp(lastCommand($r, 'renew:domain'));
    eq('1', $xp->query('//domain:period')->item(0)->textContent);
    eq($e, $xp->query('//domain:curExpDate')->item(0)->textContent);
});

test('flow: auto-renew grace, unpaid N=3 renews 2; W unknown behaves as unpaid; no grace unchanged', function () {
    $r = registryWithDomain(['rgp' => ['autoRenewPeriod'], 'exDate' => strtotime('2027-11-01 10:00:00 UTC')]);
    whmcsExpiry('2026-11-01');
    eq(['success' => true], IR\ok_epp_RenewDomain(params(['regperiod' => 3]), 'example.in'));
    eq('2', xp(lastCommand($r, 'renew:domain'))->query('//domain:period')->item(0)->textContent);

    foreach (['', '0000-00-00', 'not a date', null] as $unknown) {
        $r = registryWithDomain(['rgp' => ['autoRenewPeriod'], 'exDate' => strtotime('2027-11-01 10:00:00 UTC')]);
        whmcsExpiry($unknown);
        eq('2026-11-01', IR\ok_epp_SyncDomain(params(), 'example.in')['expirydate'], var_export($unknown, true));
        IR\ok_epp_RenewDomain(params(['regperiod' => 1]), 'example.in');
        eq([], $r->commandsFor('renew:domain'));
    }

    // Without autoRenewPeriod, W does not matter.
    $r = registryWithDomain(['exDate' => strtotime('2027-11-01 10:00:00 UTC')]);
    whmcsExpiry('2020-01-01');
    eq('2027-11-01', IR\ok_epp_SyncDomain(params(), 'example.in')['expirydate']);
    IR\ok_epp_RenewDomain(params(['regperiod' => 1]), 'example.in');
    eq('1', xp(lastCommand($r, 'renew:domain'))->query('//domain:period')->item(0)->textContent);
});

test('flow: auto-renew grace leap day and stored expiry from tbldomains', function () {
    registryWithDomain(['rgp' => ['autoRenewPeriod'], 'exDate' => strtotime('2028-02-29 10:00:00 UTC')]);
    \WHMCS\Database\Capsule::$tables['tbldomains'] = [['id' => 123, 'expirydate' => '2027-02-28']];
    eq('2027-02-28', IR\ok_epp_SyncDomain(params(), 'example.in')['expirydate'], 'unpaid: E - 1y on a leap day');
    \WHMCS\Database\Capsule::$tables['tbldomains'] = [['id' => 123, 'expirydate' => '2028-02-29']];
    eq('2028-02-29', IR\ok_epp_SyncDomain(params(), 'example.in')['expirydate'], 'paid: E');
    \WHMCS\Database\Capsule::$tables['tbldomains'] = [['id' => 123, 'expirydate' => '0000-00-00']];
    eq('2027-02-28', IR\ok_epp_SyncDomain(params(), 'example.in')['expirydate']);
});

test('flow: renew error is reported', function () {
    $r = registryWithDomain();
    $r->failures['renew:domain'] = ['code' => 2105, 'msg' => 'Object is not eligible for renewal'];
    eq(['error' => 'Object is not eligible for renewal (EPP 2105)'], IR\ok_epp_RenewDomain(params(), 'example.in'));
});

test('flow: Sync reports the paid-through date during autoRenewPeriod', function () {
    registryWithDomain(['exDate' => strtotime('2099-03-05 10:00:00 UTC')]);
    eq(['active' => true, 'expirydate' => '2099-03-05'], IR\ok_epp_SyncDomain(params(), 'example.in'));

    $r = registryWithDomain(['exDate' => strtotime('+300 days'), 'rgp' => ['autoRenewPeriod']]);
    $expected = gmdate('Y-m-d', strtotime('-1 year', $r->domains['example.in']['exDate']));
    eq(['expired' => true, 'expirydate' => $expected], IR\ok_epp_SyncDomain(params(), 'example.in'));

    registryWithDomain(['exDate' => strtotime('2028-02-29 10:00:00 UTC'), 'rgp' => ['autoRenewPeriod']]);
    eq('2027-02-28', IR\ok_epp_SyncDomain(params(), 'example.in')['expirydate']);

    registryWithDomain(['exDate' => strtotime('2001-01-01 00:00:00 UTC')]);
    eq(['expired' => true, 'expirydate' => '2001-01-01'], IR\ok_epp_SyncDomain(params(), 'example.in'));
});

test('flow: TransferSync statuses', function () {
    $r = fake();
    $r->transfers['example.in'] = ['trStatus' => 'pending', 'exDate' => 0];
    eq(['completed' => false], IR\ok_epp_TransferDomainSync(params(), 'example.in'));
    $r->transfers['example.in']['trStatus'] = 'clientRejected';
    eq(['completed' => false, 'failed' => true, 'reason' => 'clientRejected'], IR\ok_epp_TransferDomainSync(params(), 'example.in'));
});

test('flow: TransferSync completes without contact params (cron)', function () {
    $r = registryWithDomain();
    $r->transfers['example.in'] = ['trStatus' => 'serverApproved', 'exDate' => strtotime('2027-05-01 00:00:00 UTC')];
    $p = params();
    foreach (['firstname', 'lastname', 'email', 'address1', 'city', 'country', 'fullphonenumber', 'userid'] as $k) {
        unset($p[$k]);
    }
    eq(['completed' => true, 'expirydate' => '2027-05-01'], IR\ok_epp_TransferDomainSync($p, 'example.in'));
    eq([], $r->commandsFor('create:contact'), 'no contact data: post-transfer step skipped');
});

test('flow: TransferSync post-transfer step uses the client record', function () {
    $r = registryWithDomain(['status' => ['ok']]);
    $r->transfers['example.in'] = ['trStatus' => 'clientApproved', 'exDate' => strtotime('2027-05-01 00:00:00 UTC')];
    \WHMCS\Database\Capsule::$tables['tbldomains'] = [['id' => 123, 'userid' => 9, 'type' => 'Transfer', 'status' => 'Pending Transfer']];
    $GLOBALS['__clients'][9] = [
        'firstname' => 'Ravi', 'lastname' => 'K', 'companyname' => '', 'email' => 'ravi@example.in',
        'address1' => 'Line 1', 'address2' => '', 'city' => 'Mumbai', 'state' => 'MH', 'postcode' => '400001',
        'countrycode' => 'IN', 'phonenumberformatted' => '+91.9123456789', 'phonecc' => '91',
    ];
    $p = params();
    foreach (['firstname', 'email', 'userid'] as $k) {
        unset($p[$k]);
    }
    eq(['completed' => true, 'expirydate' => '2027-05-01'], IR\ok_epp_TransferDomainSync($p, 'example.in'));
    eq(1, count($r->commandsFor('create:contact')));
    $d = $r->domains['example.in'];
    $new = $d['registrant'];
    ok($new !== 'CON1');
    eq('Ravi K', $r->contacts[$new]['postal'][0]['name']);
    eq([['admin', $new], ['tech', $new], ['billing', $new]], $d['contacts']);
    eq(['clientTransferProhibited'], $d['status']);
    ok($d['authInfo'] !== 'Auth-Code+123' && strlen($d['authInfo']) === 16);
});

test('flow: TransferSync completed even if the post-transfer step fails', function () {
    $r = registryWithDomain();
    $r->transfers['example.in'] = ['trStatus' => 'serverApproved', 'exDate' => 0];
    $r->failures['create:contact'] = ['code' => 2306, 'msg' => 'Parameter value policy error'];
    $res = IR\ok_epp_TransferDomainSync(params(), 'example.in');
    eq(['completed' => true, 'expirydate' => '2027-01-10'], $res);
    contains('TransferSync-postTransfer', implode(',', logActions()));
});

test('flow: TransferSync when the registry no longer knows the transfer', function () {
    $r = registryWithDomain(['clID' => 'REGISTRAR1']);
    $res = IR\ok_epp_TransferDomainSync(params(), 'example.in');
    eq(true, $res['completed']);
    eq('2027-01-10', $res['expirydate']);

    $r = registryWithDomain(['clID' => 'OTHERREG']);
    contains('Object not pending transfer', IR\ok_epp_TransferDomainSync(params(), 'example.in')['error']);
});

test('flow: a logout that throws does not change the result', function () {
    $r = new FakeRegistry();
    $r->addDomain('example.in', ['ns' => ['a.example.net']]);
    \AllsWeb\InRegistry\ClientFactory::$factory = function ($p) use ($r) {
        $c = new class(\AllsWeb\InRegistry\ok_epp_buildConfig($p), $r) extends ScriptedEppClient {
            public function logout()
            {
                throw new \RuntimeException('socket gone');
            }
        };
        $c->login($p['clid'], $p['pw']);
        return $c;
    };
    eq(['a.example.net'], IR\ok_epp_GetNameservers(params(), 'example.in'));
});

test('flow: connection failure and PHP errors become error arrays', function () {
    \AllsWeb\InRegistry\ClientFactory::$factory = function () {
        throw new IR\EppException('Could not connect to tls://epp.ote.nixiregistry.in:700: handshake failure (error 0)');
    };
    eq(['error' => 'Could not connect to tls://epp.ote.nixiregistry.in:700: handshake failure (error 0)'], IR\ok_epp_RequestDelete(params(), 'example.in'));
    registryWithDomain();
    $res = IR\ok_epp_withClient(params(), function () {
        return strlen([]);
    });
    contains('Module error', $res['error']);
});

test('flow: SaveContactDetails splits a shared contact, skips unchanged roles', function () {
    $r = registryWithDomain();
    $current = IR\ok_epp_GetContactDetails(params(), 'example.in');
    eq(['Registrant', 'Admin', 'Tech', 'Billing'], array_keys($current));
    eq('+91.9876543210', $current['Admin']['Phone Number']);

    // Unchanged (WHMCS posts the number without the dot and with a country code): no writes.
    $post = $current;
    foreach ($post as &$role) {
        $role['Phone Number'] = '9876543210';
        $role['Phone Country Code'] = '91';
        unset($role['Address 2']);
    }
    unset($role, $post['Billing']);
    $before = count($r->commands);
    eq(['success' => true], IR\ok_epp_SaveContactDetails(params(['contactdetails' => $post]), 'example.in'));
    eq(['login', 'info:domain', 'info:contact', 'logout'], array_slice($r->keys(), $before));

    // Admin edited: shared with other roles, so it gets its own contact.
    $post['Admin']['Full Name'] = 'New Admin';
    $post['Admin']['Company Name'] = '';
    eq(['success' => true], IR\ok_epp_SaveContactDetails(params(['contactdetails' => $post]), 'example.in'));
    $d = $r->domains['example.in'];
    $admin = array_values(array_filter($d['contacts'], function ($c) {
        return $c[0] === 'admin';
    }));
    eq(1, count($admin));
    ok($admin[0][1] !== 'CON1');
    eq('New Admin', $r->contacts[$admin[0][1]]['postal'][0]['name']);
    eq('+91.9876543210', $r->contacts[$admin[0][1]]['voice']);
    eq('Asha Rao', $r->contacts['CON1']['postal'][0]['name']);

    // Admin edited again: now unshared, so updated in place.
    $post['Admin']['City'] = 'Mysuru';
    $n = count($r->commandsFor('create:contact'));
    eq(['success' => true], IR\ok_epp_SaveContactDetails(params(['contactdetails' => $post]), 'example.in'));
    eq($n, count($r->commandsFor('create:contact')));
    eq('Mysuru', $r->contacts[$admin[0][1]]['postal'][0]['city']);

    // A number that cannot be normalised fails clearly.
    $post['Tech']['Phone Number'] = 'call me';
    contains('Invalid phone number', IR\ok_epp_SaveContactDetails(params(['contactdetails' => $post]), 'example.in')['error']);
    eq(['error' => 'contact details Empty'], IR\ok_epp_SaveContactDetails(params(), 'example.in'));
});

test('flow: nameserver (host) management', function () {
    $r = fake();
    eq(['success' => true], IR\ok_epp_RegisterNameserver(params(['nameserver' => 'ns1.example.in', 'ipaddress' => '192.0.2.10'])));
    eq(['192.0.2.10'], $r->hosts['ns1.example.in']['addr']);
    eq(['success' => true], IR\ok_epp_ModifyNameserver(params(['nameserver' => 'ns1.example.in', 'currentipaddress' => '192.0.2.10', 'newipaddress' => '192.0.2.11'])));
    eq(['192.0.2.11'], $r->hosts['ns1.example.in']['addr']);
    eq(['success' => true], IR\ok_epp_DeleteNameserver(params(['nameserver' => 'ns1.example.in'])));
    eq([], $r->hosts);
    eq(['error' => 'Empty required parameters values'], IR\ok_epp_RegisterNameserver(params()));
    eq(['error' => 'Empty required parameters values'], IR\ok_epp_ModifyNameserver(params(['nameserver' => 'x'])));
    eq(['error' => 'Empty required parameters values'], IR\ok_epp_DeleteNameserver(params()));
});
