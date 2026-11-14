<?php

namespace AllsWeb\InRegistry;

// ----------------------------------------------------------------------
// Small utilities
// ----------------------------------------------------------------------

function ok_epp_line2arr($str, $limit = PHP_INT_MAX)
{
    return ok_epp_arrTrim(explode("\n", trim((string) $str), $limit));
}

function ok_epp_arrTrim(array $arr)
{
    return array_map('trim', $arr);
}

function ok_epp_arr2arr(array $arr)
{
    return isset($arr[0]) ? $arr : [$arr];
}

/**
 * Undoes exactly the encoding WHMCS input sanitising produces (htmlspecialchars
 * with quotes), once, not recursively. Other entities such as &copy; are text.
 */
function ok_epp_decodeWhmcs($value)
{
    return strtr((string) $value, [
        '&amp;' => '&', '&lt;' => '<', '&gt;' => '>', '&quot;' => '"',
        '&#039;' => "'", '&#39;' => "'", '&apos;' => "'",
    ]);
}

/**
 * XML-escapes one value for element text or a quoted attribute. Idempotent:
 * the WHMCS encoding is undone first, so a value WHMCS already entity-encoded
 * is not encoded twice. Characters that XML 1.0 forbids are dropped.
 */
function ok_epp_xmlEscape($value)
{
    $value = ok_epp_decodeWhmcs($value);
    $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    return $clean === null ? $value : $clean;
}

/** Kept for compatibility; same as ok_epp_xmlEscape(). */
function ok_epp_htmlspecialchars($str)
{
    return ok_epp_xmlEscape($str);
}

/** EPP boolean attribute ("1", "0", "true", "false"). */
function ok_epp_bool($value)
{
    $value = strtolower(trim((string) $value));
    return $value === '1' || $value === 'true';
}

/** Length in characters (UTF-8), without requiring mbstring. */
function ok_epp_strlen($value)
{
    $value = (string) $value;
    $count = preg_match_all('/./us', $value);
    return $count === false ? strlen($value) : $count;
}

function ok_epp_simple_ArrStrTag($data, $inTag = null, $func = false)
{
    $return = '';
    foreach ((array) $data as $v) {
        $v = trim((string) $v);
        if ($v === '') {
            continue;
        }
        $fv = ok_epp_xmlEscape($func === false ? $v : call_user_func($func, $v));
        $return .= $inTag === null ? $fv : '<' . $inTag . '>' . $fv . '</' . $inTag . '>';
    }
    return $return;
}

/** <tag ip="v4|v6">address</tag> for every valid IP address. */
function ok_epp_simple_IpsArrStrTag($data, $inTag)
{
    $ip = '';
    foreach ((array) $data as $v) {
        $v = trim((string) $v);
        if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip .= '<' . $inTag . ' ip="v4">' . $v . '</' . $inTag . '>';
        } elseif (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ip .= '<' . $inTag . ' ip="v6">' . $v . '</' . $inTag . '>';
        }
    }
    return $ip;
}

// ----------------------------------------------------------------------
// Random values
// ----------------------------------------------------------------------

/** 16-character auth code with at least one upper, lower, digit and one of !=+- */
function ok_epp_generateObjectPW($objType = 'none')
{
    $sets = ['ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz', '1234567890', '!=+-'];
    $all = implode('', $sets);
    $chars = [];
    foreach ($sets as $set) {
        $chars[] = $set[random_int(0, strlen($set) - 1)];
    }
    while (count($chars) < 16) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $chars[$i];
        $chars[$i] = $chars[$j];
        $chars[$j] = $tmp;
    }
    return implode('', $chars);
}

function ok_epp_generateRandomString($length = 12)
{
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $randomString;
}

/**
 * Builds a contact ID (3-16 chars, [A-Z0-9_]) as PREFIX_ + 4 random +
 * as much of the domain id as fits (its last digits), and checks it is free.
 */
function ok_epp_buildContactId($identityprefix, $dId)
{
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $identityprefix), 0, 4));
    $base = ($prefix !== '' ? $prefix . '_' : '') . ok_epp_generateRandomString(4);
    $tail = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $dId));
    $room = 16 - strlen($base);
    if ($room > 0 && $tail !== '') {
        $base .= substr($tail, -$room);
    }
    return $base;
}

function ok_epp_generateUniqueContactId($eppCon, $identityprefix, $dId, $attempts = 5)
{
    for ($i = 0; $i < $attempts; $i++) {
        $id = ok_epp_buildContactId($identityprefix, $dId);
        $check = $eppCon->contactCheck($id);
        if (!empty($check['contact'][0]['avail'])) {
            return $id;
        }
    }
    throw new EppException("Could not find a free contact ID after {$attempts} attempts");
}

// ----------------------------------------------------------------------
// Phone numbers (EPP e164: +CC.NUMBER)
// ----------------------------------------------------------------------

/** Assigned ITU-T E.164 country calling codes (a prefix-free set). */
function ok_epp_callingCodes()
{
    static $codes = null;
    if ($codes === null) {
        $list = '1 7 20 27 30 31 32 33 34 36 39 40 41 43 44 45 46 47 48 49 51 52 53 54 55 56 57 58 '
            . '60 61 62 63 64 65 66 81 82 84 86 90 91 92 93 94 95 98 '
            . '211 212 213 216 218 220 221 222 223 224 225 226 227 228 229 230 231 232 233 234 235 236 237 238 239 '
            . '240 241 242 243 244 245 246 247 248 249 250 251 252 253 254 255 256 257 258 260 261 262 263 264 265 '
            . '266 267 268 269 290 291 297 298 299 '
            . '350 351 352 353 354 355 356 357 358 359 370 371 372 373 374 375 376 377 378 379 380 381 382 383 385 '
            . '386 387 389 420 421 423 '
            . '500 501 502 503 504 505 506 507 508 509 590 591 592 593 594 595 596 597 598 599 '
            . '670 672 673 674 675 676 677 678 679 680 681 682 683 685 686 687 688 689 690 691 692 '
            . '800 808 850 852 853 855 856 870 878 880 881 882 883 886 888 '
            . '960 961 962 963 964 965 966 967 968 970 971 972 973 974 975 976 977 979 992 993 994 995 996 998';
        $codes = array_fill_keys(explode(' ', $list), true);
    }
    return $codes;
}

/** Calling code for a 2-letter country code, or '' when unknown. */
function ok_epp_countryCallingCode($country)
{
    $map = [
        'IN' => '91', 'US' => '1', 'CA' => '1', 'GB' => '44', 'AE' => '971', 'AU' => '61', 'SG' => '65',
        'DE' => '49', 'FR' => '33', 'NL' => '31', 'IE' => '353', 'IT' => '39', 'ES' => '34', 'PT' => '351',
        'BE' => '32', 'CH' => '41', 'AT' => '43', 'SE' => '46', 'NO' => '47', 'DK' => '45', 'FI' => '358',
        'PL' => '48', 'CZ' => '420', 'GR' => '30', 'RO' => '40', 'HU' => '36', 'UA' => '380', 'RU' => '7',
        'TR' => '90', 'IL' => '972', 'NZ' => '64', 'JP' => '81', 'KR' => '82', 'CN' => '86', 'HK' => '852',
        'TW' => '886', 'MY' => '60', 'TH' => '66', 'ID' => '62', 'PH' => '63', 'VN' => '84', 'PK' => '92',
        'BD' => '880', 'LK' => '94', 'NP' => '977', 'BT' => '975', 'MV' => '960', 'SA' => '966', 'QA' => '974',
        'KW' => '965', 'OM' => '968', 'BH' => '973', 'ZA' => '27', 'NG' => '234', 'KE' => '254', 'EG' => '20',
        'BR' => '55', 'MX' => '52', 'AR' => '54',
    ];
    $country = strtoupper(trim((string) $country));
    return isset($map[$country]) ? $map[$country] : '';
}

/**
 * Normalises a phone number to EPP form "+CC.NUMBER" (pattern
 * \+[0-9]{1,3}\.[0-9]{1,14}), or returns '' when it cannot be done safely.
 * $countryCallingCode: e.g. WHMCS "phonecc"; $country: 2-letter country code.
 */
function ok_epp_normalisePhone($number, $countryCallingCode = null, $country = null)
{
    $raw = trim((string) $number);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^\+[0-9]{1,3}\.[0-9]{1,14}$/', $raw)) {
        return $raw;
    }

    $codes = ok_epp_callingCodes();
    $build = function ($code, $national) use ($codes) {
        $national = preg_replace('/\D/', '', (string) $national);
        if (!isset($codes[$code]) || strlen($national) < 4 || strlen($national) > 14) {
            return '';
        }
        return '+' . $code . '.' . $national;
    };

    $raw = preg_replace('/\(\s*0\s*\)/', ' ', $raw); // "+44 (0)20 ..." trunk marker
    if (preg_match('/^00\s*[1-9]/', $raw)) {
        $raw = '+' . ltrim(substr($raw, 2));
    }
    if (preg_match('/[^0-9+\s.\-()\/]/', $raw)) {
        return ''; // letters, extensions etc.: do not guess
    }

    $cc = preg_replace('/\D/', '', (string) $countryCallingCode);
    if (!isset($codes[$cc])) {
        $cc = '';
    }
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === '') {
        return '';
    }

    if ($raw[0] === '+') {
        // "+CC NNN", "+CC-NNN", "+CC.NNN NN": an explicit separator after the code
        if (preg_match('/^\+\s*(\d{1,3})[\s.\-\/]+(.+)$/', $raw, $m) && isset($codes[$m[1]])) {
            return $build($m[1], $m[2]);
        }
        if ($cc !== '' && strpos($digits, $cc) === 0) {
            return $build($cc, substr($digits, strlen($cc)));
        }
        for ($len = 1; $len <= 3; $len++) {
            $code = substr($digits, 0, $len);
            if (isset($codes[$code])) {
                return $build($code, substr($digits, $len));
            }
        }
        return '';
    }

    // "CC.NNN" (one dot, digits only)
    if (preg_match('/^(\d{1,3})\.(\d+)$/', $raw, $m) && isset($codes[$m[1]])) {
        return $build($m[1], $m[2]);
    }

    // A national number: needs the calling code from the caller or the country.
    $code = $cc !== '' ? $cc : ok_epp_countryCallingCode($country);
    if ($code === '') {
        return '';
    }
    if ($code !== '39' && strlen($digits) > 1 && $digits[0] === '0') {
        $digits = substr($digits, 1); // national trunk prefix
    }
    return $build($code, $digits);
}

// ----------------------------------------------------------------------
// Dates
// ----------------------------------------------------------------------

/** 'Y-m-d' when $value starts with a real calendar date, else ''. */
function ok_epp_datePart($value)
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string) $value), $m)) {
        return '';
    }
    if ((int) $m[1] < 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return ''; // includes 0000-00-00
    }
    return $m[1] . '-' . $m[2] . '-' . $m[3];
}

/**
 * The expiry date WHMCS has stored for the domain (tbldomains.expirydate),
 * 'Y-m-d' or '' when unknown. Tests replace the lookup through $resolver.
 */
final class WhmcsExpiry
{
    /** @var callable|null fn(array $params): string */
    public static $resolver = null;

    public static function get(array $params)
    {
        try {
            if (self::$resolver !== null) {
                return ok_epp_datePart(call_user_func(self::$resolver, $params));
            }
            if (empty($params['domainid']) || !class_exists('\WHMCS\Database\Capsule')) {
                return '';
            }
            $value = \WHMCS\Database\Capsule::table('tbldomains')->where('id', (int) $params['domainid'])->value('expirydate');
            return ok_epp_datePart($value);
        } catch (\Throwable $e) {
            return '';
        }
    }
}

/**
 * The registry auto-renews on the expiry date (+1 year) and keeps the domain
 * in the RFC 3915 autoRenewPeriod grace for 45 days; that year is charged
 * only later. It counts as paid ("accepted") once WHMCS's own expiry date W
 * has caught up with it: W known and W >= E - 183 days (E = registry exDate).
 *
 * Returns true when the domain is in autoRenewPeriod and that year is NOT
 * accepted yet (W unknown or older): then the paid-through date is E - 1 year
 * and the first renewed year needs no registry command.
 */
function ok_epp_autoRenewUnpaid($exDate, array $rgpStatus, $whmcsExpiry)
{
    if (!in_array('autoRenewPeriod', $rgpStatus, true)) {
        return false;
    }
    $e = ok_epp_datePart($exDate);
    $w = ok_epp_datePart($whmcsExpiry);
    if ($e === '' || $w === '') {
        return true;
    }
    $threshold = new \DateTime($e . ' 00:00:00', new \DateTimeZone('UTC'));
    $threshold->modify('-183 days');
    return $w < $threshold->format('Y-m-d');
}

/**
 * Paid-through date (Y-m-d) from the registry exDate; one year less while an
 * auto-renewed year is unpaid (see ok_epp_autoRenewUnpaid()).
 */
function ok_epp_expiryDate($exDate, $unpaidAutoRenew = false)
{
    $date = ok_epp_datePart($exDate);
    if ($date === '' || !$unpaidAutoRenew) {
        return $date;
    }
    list($year, $month, $day) = array_map('intval', explode('-', $date));
    if ($month === 2 && $day === 29) {
        $day = 28;
    }
    return sprintf('%04d-%02d-%02d', $year - 1, $month, $day);
}

/** Paid-through date for a domainInfo result, using WHMCS's stored expiry. */
function ok_epp_paidThrough(array $params, array $info)
{
    return ok_epp_expiryDate($info['exDate'], ok_epp_autoRenewUnpaid($info['exDate'], $info['rgpStatus'], WhmcsExpiry::get($params)));
}

// ----------------------------------------------------------------------
// Client lifecycle
// ----------------------------------------------------------------------

final class ClientFactory
{
    /** @var callable|null fn(array $params): EppClient (logged in). Tests set this. */
    public static $factory = null;

    public static function create(array $params)
    {
        if (self::$factory !== null) {
            return call_user_func(self::$factory, $params);
        }
        $client = new EppClient(ok_epp_buildConfig($params));
        try {
            $client->login((string) ($params['clid'] ?? ''), (string) ($params['pw'] ?? ''));
        } catch (\Exception $e) {
            $client->disconnect();
            throw $e;
        }
        return $client;
    }
}

function ok_epp_errorMessage($e)
{
    if ($e instanceof EppException) {
        return $e->getMessage();
    }
    return 'Module error: ' . $e->getMessage();
}

/** Logged-in client, or (object) ['error' => message]. */
function ok_epp_startEppClient(array $config)
{
    try {
        return ClientFactory::create($config);
    } catch (\Throwable $e) {
        return (object) ['error' => ok_epp_errorMessage($e)];
    }
}

/**
 * Opens a session, runs $fn($client), always logs out, and maps any
 * exception to ['error' => message].
 */
function ok_epp_withClient(array $config, callable $fn)
{
    try {
        $con = ClientFactory::create($config);
    } catch (\Throwable $e) {
        return ['error' => ok_epp_errorMessage($e)];
    }

    try {
        $return = $fn($con);
    } catch (\Throwable $e) {
        if (!$e instanceof EppException) {
            ok_epp_modulelog(get_class($e), $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), 'internal-error');
        }
        $return = ['error' => ok_epp_errorMessage($e)];
    }

    try {
        $con->logout();
    } catch (\Throwable $e) {
        // logout() never throws; a replaced client might.
    }
    return $return;
}

function ok_epp_lowerHost($name)
{
    return rtrim(strtolower(trim((string) $name)), '.');
}

/** True when $host is $domainName itself or below it (needs glue IPs). */
function ok_epp_isSubordinateHost($host, $domainName)
{
    $host = ok_epp_lowerHost($host);
    $domain = ok_epp_lowerHost($domainName);
    return $domain !== '' && ($host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain);
}

/**
 * Refuses nameservers under $domainName that the registry does not know:
 * they cannot be created without an IP address.
 */
function ok_epp_checkSubordinateHosts($con, array $nss, $domainName)
{
    $subordinate = array_values(array_filter($nss, function ($ns) use ($domainName) {
        return ok_epp_isSubordinateHost($ns, $domainName);
    }));
    if (!$subordinate) {
        return;
    }
    foreach ($con->hostCheck($subordinate)['host'] as $host) {
        if (!empty($host['avail'])) {
            $name = ok_epp_lowerHost($host['name']);
            $domain = ok_epp_lowerHost($domainName);
            throw new EppException("Nameserver {$name} is under {$domain}: register it as a private nameserver with its IP address first.");
        }
    }
}

/** Creates host objects for nameservers the registry does not know yet. */
function ok_epp_ensureHosts($con, array $nss, $domainName = '')
{
    if (!$nss) {
        return;
    }
    $check = $con->hostCheck(array_values($nss));
    foreach ($check['host'] as $host) {
        if (empty($host['avail'])) {
            continue;
        }
        if (ok_epp_isSubordinateHost($host['name'], $domainName)) {
            $name = ok_epp_lowerHost($host['name']);
            $domain = ok_epp_lowerHost($domainName);
            throw new EppException("Nameserver {$name} is under {$domain}: register it as a private nameserver with its IP address first.");
        }
        $con->hostCreate($host['name']);
    }
}

// ----------------------------------------------------------------------
// WHMCS flows
// ----------------------------------------------------------------------

function ok_epp_GetNameservers(array $config, $domainName)
{
    if (empty($domainName)) {
        return ['error' => 'Empty Domain Name'];
    }
    return ok_epp_withClient($config, function ($con) use ($domainName) {
        return $con->domainInfo($domainName)['domain']['ns'];
    });
}

function ok_epp_SaveNameservers(array $config, $domainName, array $saveNSD)
{
    if (empty($domainName)) {
        return ['error' => 'Empty Domain Name'];
    }
    $wanted = [];
    foreach ($saveNSD as $ns) {
        $ns = ok_epp_lowerHost($ns);
        if ($ns !== '') {
            $wanted[$ns] = $ns;
        }
    }
    if (!$wanted) {
        return ['error' => 'Empty Save Nameservers'];
    }

    return ok_epp_withClient($config, function ($con) use ($domainName, $wanted) {
        $current = [];
        foreach ($con->domainInfo($domainName)['domain']['ns'] as $ns) {
            $current[ok_epp_lowerHost($ns)] = $ns;
        }
        $add = array_values(array_diff_key($wanted, $current));
        $rem = array_values(array_diff_key($current, $wanted));
        if (!$add && !$rem) {
            return ['success' => true];
        }
        ok_epp_ensureHosts($con, $add, $domainName);
        $con->domainUpdateNS($domainName, $add, $rem);
        return ['success' => true];
    });
}

function ok_epp_GetRegistrarLock(array $config, $domainName)
{
    if (empty($domainName)) {
        return ['error' => 'Empty Domain Name'];
    }
    return ok_epp_withClient($config, function ($con) use ($domainName) {
        $status = $con->domainInfo($domainName)['domain']['status'];
        return in_array('clientTransferProhibited', $status, true) ? 'locked' : 'unlocked';
    });
}

function ok_epp_SaveRegistrarLock(array $config, $domainName, $lockenabled)
{
    if (empty($domainName)) {
        return ['error' => 'Empty Domain Name'];
    }
    return ok_epp_withClient($config, function ($con) use ($domainName, $lockenabled) {
        $locked = in_array('clientTransferProhibited', $con->domainInfo($domainName)['domain']['status'], true);
        $wantLocked = $lockenabled === 'locked';
        if ($locked !== $wantLocked) {
            $con->domainUpdateStatus($domainName, ['clientTransferProhibited'], $wantLocked ? 'add' : 'rem');
        }
        return ['success' => true];
    });
}

function ok_epp_RegisterDomain(array $config, $domainName, array $nss)
{
    return ok_epp_withClient($config, function ($con) use ($config, $domainName, $nss) {
        $check = $con->domainCheck($domainName);
        $first = isset($check['domain'][0]) ? $check['domain'][0] : null;
        if ($first === null || empty($first['avail'])) {
            $reason = $first !== null ? trim($first['reason']) : '';
            throw new EppException('Domain is not available' . ($reason !== '' ? ': ' . $reason : ''));
        }

        // Before anything is created: in-zone nameservers must already exist.
        ok_epp_checkSubordinateHosts($con, $nss, $domainName);

        $contactId = ok_epp_generateUniqueContactId($con, $config['identityprefix'] ?? '', $config['domainid'] ?? '');
        $con->contactCreate($contactId, $config);
        $cids = ['registrant' => $contactId, 'admin' => $contactId, 'tech' => $contactId, 'billing' => $contactId];

        try {
            ok_epp_ensureHosts($con, $nss, $domainName);
            $con->domainCreate($domainName, $config['regperiod'] ?? 1, $cids, $nss);
        } catch (\Throwable $e) {
            // Nothing references the new contact yet: remove it (best effort).
            try {
                $con->contactDelete($contactId);
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }

        // The domain exists and is billed: from here on, report success.
        try {
            $con->domainUpdateStatus($domainName, ['clientTransferProhibited'], 'add');
        } catch (\Throwable $e) {
            ok_epp_modulelog($domainName, 'Registered, but setting clientTransferProhibited failed: ' . $e->getMessage(), 'RegisterDomain-lock');
        }
        return ['success' => true];
    });
}

function ok_epp_TransferDomain(array $config, $domainName, $transferSecret)
{
    return ok_epp_withClient($config, function ($con) use ($domainName, $transferSecret) {
        $con->domainTransfer($domainName, 'request', $transferSecret);
        return ['success' => true];
    });
}

function ok_epp_RenewDomain(array $config, $domainName)
{
    return ok_epp_withClient($config, function ($con) use ($config, $domainName) {
        $period = isset($config['regperiod']) && is_numeric($config['regperiod']) ? (int) $config['regperiod'] : 1;
        $info = $con->domainInfo($domainName)['domain'];

        // An auto-renewed year not yet accepted counts as the first year paid now.
        if (ok_epp_autoRenewUnpaid($info['exDate'], $info['rgpStatus'], WhmcsExpiry::get($config))) {
            if ($period <= 1) {
                ok_epp_modulelog($domainName, 'Registry auto-renewal (autoRenewPeriod) accepted as this renewal; no renew command sent.', 'RenewDomain-autoRenew');
                return ['success' => true];
            }
            $period--;
        }
        $con->domainRenew($domainName, $period, $info['exDate']);
        return ['success' => true];
    });
}

function ok_epp_GetContactDetails(array $config, $domainName)
{
    return ok_epp_withClient($config, function ($con) use ($domainName) {
        return $con->domainContactInfo($domainName)['contents'];
    });
}

/** WHMCS contact form fields → contactCreate/contactUpdate params. */
function ok_epp_contactParamsFromForm(array $form)
{
    $get = function ($key) use ($form) {
        return isset($form[$key]) && is_scalar($form[$key]) ? trim((string) $form[$key]) : '';
    };
    return [
        'firstname' => $get('Full Name') !== '' ? $get('Full Name') : trim($get('First Name') . ' ' . $get('Last Name')),
        'email' => $get('Email') !== '' ? $get('Email') : $get('Email Address'),
        'companyname' => $get('Company Name') !== '' ? $get('Company Name') : $get('Organisation Name'),
        'address1' => $get('Address 1'),
        'address2' => $get('Address 2'),
        'address3' => $get('Address 3'),
        'city' => $get('City'),
        'state' => $get('State'),
        'postcode' => $get('Postcode') !== '' ? $get('Postcode') : $get('ZIP Code'),
        'country' => strtoupper($get('Country')),
        'fullphonenumber' => $get('Phone Number'),
        'phonecc' => $get('Phone Country Code'),
    ];
}

/** Comparable form of contact params (normalised, entity-decoded, phone in EPP form). */
function ok_epp_contactCanonical(array $p)
{
    $out = [];
    foreach (['firstname', 'email', 'companyname', 'address1', 'address2', 'address3', 'city', 'state', 'postcode', 'country'] as $key) {
        $value = ok_epp_decodeWhmcs($p[$key] ?? '');
        $out[$key] = trim(preg_replace('/\s+/u', ' ', $value));
    }
    $out['email'] = strtolower($out['email']);
    $out['country'] = strtoupper($out['country']);
    $phone = (string) ($p['fullphonenumber'] ?? '');
    $normalised = ok_epp_normalisePhone($phone, $p['phonecc'] ?? null, $out['country']);
    $out['phone'] = $normalised !== '' ? $normalised : $phone;
    return $out;
}

function ok_epp_SaveContactDetails(array $config, $domainName)
{
    if (empty($config['contactdetails']) || !is_array($config['contactdetails'])) {
        return ['error' => 'contact details Empty'];
    }

    return ok_epp_withClient($config, function ($con) use ($config, $domainName) {
        $current = $con->domainContactInfo($domainName);
        $assigned = $current['cId'];

        foreach ($current['cId'] as $role => $contactId) {
            $form = $config['contactdetails'][ucfirst($role)] ?? null;
            if (!is_array($form)) {
                continue; // role not posted by WHMCS
            }
            $params = ok_epp_contactParamsFromForm($form);
            $existing = ok_epp_contactParamsFromForm($current['contents'][ucfirst($role)] ?? []);
            if (ok_epp_contactCanonical($params) == ok_epp_contactCanonical($existing)) {
                continue;
            }

            $others = $assigned;
            unset($others[$role]);
            if (in_array($contactId, $others, true)) {
                // The contact is shared with another role: give this role its own.
                $newId = ok_epp_generateUniqueContactId($con, $config['identityprefix'] ?? '', $config['domainid'] ?? '');
                $con->contactCreate($newId, $params);
                $con->domainUpdateContact($domainName, $role, $newId, $contactId);
                $assigned[$role] = $newId;
            } else {
                $con->contactUpdate($contactId, $params);
            }
        }
        return ['success' => true];
    });
}

function ok_epp_GetEPPCode(array $config, $domainName)
{
    return ok_epp_withClient($config, function ($con) use ($domainName) {
        $authInfo = $con->domainInfo($domainName)['domain']['authInfo'];
        if ($authInfo === '') {
            // Registries that store auth codes hashed do not return them: set a new one.
            $authInfo = ok_epp_generateObjectPW();
            $con->domainUpdateAuthinfo($domainName, $authInfo);
        }
        return ['eppcode' => $authInfo];
    });
}

function ok_epp_RegisterNameserver(array $config)
{
    if (empty($config['nameserver']) || empty($config['ipaddress'])) {
        return ['error' => 'Empty required parameters values'];
    }
    return ok_epp_withClient($config, function ($con) use ($config) {
        $check = $con->hostCheck($config['nameserver']);
        if (!empty($check['host'][0]['avail'])) {
            $con->hostCreate($config['nameserver'], $config['ipaddress']);
        }
        return ['success' => true];
    });
}

function ok_epp_ModifyNameserver(array $config)
{
    if (empty($config['nameserver']) || empty($config['currentipaddress']) || empty($config['newipaddress'])) {
        return ['error' => 'Empty required parameters values'];
    }
    return ok_epp_withClient($config, function ($con) use ($config) {
        $con->hostUpdate($config['nameserver'], $config['newipaddress'], $config['currentipaddress']);
        return ['success' => true];
    });
}

function ok_epp_DeleteNameserver(array $config)
{
    if (empty($config['nameserver'])) {
        return ['error' => 'Empty required parameters values'];
    }
    return ok_epp_withClient($config, function ($con) use ($config) {
        $con->hostDelete($config['nameserver']);
        return ['success' => true];
    });
}

function ok_epp_RequestDelete(array $config, $domainName)
{
    return ok_epp_withClient($config, function ($con) use ($domainName) {
        $con->domainDelete($domainName);
        return ['success' => true];
    });
}

function ok_epp_SyncDomain(array $config, $domainName)
{
    return ok_epp_withClient($config, function ($con) use ($config, $domainName) {
        $info = $con->domainInfo($domainName)['domain'];
        $expiry = ok_epp_paidThrough($config, $info);
        if ($expiry === '') {
            throw new EppException('Empty expDate date for domain: ' . $domainName);
        }
        if (strtotime($expiry . ' 23:59:59') < time()) {
            return ['expired' => true, 'expirydate' => $expiry];
        }
        return ['active' => true, 'expirydate' => $expiry];
    });
}

/**
 * Contact data for the post-transfer contact: from the params when WHMCS
 * passed them, else from the client record. [] when none is found.
 */
function ok_epp_transferContactParams(array $config)
{
    if (!empty($config['firstname']) && !empty($config['email'])) {
        return $config;
    }
    if (!function_exists('getClientsDetails')) {
        return [];
    }

    $userId = (int) ($config['userid'] ?? 0);
    if ($userId <= 0 && !empty($config['domainid']) && class_exists('\WHMCS\Database\Capsule')) {
        try {
            $userId = (int) \WHMCS\Database\Capsule::table('tbldomains')->where('id', (int) $config['domainid'])->value('userid');
        } catch (\Throwable $e) {
            $userId = 0;
        }
    }
    if ($userId <= 0) {
        return [];
    }

    $client = getClientsDetails($userId);
    if (!is_array($client) || empty($client['email'])) {
        return [];
    }
    $phone = '';
    foreach (['phonenumberformatted', 'fullphonenumber', 'phonenumber'] as $key) {
        if (!empty($client[$key])) {
            $phone = $client[$key];
            break;
        }
    }
    return [
        'firstname' => $client['firstname'] ?? '',
        'lastname' => $client['lastname'] ?? '',
        'companyname' => $client['companyname'] ?? '',
        'email' => $client['email'],
        'address1' => $client['address1'] ?? '',
        'address2' => $client['address2'] ?? '',
        'city' => $client['city'] ?? '',
        'state' => $client['state'] ?? '',
        'postcode' => $client['postcode'] ?? '',
        'country' => !empty($client['countrycode']) ? $client['countrycode'] : ($client['country'] ?? ''),
        'fullphonenumber' => $phone,
        'phonecc' => $client['phonecc'] ?? '',
    ];
}

function ok_epp_TransferDomainSync(array $config, $domainName)
{
    return ok_epp_withClient($config, function ($con) use ($config, $domainName) {
        try {
            $transfer = $con->domainTransfer($domainName, 'query')['domain'];
        } catch (EppException $e) {
            // Some registries forget the transfer once it completed: the
            // domain being sponsored by us then means it did complete.
            $info = $con->domainInfo($domainName)['domain'];
            $ours = $info['clID'] !== '' && strcasecmp($info['clID'], (string) ($config['clid'] ?? '')) === 0;
            if (!$ours || in_array('pendingTransfer', $info['status'], true)) {
                throw $e;
            }
            $transfer = ['trStatus' => 'serverApproved', 'exDate' => ok_epp_paidThrough($config, $info)];
        }
        $trStatus = $transfer['trStatus'];

        if ($trStatus === 'pending') {
            return ['completed' => false];
        }
        if (in_array($trStatus, ['clientRejected', 'clientCancelled', 'serverCancelled'], true)) {
            return ['completed' => false, 'failed' => true, 'reason' => $trStatus];
        }
        if (!in_array($trStatus, ['clientApproved', 'serverApproved'], true)) {
            return ['error' => sprintf('invalid transfer status: %s', $trStatus)];
        }

        $expiry = ok_epp_datePart($transfer['exDate']);
        if ($expiry === '') {
            $expiry = ok_epp_paidThrough($config, $con->domainInfo($domainName)['domain']);
        }
        $return = ['completed' => true, 'expirydate' => $expiry];

        // Post-transfer: own contact for every role, lock, new auth code. Best effort.
        try {
            $contact = ok_epp_transferContactParams($config);
            if ($contact) {
                $newContactId = ok_epp_generateUniqueContactId($con, $config['identityprefix'] ?? '', $config['domainid'] ?? '');
                $con->contactCreate($newContactId, $contact);
                $con->domainTransferUpdate($domainName, $newContactId);
            }
        } catch (\Throwable $e) {
            ok_epp_modulelog($domainName, 'Transfer completed; post-transfer update failed: ' . $e->getMessage(), 'TransferSync-postTransfer');
        }
        return $return;
    });
}

function ok_epp_cancelDomainTransfer(array $config, $domainName)
{
    return ok_epp_withClient($config, function ($con) use ($domainName) {
        $con->domainTransfer($domainName, 'cancel');
        return ['message' => 'Successfully cancelled the domain transfer'];
    });
}

function ok_epp_AuthinfoUpdate(array $config, $domainName)
{
    return ok_epp_withClient($config, function ($con) use ($domainName) {
        $con->domainUpdateAuthinfo($domainName, ok_epp_generateObjectPW());
        return ['success' => true, 'message' => 'Successfully Changed EPP code'];
    });
}

function ok_epp_GetDomainInformation(array $config, $domainName)
{
    return ok_epp_withClient($config, function ($con) use ($domainName) {
        return $con->domainInfo($domainName)['domain'];
    });
}
