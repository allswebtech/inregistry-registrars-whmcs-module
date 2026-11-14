<?php

namespace AllsWeb\InRegistry\Tests;

const SECDNS = 'urn:ietf:params:xml:ns:secDNS-1.1';
const RGP = 'urn:ietf:params:xml:ns:rgp-1.0';
const IDN = 'urn:ietf:params:xml:ns:idn-1.0';
const LOGINSEC = 'urn:ietf:params:xml:ns:epp:loginSec-1.0';

function loginExtUris(FakeRegistry $r)
{
    $xp = xp(lastCommand($r, 'login'));
    $out = [];
    foreach ($xp->query('//epp:svcExtension/epp:extURI') as $n) {
        $out[] = $n->textContent;
    }
    return [$out, $xp->query('//epp:svcExtension')->length, $xp];
}

test('greeting: no extensions advertised -> no <svcExtension>', function () {
    $r = new FakeRegistry();
    $r->extURIs = [];
    $c = client($r);
    list($uris, $count, $xp) = loginExtUris($r);
    eq([], $uris);
    eq(0, $count);
    eq(3, $xp->query('//epp:svcs/epp:objURI')->length);
    eq(true, $c->getGreeting()['parsed']);
    $c->logout();
});

test('greeting: only known AND advertised extensions are declared', function () {
    $r = new FakeRegistry();
    $r->extURIs = ['urn:ar:params:xml:ns:exAvail-1.0', RGP, 'urn:ietf:params:xml:ns:fee-0.7', SECDNS, 'urn:ietf:params:xml:ns:launch-1.0'];
    $c = client($r);
    list($uris) = loginExtUris($r);
    eq([SECDNS, RGP], $uris);
    eq([SECDNS, RGP], $c->getLoginExtensions());
    $c->logout();

    $r = new FakeRegistry();
    $r->extURIs = [IDN, LOGINSEC, SECDNS, RGP];
    $c = client($r);
    list($uris) = loginExtUris($r);
    eq([SECDNS, RGP, IDN, LOGINSEC], $uris);
    $c->logout();

    $r = new FakeRegistry();
    $r->extURIs = ['urn:ar:params:xml:ns:exAvail-1.0', 'urn:ar:params:xml:ns:price-1.2'];
    $c = client($r);
    list($uris, $count) = loginExtUris($r);
    eq([], $uris);
    eq(0, $count, 'only unknown extensions: no svcExtension');
    $c->logout();
});

test('greeting: unparsable or prefixed greeting', function () {
    $r = new FakeRegistry();
    $r->greetingOverride = '<epp><greeting>broken';
    $c = client($r);
    eq(false, $c->getGreeting()['parsed']);
    list($uris, $count) = loginExtUris($r);
    eq(0, $count, 'unparsed greeting: declare no extensions');
    $c->logout();

    $r = new FakeRegistry();
    $r->greetingOverride = '<?xml version="1.0"?><e:epp xmlns:e="urn:ietf:params:xml:ns:epp-1.0"><e:greeting><e:svID>x</e:svID>'
        . '<e:svDate>2026-11-14T10:00:00.0Z</e:svDate><e:svcMenu><e:version>1.0</e:version><e:lang>en</e:lang>'
        . '<e:objURI>urn:ietf:params:xml:ns:domain-1.0</e:objURI><e:svcExtension><e:extURI>' . RGP . '</e:extURI></e:svcExtension>'
        . '</e:svcMenu><e:dcp><e:access><e:all/></e:access><e:statement><e:purpose><e:prov/></e:purpose><e:recipient><e:ours/></e:recipient><e:retention><e:stated/></e:retention></e:statement></e:dcp></e:greeting></e:epp>';
    $c = client($r);
    eq([RGP], $c->getLoginExtensions());
    $c->logout();
});

test('loginSec: 16-character password uses classic <pw>', function () {
    $r = new FakeRegistry();
    $r->extURIs = [LOGINSEC];
    $c = new ScriptedEppClient(\AllsWeb\InRegistry\ok_epp_buildConfig(params()), $r);
    $c->login('REGISTRAR1', str_repeat('a', 15) . '$');
    eq(str_repeat('a', 15) . '$', $r->loginPw);
    eq(null, $r->loginSecPw);
    eq(0, xp(lastCommand($r, 'login'))->query('//epp:extension')->length);
    $c->logout();
});

test('loginSec: 17-character password goes in the extension', function () {
    $r = new FakeRegistry();
    $r->extURIs = [SECDNS, LOGINSEC];
    $pw = 'Long$1\\2&<x>pass!';
    eq(17, strlen($pw));
    $c = new ScriptedEppClient(\AllsWeb\InRegistry\ok_epp_buildConfig(params()), $r);
    $c->login('REGISTRAR1', $pw);
    eq('[LOGIN-SECURITY]', $r->loginPw);
    eq($pw, $r->loginSecPw);
    $xp = xp(lastCommand($r, 'login'));
    $app = $xp->query('//loginSec:userAgent/loginSec:app')->item(0)->textContent;
    contains(\AllsWeb\InRegistry\VERSION, $app);
    contains('PHP ' . PHP_VERSION, $xp->query('//loginSec:userAgent/loginSec:tech')->item(0)->textContent);
    $c->logout();
});

test('loginSec: long new password, and long password without loginSec is refused locally', function () {
    $r = new FakeRegistry();
    $r->extURIs = [LOGINSEC];
    $c = new ScriptedEppClient(\AllsWeb\InRegistry\ok_epp_buildConfig(params()), $r);
    $c->changePassword('REGISTRAR1', 'short-pw1', 'a-very-long-new-password');
    eq('short-pw1', $r->loginPw);
    eq('[LOGIN-SECURITY]', $r->loginNewPw);
    $xp = xp(lastCommand($r, 'login'));
    eq('a-very-long-new-password', $xp->query('//loginSec:newPW')->item(0)->textContent);
    eq(0, $xp->query('//loginSec:pw')->length);
    $c->logout();

    // No loginSec offered: the classic <pw> is sent whatever its length and the
    // registry decides. A 17-character <pw> breaks the RFC 5730 XSD (maxLength 16),
    // so this one login is exempt from schema validation on purpose.
    $r = new FakeRegistry();
    $r->extURIs = [SECDNS];
    $r->schemaExempt = ['login'];
    $c = new ScriptedEppClient(\AllsWeb\InRegistry\ok_epp_buildConfig(params()), $r);
    $c->changePassword('REGISTRAR1', str_repeat('p', 17), str_repeat('n', 20));
    eq(str_repeat('p', 17), $r->loginPw);
    eq(str_repeat('n', 20), $r->loginNewPw);
    eq(0, xp(lastCommand($r, 'login'))->query('//epp:extension')->length);
    $c->logout();
});
