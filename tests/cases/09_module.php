<?php

namespace AllsWeb\InRegistry\Tests;

// Every inregistry_* function, called the way WHMCS calls it.

function moduleRegistry()
{
    $r = new FakeRegistry();
    $r->addContact('CON1');
    $r->addHost('ns1.example.net');
    $r->addHost('ns2.example.net');
    $r->addDomain('example.in', [
        'ns' => ['ns1.example.net', 'ns2.example.net'],
        'status' => ['clientTransferProhibited'],
        'exDate' => strtotime('2099-06-30 10:00:00 UTC'),
    ]);
    return fake($r);
}

test('module: metadata and config', function () {
    eq(['DisplayName' => 'IN Registry', 'APIVersion' => '1.1', 'NonLinearRegistrationPricing' => true], inregistry_MetaData());
    $config = inregistry_GetConfigArray();
    eq(['Description', 'local_cert', 'local_pk', 'passphrase', 'cafile', 'clid', 'pw', 'identityprefix', 'TestMode'], array_keys($config));
    eq('OT&E (Test) Mode', $config['TestMode']['FriendlyName']);
    contains('github.com/allswebtech/inregistry-registrars-whmcs-module', $config['Description']['Value']);
    contains('Unofficial', $config['Description']['Value']);
    contains('outside the web root', $config['local_cert']['Description']);
    eq('.in', inregistry_GetDomainExtensionGroup()['ccTLD'][0]);
    $json = json_decode(file_get_contents(MODULE_DIR . '/whmcs.json'), true);
    eq('MIT', $json['license']);
    eq(\AllsWeb\InRegistry\VERSION, $json['version']);
    eq('AllsWeb', $json['authors'][0]['name']);
});

test('module: buildConfig', function () {
    $c = \AllsWeb\InRegistry\ok_epp_buildConfig(['TestMode' => 'on', 'cafile' => ' /x/ca.pem ']);
    eq('epp.ote.nixiregistry.in', $c['host']);
    eq(700, $c['port']);
    eq('/x/ca.pem', $c['cafile']);
    ok(($c['crypto_method'] & STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT) === STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
    eq('epp.nixiregistry.in', \AllsWeb\InRegistry\ok_epp_buildConfig([])['host']);
});

test('module: config_validate', function () {
    $cert = tempnam(sys_get_temp_dir(), 'crt');
    $valid = ['clid' => 'REGISTRAR1', 'pw' => 'secret12', 'local_cert' => $cert, 'local_pk' => '', 'cafile' => '', 'identityprefix' => 'AB12'];
    $r = fake();
    inregistry_config_validate($valid);
    eq(['login', 'logout'], $r->keys());
    $bad = [
        [['clid' => ''], 'Username'],
        [['pw' => null], 'Password'],
        [['local_cert' => '/nonexistent/cert.pem'], 'Certificate'],
        [['local_pk' => '/nonexistent/key.pem'], 'Private Key'],
        [['cafile' => '/nonexistent/ca.pem'], 'CA Certificate'],
        [['identityprefix' => 'ABCDE'], 'Identity Prefix'],
        [['identityprefix' => 'A_B'], 'Identity Prefix'],
    ];
    foreach ($bad as $case) {
        throws(function () use ($valid, $case) {
            inregistry_config_validate(array_merge($valid, $case[0]));
        }, \WHMCS\Exception\Module\InvalidConfiguration::class, $case[1]);
    }
    throws(function () use ($cert) {
        inregistry_config_validate(['local_cert' => $cert]);
    }, \WHMCS\Exception\Module\InvalidConfiguration::class);
    $r->failures['login'] = ['code' => 2200, 'msg' => 'Authentication error'];
    throws(function () use ($valid) {
        inregistry_config_validate($valid);
    }, \WHMCS\Exception\Module\InvalidConfiguration::class, 'Authentication error (EPP 2200)');
    unlink($cert);
});

test('module: domain name from domainObj, domainname or sld.tld', function () {
    eq('example.in', inregistry_getDomainName(['domainObj' => new \WHMCS\Domains\Domain('Example.IN')]));
    eq('example.co.in', inregistry_getDomainName(['domainname' => 'EXAMPLE.co.in']));
    eq('example.in', inregistry_getDomainName(['sld' => 'Example', 'tld' => '.in']));
    eq('Example.IN', inregistry_getDomainName('Example.IN', true));
    eq('example.in', inregistry_getDomainName(new \WHMCS\Domains\Domain('EXAMPLE.in')));
    eq('', inregistry_getDomainName([]));
    eq(['error' => 'Empty Domain Name'], inregistry_GetNameservers([]));
});

test('module: every domain function with a fake registry', function () {
    $r = moduleRegistry();
    $p = params();

    eq(['ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net'], inregistry_GetNameservers($p));
    eq(['success' => true], inregistry_SaveNameservers(array_merge($p, ['ns1' => 'NS1.example.net', 'ns2' => 'ns3.example.org', 'ns3' => ''])));
    eq(['ns1.example.net', 'ns3.example.org'], $r->domains['example.in']['ns']);
    eq('locked', inregistry_GetRegistrarLock($p));
    eq(['success' => true], inregistry_SaveRegistrarLock(array_merge($p, ['lockenabled' => 'unlocked'])));
    eq('unlocked', inregistry_GetRegistrarLock($p));

    $contacts = inregistry_GetContactDetails($p);
    eq('Asha Rao', $contacts['Registrant']['Full Name']);
    eq(['success' => true], inregistry_SaveContactDetails(array_merge($p, ['contactdetails' => $contacts])));
    eq(['eppcode' => 'Auth-Code+123'], inregistry_GetEPPCode($p));
    $changed = inregistry_changeEppCode($p);
    eq(true, $changed['success']);
    ok($r->domains['example.in']['authInfo'] !== 'Auth-Code+123');

    eq(['success' => true], inregistry_RenewDomain($p));
    eq('2100-06-30', gmdate('Y-m-d', $r->domains['example.in']['exDate']));
    eq(['active' => true, 'expirydate' => '2100-06-30'], inregistry_Sync($p));

    eq(['success' => true], inregistry_RegisterNameserver(array_merge($p, ['nameserver' => 'ns1.example.in', 'ipaddress' => '192.0.2.1'])));
    eq(['success' => true], inregistry_ModifyNameserver(array_merge($p, ['nameserver' => 'ns1.example.in', 'currentipaddress' => '192.0.2.1', 'newipaddress' => '192.0.2.2'])));
    eq(['success' => true], inregistry_DeleteNameserver(array_merge($p, ['nameserver' => 'ns1.example.in'])));

    eq(['success' => true], inregistry_RequestDelete($p));
    ok(!isset($r->domains['example.in']));
    eq(['success' => true], inregistry_RegisterDomain($p));
    ok(isset($r->domains['example.in']));

    $other = params(['sld' => 'other', 'domainname' => 'other.in', 'transfersecret' => 'Tr$1\\2&<x>']);
    eq(['success' => true], inregistry_TransferDomain($other));
    eq(['error' => 'Empty Transfer Secret Code'], inregistry_TransferDomain(params(['sld' => 'other', 'domainname' => 'other.in'])));
    eq(['completed' => false], inregistry_TransferSync($other));
    \WHMCS\Database\Capsule::$tables['tbldomains'] = [['id' => 555, 'userid' => 7, 'type' => 'Transfer', 'status' => 'Pending Transfer']];
    eq(['message' => 'Successfully cancelled the domain transfer'], inregistry_canceldomaintransfer(array_merge($other, ['domainid' => 555])));
    eq('Cancelled', \WHMCS\Database\Capsule::$tables['tbldomains'][0]['status']);
    eq(['completed' => false, 'failed' => true, 'reason' => 'clientCancelled'], inregistry_TransferSync($other));
    ok(count($r->commandsFor('logout')) >= 20, 'every call logged out');
});

test('module: AdminCustomButtonArray', function () {
    \WHMCS\Database\Capsule::$tables['tbldomains'] = [
        ['id' => 1, 'type' => 'Transfer', 'status' => 'Pending Transfer'],
        ['id' => 2, 'type' => 'Register', 'status' => 'Active'],
    ];
    eq(['Cancel Domain Transfer' => 'canceldomaintransfer', 'Change EPP Code' => 'changeEppCode'], inregistry_AdminCustomButtonArray(['domainid' => 1]));
    eq(['Change EPP Code' => 'changeEppCode'], inregistry_AdminCustomButtonArray(['domainid' => 2]));
    eq(['Change EPP Code' => 'changeEppCode'], inregistry_AdminCustomButtonArray());
    $_REQUEST = ['id' => '1'];
    eq(['Cancel Domain Transfer' => 'canceldomaintransfer', 'Change EPP Code' => 'changeEppCode'], inregistry_AdminCustomButtonArray());
    $_REQUEST = ['domainid' => '2', 'id' => '1'];
    eq(['Change EPP Code' => 'changeEppCode'], inregistry_AdminCustomButtonArray());
    $_REQUEST = [];
});

test('module: GetDomainInformation', function () {
    $r = moduleRegistry();
    $d = inregistry_GetDomainInformation(params(['idprotection' => 1]));
    ok($d instanceof \WHMCS\Domain\Registrar\Domain);
    eq('example.in', $d->values['Domain']);
    eq(['ns1' => 'ns1.example.net', 'ns2' => 'ns2.example.net'], $d->values['Nameservers']);
    eq('Active', $d->values['RegistrationStatus']);
    eq(true, $d->values['TransferLock']);
    eq(true, $d->values['IdProtectionStatus']);
    eq(false, $d->values['DnsManagementStatus']);
    eq(false, $d->values['IsIrtpEnabled']);
    eq(false, $d->values['IrtpTransferLock']);
    eq('2099-06-30', $d->values['ExpiryDate']->toDateString());

    $map = [
        [['status' => ['pendingDelete']], 'Pending Delete Restorable'],
        [['status' => ['ok'], 'rgp' => ['redemptionPeriod']], 'Pending Delete Restorable'],
        [['status' => ['ok'], 'rgp' => ['pendingRestore']], 'Pending Delete Restorable'],
        [['status' => ['serverHold']], 'Suspended'],
        [['status' => ['clientHold']], 'Suspended'],
        [['status' => ['inactive']], 'InActive'],
        [['status' => ['pendingTransfer']], 'Active'],
    ];
    foreach ($map as $case) {
        $r->domains['example.in'] = array_merge($r->domains['example.in'], ['rgp' => []], $case[0]);
        eq($case[1], inregistry_GetDomainInformation(params())->values['RegistrationStatus'], json_encode($case[0]));
    }

    $r->domains['example.in']['rgp'] = ['autoRenewPeriod'];
    $r->domains['example.in']['status'] = ['ok'];
    eq('2098-06-30', inregistry_GetDomainInformation(params())->values['ExpiryDate']->toDateString());

    throws(function () {
        inregistry_GetDomainInformation(params(['sld' => 'missing', 'domainname' => 'missing.in']));
    }, \Exception::class, 'Object does not exist (EPP 2303)');
    throws(function () {
        inregistry_GetDomainInformation([]);
    }, \Exception::class, 'Empty Domain Name');
});

test('module: errors and PHP errors never escape a module function', function () {
    \AllsWeb\InRegistry\ClientFactory::$factory = function () {
        throw new \TypeError('boom');
    };
    foreach (['GetNameservers', 'SaveNameservers', 'GetRegistrarLock', 'SaveRegistrarLock', 'RegisterDomain', 'TransferDomain', 'RenewDomain', 'GetContactDetails', 'SaveContactDetails', 'GetEPPCode', 'RequestDelete', 'Sync', 'TransferSync', 'changeEppCode', 'canceldomaintransfer'] as $fn) {
        $res = call_user_func('inregistry_' . $fn, params(['transfersecret' => 'x', 'contactdetails' => ['Registrant' => []]]));
        ok(is_array($res) && isset($res['error']), $fn);
    }
    foreach (['RegisterNameserver', 'ModifyNameserver', 'DeleteNameserver'] as $fn) {
        $res = call_user_func('inregistry_' . $fn, params(['nameserver' => 'ns1.x.in', 'ipaddress' => '192.0.2.1', 'currentipaddress' => '192.0.2.1', 'newipaddress' => '192.0.2.2']));
        contains('Module error: boom', $res['error'], $fn);
    }
    throws(function () {
        inregistry_GetDomainInformation(params());
    }, \Exception::class, 'boom');
});

test('module: PHP files lint and avoid PHP 8-only syntax', function () {
    $files = array_merge(
        glob(MODULE_DIR . '/*.php'),
        glob(MODULE_DIR . '/lib/*.php'),
        glob(dirname(__DIR__) . '/*.php'),
        glob(dirname(__DIR__) . '/lib/*.php'),
        glob(dirname(__DIR__) . '/stubs/*.php'),
        glob(dirname(__DIR__) . '/cases/*.php')
    );
    foreach ($files as $file) {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        eq(0, $code, basename($file) . ': ' . implode(' ', $out));
    }
    // Local PHP may be newer than the 7.4 minimum: catch the usual 8.x-only constructs.
    $forbidden = [
        '/\bmatch\s*\(/' => 'match', '/\?->/' => 'nullsafe', '/\bstr_(contains|starts_with|ends_with)\s*\(/' => 'str_* 8.0',
        '/\breadonly\b/' => 'readonly', '/^\s*enum\s/m' => 'enum', '/\bmixed\s+\$/' => 'mixed type',
        '/function\s+\w*\s*\([^)]*\b\w+\s*\|\s*\w+\s+\$/' => 'union type', '/,\s*\)\s*(\{|:|use)/' => 'trailing comma in params',
        '/\b(public|private|protected)\s+(?:\?\w+\s+)?\$\w+\s*[,)]/' => 'constructor promotion', '/\w\(\s*\w+:\s*[\'"$\w]/' => 'named argument',
        '/\barray_is_list\s*\(/' => 'array_is_list', '/\bget_debug_type\s*\(/' => 'get_debug_type', '/#\[/' => 'attribute',
        '/\bnever\b\s*\{/' => 'never', '/\bnew\s+\w+\(.*\)\s*->/' => 'new without parentheses',
    ];
    foreach (array_merge(glob(MODULE_DIR . '/*.php'), glob(MODULE_DIR . '/lib/*.php')) as $file) {
        $code = file_get_contents($file);
        $stripped = '';
        foreach (token_get_all($code) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                $stripped .= ($t[0] === T_CONSTANT_ENCAPSED_STRING ? "''" : '') . str_repeat("\n", substr_count($t[1], "\n"));
                continue;
            }
            $stripped .= is_array($t) ? $t[1] : $t;
        }
        foreach ($forbidden as $re => $what) {
            ok(!preg_match($re, $stripped, $m), basename($file) . " uses {$what}: " . ($m[0] ?? ''));
        }
    }
});
