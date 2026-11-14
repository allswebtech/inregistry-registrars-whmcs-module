<?php

namespace AllsWeb\InRegistry;

const VERSION = '2.0.0';
const MODULE = 'inregistry';

require_once __DIR__ . '/Helper.php';
require_once __DIR__ . '/EppClient.php';

/**
 * Connection settings for EppClient, built from the WHMCS registrar settings.
 */
function ok_epp_buildConfig(array $config)
{
    $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
        $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }

    return [
        'host' => !empty($config['TestMode']) ? 'epp.ote.nixiregistry.in' : 'epp.nixiregistry.in',
        'port' => 700, // IANA-assigned EPP port (RFC 5734)
        'crypto_method' => $crypto,
        'local_cert' => trim((string) ($config['local_cert'] ?? '')),
        'local_pk' => trim((string) ($config['local_pk'] ?? '')),
        'passphrase' => (string) ($config['passphrase'] ?? ''),
        'cafile' => trim((string) ($config['cafile'] ?? '')),
        'identityprefix' => (string) ($config['identityprefix'] ?? ''),
        'connect_timeout' => 30,
        'timeout' => 60,
    ];
}

/**
 * Zones of the .IN registry. Informational only: the registry decides which
 * names can be registered, and WHMCS only routes TLDs assigned to this module.
 */
function ok_epp_gTld()
{
    $in_supported_tld_list = '
.in
.us.in
.up.in
.uk.in
.tv.in
.travel.in
.dr.in
.delhi.in
.cs.in
.coop.in
.com.in
.cn.in
.ca.in
.business.in
.biz.in
.bihar.in
.am.in
.pro.in
.ai.in
.post.in
.6g.in
.pg.in
.5g.in
.me.in
.ind.in
.io.in
.firm.in
.internet.in
.gen.in
.int.in
.org.in
.info.in
.net.in
.gujarat.in
.co.in
.er.in
';
    return ['ccTLD' => ok_epp_line2arr($in_supported_tld_list)];
}

function ok_epp_domainStatus($isStatus = null)
{
    $domainStatus = [
        'clientDeleteProhibited',
        'clientHold',
        'clientRenewProhibited',
        'clientTransferProhibited',
        'clientUpdateProhibited',
        'inactive',
        'ok',
        'pendingCreate',
        'pendingDelete',
        'pendingRenew',
        'pendingTransfer',
        'pendingUpdate',
        'serverDeleteProhibited',
        'serverHold',
        'serverRenewProhibited',
        'serverTransferProhibited',
        'serverUpdateProhibited',
    ];

    return $isStatus === null ? $domainStatus : in_array($isStatus, $domainStatus, true);
}

function ok_epp_contactsType($isType = null)
{
    $contactsType = ['registrant', 'tech', 'admin', 'billing'];

    return $isType === null ? $contactsType : in_array($isType, $contactsType, true);
}

function ok_epp_trstatus($isStatus = null)
{
    $trstatus = ['clientApproved', 'clientCancelled', 'clientRejected', 'pending', 'serverApproved', 'serverCancelled'];

    return $isStatus === null ? $trstatus : in_array($isStatus, $trstatus, true);
}

/**
 * Masks secrets in an EPP document before it is logged: the login <clID>,
 * every <pw>/<newPW> element whatever its namespace prefix (domain:pw,
 * contact:pw, loginSec:pw, ...). Returns [maskedXml, foundSecrets].
 */
function ok_epp_maskXml($xml)
{
    $xml = (string) $xml;
    $secrets = [];

    $xml = preg_replace_callback(
        '#<((?:[A-Za-z_][\w.\-]*:)?(?:pw|newPW))(\s[^>]*)?>(.*?)</\1\s*>#s',
        function ($m) use (&$secrets) {
            if ($m[3] !== '' && $m[3] !== '[LOGIN-SECURITY]') {
                $secrets[] = $m[3];
            }
            return '<' . $m[1] . $m[2] . '>' . ($m[3] === '[LOGIN-SECURITY]' ? $m[3] : '***') . '</' . $m[1] . '>';
        },
        $xml
    );

    // Only the <clID> of the <login> command (unprefixed or epp:) is the registrar login.
    if (preg_match('#<(?:epp:)?login[\s>/]#', $xml)) {
        $xml = preg_replace_callback(
            '#<((?:epp:)?clID)>(.*?)</\1>#s',
            function ($m) {
                // Not added to the secret list: the registrar ID also appears,
                // legitimately, as domain:clID etc. in responses.
                return '<' . $m[1] . '>***</' . $m[1] . '>';
            },
            $xml
        );
    }

    return [$xml, $secrets];
}

/**
 * The only log of this module: WHMCS module log (Utilities > Logs > Module Log).
 */
function ok_epp_modulelog($send, $responsedata, $action, array $secrets = [])
{
    if (!function_exists('logModuleCall')) {
        return;
    }

    $send = is_scalar($send) ? (string) $send : json_encode($send);
    $responsedata = is_scalar($responsedata) ? (string) $responsedata : json_encode($responsedata);

    list($sendMasked, $s1) = ok_epp_maskXml($send);
    list($respMasked, $s2) = ok_epp_maskXml($responsedata);

    $replace = [];
    foreach (array_merge($secrets, $s1, $s2) as $secret) {
        $secret = (string) $secret;
        if (strlen($secret) < 4) {
            continue;
        }
        $replace[] = $secret;
        $decoded = ok_epp_decodeWhmcs($secret);
        if ($decoded !== $secret && strlen($decoded) >= 4) {
            $replace[] = $decoded;
        }
        $encoded = ok_epp_xmlEscape($secret);
        if ($encoded !== $secret && $encoded !== '') {
            $replace[] = $encoded;
        }
    }
    $replace = array_values(array_unique($replace));

    // Belt and braces: also strip any literal occurrence of a known secret.
    if ($replace) {
        $sendMasked = str_replace($replace, '***', $sendMasked);
        $respMasked = str_replace($replace, '***', $respMasked);
    }

    logModuleCall(MODULE, $action, $sendMasked, $respMasked, $respMasked, $replace);
}
