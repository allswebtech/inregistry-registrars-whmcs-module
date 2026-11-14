<?php

namespace AllsWeb\InRegistry\Tests;

use AllsWeb\InRegistry as IR;

test('phone: normaliser table', function () {
    $cases = [
        // [input, calling code, country, expected]
        ['+91.9876543210', null, null, '+91.9876543210'],
        ['+1.4155550100', '44', 'GB', '+1.4155550100'],
        ['+91 98765 43210', null, null, '+91.9876543210'],
        ['+91-98765-43210', null, null, '+91.9876543210'],
        ['+91.98765 43210', null, null, '+91.9876543210'],
        ['+919876543210', null, null, '+91.9876543210'],
        ['+919876543210', '91', 'IN', '+91.9876543210'],
        ['+14155550100', null, null, '+1.4155550100'],
        ['+1 (415) 555-0100', null, null, '+1.4155550100'],
        ['+442079460958', null, null, '+44.2079460958'],
        ['+44 (0)20 7946 0958', null, null, '+44.2079460958'],
        ['+971501234567', null, null, '+971.501234567'],
        ['+6591234567', null, null, '+65.91234567'],
        ['+35312345678', null, null, '+353.12345678'],
        ['+8801712345678', null, null, '+880.1712345678'],
        ['0091 98765 43210', null, null, '+91.9876543210'],
        ['91.9876543210', null, null, '+91.9876543210'],
        ['44.2079460958', null, null, '+44.2079460958'],
        ['9876543210', '91', null, '+91.9876543210'],
        ['9876543210', '+91', 'US', '+91.9876543210'],
        ['09876543210', null, 'IN', '+91.9876543210'],
        ['98765 43210', null, 'in', '+91.9876543210'],
        ['(415) 555-0100', null, 'US', '+1.4155550100'],
        ['415.555.0100', null, 'US', '+1.4155550100'],
        ['416-555-0100', null, 'CA', '+1.4165550100'],
        ['020 7946 0958', null, 'GB', '+44.2079460958'],
        ['050 123 4567', null, 'AE', '+971.501234567'],
        ['0412 345 678', null, 'AU', '+61.412345678'],
        ['9123 4567', null, 'SG', '+65.91234567'],
        ['030 123456', null, 'DE', '+49.30123456'],
        ['01 23 45 67 89', null, 'FR', '+33.123456789'],
        ['020 123 4567', null, 'NL', '+31.201234567'],
        ['06 1234 5678', '39', null, '+39.0612345678'],
        ['9876543210', null, null, ''],
        ['9876543210', null, 'ZZ', ''],
        ['', '91', 'IN', ''],
        ['call me', '91', 'IN', ''],
        ['+91 98765 43210 ext 5', null, null, ''],
        ['+0 123456', null, null, ''],
        ['+999 1234567', null, null, ''],
        ['+91.12', null, null, '+91.12'], // already schema-valid: kept
        ['+91 12', null, null, ''],
        ['+91.123456789012345', null, null, ''],
        ['12', '91', 'IN', ''],
    ];
    foreach ($cases as $case) {
        list($in, $cc, $country, $expected) = $case;
        eq($expected, IR\ok_epp_normalisePhone($in, $cc, $country), "normalise(" . var_export($in, true) . ", {$cc}, {$country})");
        if ($expected !== '') {
            ok(preg_match('/^\+[0-9]{1,3}\.[0-9]{1,14}$/', $expected) === 1);
        }
    }
});

test('phone: calling-code table is prefix-free and complete for the country map', function () {
    $codes = array_keys(IR\ok_epp_callingCodes());
    ok(count($codes) > 200, 'all assigned codes listed');
    foreach ($codes as $a) {
        ok(preg_match('/^[1-9][0-9]{0,2}$/', (string) $a) === 1, "code {$a}");
        foreach ($codes as $b) {
            if ($a !== $b) {
                ok(strpos((string) $b, (string) $a) !== 0, "{$a} is a prefix of {$b}");
            }
        }
    }
    foreach (['IN' => '91', 'US' => '1', 'CA' => '1', 'GB' => '44', 'AE' => '971', 'AU' => '61', 'SG' => '65', 'DE' => '49', 'FR' => '33', 'NL' => '31'] as $country => $code) {
        eq($code, IR\ok_epp_countryCallingCode($country));
        ok(isset(IR\ok_epp_callingCodes()[$code]));
    }
    eq('', IR\ok_epp_countryCallingCode('XX'));
});

test('contact id: 3-16 chars, [A-Z0-9_], for domain ids of 1-12 digits and prefixes of 0-4', function () {
    foreach (['', 'A', 'ab', 'abc', 'ABCD', 'toolong', 'a-b!'] as $prefix) {
        for ($digits = 1; $digits <= 12; $digits++) {
            $domainId = str_repeat('7', $digits - 1) . '3';
            $id = IR\ok_epp_buildContactId($prefix, $domainId);
            ok(strlen($id) >= 3 && strlen($id) <= 16, "length of {$id}");
            ok(preg_match('/^[A-Z0-9_]+$/', $id) === 1, "charset of {$id}");
            $clean = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $prefix), 0, 4));
            if ($clean !== '') {
                eq(0, strpos($id, $clean . '_'), "prefix of {$id}");
            }
            ok(substr($id, -1) === '3', 'ends with the last domain id digit');
        }
    }
    eq(4, strlen(IR\ok_epp_buildContactId('', '')));
});

test('auth codes: 16 chars with upper, lower, digit and one of !=+-', function () {
    $seen = [];
    for ($i = 0; $i < 300; $i++) {
        $pw = IR\ok_epp_generateObjectPW();
        eq(16, strlen($pw));
        ok(preg_match('/[A-Z]/', $pw) && preg_match('/[a-z]/', $pw) && preg_match('/[0-9]/', $pw) && preg_match('/[!=+\-]/', $pw), $pw);
        ok(preg_match('/^[A-Za-z0-9!=+\-]{16}$/', $pw) === 1);
        $seen[$pw] = true;
    }
    eq(300, count($seen));
    ok(preg_match('/^[A-Z0-9]{12}$/', IR\ok_epp_generateRandomString()) === 1);
});

test('dates: paid-through date', function () {
    eq('2027-01-10', IR\ok_epp_expiryDate('2027-01-10T10:00:00.0Z'));
    eq('2026-01-10', IR\ok_epp_expiryDate('2027-01-10T10:00:00.0Z', true));
    eq('2027-02-28', IR\ok_epp_expiryDate('2028-02-29T00:00:00Z', true));
    eq('', IR\ok_epp_expiryDate(''));
    eq('', IR\ok_epp_expiryDate('0000-00-00'));
});

test('auto-renew: accepted once WHMCS expiry >= E - 183 days, only in autoRenewPeriod', function () {
    $ar = ['autoRenewPeriod'];
    $e = '2027-11-01T10:00:00.0Z';
    eq(false, IR\ok_epp_autoRenewUnpaid($e, [], ''), 'no grace status: unchanged');
    eq(false, IR\ok_epp_autoRenewUnpaid($e, ['redemptionPeriod'], ''));
    eq(true, IR\ok_epp_autoRenewUnpaid($e, $ar, ''), 'W unknown');
    eq(true, IR\ok_epp_autoRenewUnpaid($e, $ar, '0000-00-00'), 'W zero date');
    eq(true, IR\ok_epp_autoRenewUnpaid($e, $ar, 'garbage'), 'W unparsable');
    eq(true, IR\ok_epp_autoRenewUnpaid($e, $ar, '2026-11-01'), 'W = E - 1y: unpaid');
    eq(true, IR\ok_epp_autoRenewUnpaid($e, $ar, '2027-04-30'), 'W = E - 185d');
    eq(false, IR\ok_epp_autoRenewUnpaid($e, $ar, '2027-05-02'), 'W = E - 183d');
    eq(false, IR\ok_epp_autoRenewUnpaid($e, $ar, '2027-11-01'), 'W = E');
    eq(false, IR\ok_epp_autoRenewUnpaid($e, $ar, '2028-11-01 00:00:00'), 'W > E');
    eq(true, IR\ok_epp_autoRenewUnpaid('', $ar, '2027-11-01'), 'E unknown');
});
