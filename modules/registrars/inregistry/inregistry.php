<?php

/**
 * Unofficial WHMCS registrar module for .IN domains (NIXI .IN Registry, EPP).
 * By AllsWeb: https://github.com/allswebtech/inregistry-registrars-whmcs-module
 */

if (!defined("WHMCS")) {
    exit("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/Config.php';

function inregistry_MetaData()
{
    return ["DisplayName" => "IN Registry", "APIVersion" => "1.1", "NonLinearRegistrationPricing" => true];
}

function inregistry_GetConfigArray()
{
    return [
        "Description" => [
            "Type" => "System",
            "Value" => "Unofficial third-party module for the .IN Registry (NIXI) EPP service, by AllsWeb. "
                . "Not made or endorsed by NIXI or its registry operator. "
                . "<a href=\"https://github.com/allswebtech/inregistry-registrars-whmcs-module\" target=\"_blank\" rel=\"noopener\">Documentation and source</a>",
        ],
        'local_cert' => [
            'FriendlyName' => 'Signed Certificate',
            'Type' => 'text',
            'Default' => 'csr-signed.pem',
            'Description' => 'Absolute path, outside the web root, to the PEM client certificate signed by the registry.',
        ],
        'local_pk' => [
            'FriendlyName' => 'Private Key',
            'Type' => 'text',
            'Default' => 'username-key.pem',
            'Description' => 'Absolute path, outside the web root, to the PEM private key of the certificate.',
        ],
        'passphrase' => [
            'FriendlyName' => 'Pass Phrase',
            'Type' => 'password',
            'Size' => '32',
            'Description' => 'Pass phrase of the private key (leave empty if the key is not encrypted).',
        ],
        'cafile' => [
            'FriendlyName' => 'Registry CA Certificate (optional)',
            'Type' => 'text',
            'Description' => 'Absolute path to the registry CA certificate (PEM). When set, the registry server certificate is verified.',
        ],
        'clid' => [
            'FriendlyName' => 'Client ID',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'EPP client identifier (registrar login).',
        ],
        'pw' => [
            'FriendlyName' => 'Password',
            'Type' => 'password',
            'Size' => '20',
            'Description' => 'EPP password.',
        ],
        'identityprefix' => [
            'FriendlyName' => 'Identity Prefix',
            'Type' => 'text',
            'Size' => '4',
            'Description' => 'Up to 4 letters/digits used at the start of contact IDs created by this module.',
        ],
        "TestMode" => [
            "FriendlyName" => "OT&E (Test) Mode",
            "Type" => "yesno",
            "Description" => "Tick to use the registry OT&E (test) system instead of production.",
        ],
    ];
}

function inregistry_config_validate($params)
{
    $value = function ($key) use ($params) {
        return isset($params[$key]) && is_scalar($params[$key]) ? trim((string) $params[$key]) : '';
    };
    $where = " Please navigate to Configuration (<i class=\"fa fa-wrench\" aria-hidden=\"true\"></i>) > System Settings > Domain Registrars to configure.";

    if ($value('clid') === '') {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("Missing IN Registry EPP Username." . $where);
    }
    if ($value('pw') === '') {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("Missing IN Registry EPP Password." . $where);
    }
    if ($value('local_cert') === '' || !is_readable($value('local_cert'))) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("IN Registry Signed Certificate missing or not readable." . $where);
    }
    if ($value('local_pk') !== '' && !is_readable($value('local_pk'))) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("IN Registry Private Key not readable." . $where);
    }
    if ($value('cafile') !== '' && !is_readable($value('cafile'))) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("Registry CA Certificate not readable." . $where);
    }
    if (!preg_match('/^[A-Za-z0-9]{0,4}$/', $value('identityprefix'))) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration("Identity Prefix: at most 4 letters or digits.");
    }

    $con = \AllsWeb\InRegistry\ok_epp_startEppClient($params);
    if (!empty($con->error)) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration($con->error);
    }
    $con->logout();
}

/**
 * Domain name from a WHMCS Domain object, a string, or params
 * (domainObj, else domainname, else sld.tld), lower-cased.
 */
function inregistry_getDomainName($domain, $skipFilter = false)
{
    $name = '';
    if (is_object($domain) && method_exists($domain, 'getDomain')) {
        $name = (string) $domain->getDomain();
    } elseif (is_array($domain)) {
        if (!empty($domain['domainObj']) && is_object($domain['domainObj']) && method_exists($domain['domainObj'], 'getDomain')) {
            $name = (string) $domain['domainObj']->getDomain();
        } elseif (!empty($domain['domainname']) && is_scalar($domain['domainname'])) {
            $name = (string) $domain['domainname'];
        } elseif (!empty($domain['sld']) && !empty($domain['tld'])) {
            $name = $domain['sld'] . '.' . ltrim($domain['tld'], '.');
        }
    } elseif (is_scalar($domain)) {
        $name = (string) $domain;
    }
    $name = trim($name);
    if ($skipFilter) {
        return $name;
    }
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

/** Shared wrapper of the module functions: params → domain name → $fn($params, $domain). */
function inregistry_call($params, callable $fn)
{
    try {
        $params = is_array($params) ? $params : [];
        if (function_exists('injectDomainObjectIfNecessary')) {
            $params = injectDomainObjectIfNecessary($params);
        }
        $domainName = inregistry_getDomainName($params);
        if ($domainName === '') {
            return ["error" => "Empty Domain Name"];
        }
        return $fn($params, $domainName);
    } catch (\Throwable $e) {
        \AllsWeb\InRegistry\ok_epp_modulelog(get_class($e), $e->getMessage(), 'module-error');
        return ["error" => \AllsWeb\InRegistry\ok_epp_errorMessage($e)];
    }
}

function inregistry_nameserversFromParams(array $params)
{
    $nss = [];
    foreach (['ns1', 'ns2', 'ns3', 'ns4', 'ns5'] as $key) {
        if (!empty($params[$key]) && is_scalar($params[$key])) {
            $nss[] = trim((string) $params[$key]);
        }
    }
    return $nss;
}

function inregistry_GetNameservers(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        $nss = \AllsWeb\InRegistry\ok_epp_GetNameservers($params, $domainName);
        if (isset($nss['error'])) {
            return $nss;
        }
        $return = [];
        foreach (array_values((array) $nss) as $i => $ns) {
            $return['ns' . ($i + 1)] = $ns;
        }
        return $return;
    });
}

function inregistry_SaveNameservers(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_SaveNameservers($params, $domainName, inregistry_nameserversFromParams($params));
    });
}

function inregistry_GetDomainExtensionGroup()
{
    return \AllsWeb\InRegistry\ok_epp_gTld();
}

function inregistry_GetRegistrarLock(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_GetRegistrarLock($params, $domainName);
    });
}

function inregistry_SaveRegistrarLock(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        $lock = isset($params["lockenabled"]) && $params["lockenabled"] === "locked" ? "locked" : "unlocked";
        return \AllsWeb\InRegistry\ok_epp_SaveRegistrarLock($params, $domainName, $lock);
    });
}

function inregistry_RegisterDomain(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_RegisterDomain($params, $domainName, inregistry_nameserversFromParams($params));
    });
}

function inregistry_TransferDomain(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        $secret = isset($params['transfersecret']) && is_scalar($params['transfersecret']) ? trim((string) $params['transfersecret']) : '';
        if ($secret === '') {
            return ["error" => "Empty Transfer Secret Code"];
        }
        return \AllsWeb\InRegistry\ok_epp_TransferDomain($params, $domainName, $secret);
    });
}

function inregistry_RenewDomain(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_RenewDomain($params, $domainName);
    });
}

function inregistry_GetContactDetails(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_GetContactDetails($params, $domainName);
    });
}

function inregistry_SaveContactDetails(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_SaveContactDetails($params, $domainName);
    });
}

function inregistry_GetEPPCode(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_GetEPPCode($params, $domainName);
    });
}

function inregistry_RegisterNameserver(array $params)
{
    return \AllsWeb\InRegistry\ok_epp_RegisterNameserver($params);
}

function inregistry_ModifyNameserver(array $params)
{
    return \AllsWeb\InRegistry\ok_epp_ModifyNameserver($params);
}

function inregistry_DeleteNameserver(array $params)
{
    return \AllsWeb\InRegistry\ok_epp_DeleteNameserver($params);
}

function inregistry_RequestDelete(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_RequestDelete($params, $domainName);
    });
}

function inregistry_Sync(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_SyncDomain($params, $domainName);
    });
}

function inregistry_TransferSync(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_TransferDomainSync($params, $domainName);
    });
}

function inregistry_AdminCustomButtonArray($params = [])
{
    $buttons = [];
    $domainId = 0;
    if (is_array($params) && !empty($params['domainid'])) {
        $domainId = (int) $params['domainid'];
    } elseif (!empty($_REQUEST['domainid'])) {
        $domainId = (int) $_REQUEST['domainid'];
    } elseif (!empty($_REQUEST['id'])) {
        $domainId = (int) $_REQUEST['id'];
    }

    $domain = null;
    if ($domainId > 0) {
        try {
            if (class_exists('\WHMCS\Database\Capsule')) {
                $row = \WHMCS\Database\Capsule::table('tbldomains')->where('id', $domainId)->first(['type', 'status']);
                $domain = $row ? (array) $row : null;
            } elseif (function_exists('get_query_vals')) {
                $domain = get_query_vals("tbldomains", "type,status", ["id" => $domainId]);
            }
        } catch (\Throwable $e) {
            $domain = null;
        }
    }
    if (is_array($domain) && ($domain['type'] ?? '') === 'Transfer' && ($domain['status'] ?? '') === 'Pending Transfer') {
        $buttons["Cancel Domain Transfer"] = "canceldomaintransfer";
    }
    $buttons["Change EPP Code"] = "changeEppCode";
    return $buttons;
}

function inregistry_changeEppCode(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        return \AllsWeb\InRegistry\ok_epp_AuthinfoUpdate($params, $domainName);
    });
}

function inregistry_canceldomaintransfer(array $params)
{
    return inregistry_call($params, function ($params, $domainName) {
        $result = \AllsWeb\InRegistry\ok_epp_cancelDomainTransfer($params, $domainName);
        if (!isset($result['error']) && !empty($params["domainid"])) {
            try {
                if (class_exists('\WHMCS\Database\Capsule')) {
                    \WHMCS\Database\Capsule::table('tbldomains')->where('id', (int) $params['domainid'])->update(['status' => 'Cancelled']);
                } elseif (function_exists('update_query')) {
                    update_query("tbldomains", ["status" => "Cancelled"], ["id" => (int) $params["domainid"]]);
                }
            } catch (\Throwable $e) {
                \AllsWeb\InRegistry\ok_epp_modulelog($params['domainid'], $e->getMessage(), 'canceldomaintransfer-db');
            }
        }
        return $result;
    });
}

/** Called when a domain is viewed in WHMCS (7.6+). Throws on failure, per the WHMCS contract. */
function inregistry_GetDomainInformation($params)
{
    try {
        $params = is_array($params) ? $params : [];
        if (function_exists('injectDomainObjectIfNecessary')) {
            $params = injectDomainObjectIfNecessary($params);
        }
        $domainName = inregistry_getDomainName($params);
        if ($domainName === '') {
            throw new \Exception("Empty Domain Name");
        }

        $info = \AllsWeb\InRegistry\ok_epp_GetDomainInformation($params, $domainName);
        if (!is_array($info) || isset($info['error'])) {
            throw new \Exception(is_array($info) && isset($info['error']) ? $info['error'] : 'Unknown registry error');
        }

        $nameservers = [];
        foreach (array_values($info['ns']) as $i => $ns) {
            $nameservers['ns' . ($i + 1)] = $ns;
        }

        $status = $info['status'];
        $rgp = $info['rgpStatus'];
        if (in_array('pendingDelete', $status, true) || in_array('redemptionPeriod', $rgp, true) || in_array('pendingRestore', $rgp, true)) {
            $current = \WHMCS\Domain\Registrar\Domain::STATUS_PENDING_DELETE;
        } elseif (in_array('clientHold', $status, true) || in_array('serverHold', $status, true)) {
            $current = \WHMCS\Domain\Registrar\Domain::STATUS_SUSPENDED;
        } elseif (in_array('inactive', $status, true)) {
            $current = \WHMCS\Domain\Registrar\Domain::STATUS_INACTIVE;
        } else {
            $current = \WHMCS\Domain\Registrar\Domain::STATUS_ACTIVE;
        }

        $expiry = \AllsWeb\InRegistry\ok_epp_paidThrough($params, $info);
        $domain = (new \WHMCS\Domain\Registrar\Domain())
            ->setDomain($info['name'] !== '' ? $info['name'] : $domainName)
            ->setNameservers($nameservers)
            ->setRegistrationStatus($current)
            ->setTransferLock(in_array('clientTransferProhibited', $status, true))
            ->setTransferLockExpiryDate(null)
            ->setRestorable(false)
            ->setIdProtectionStatus(!empty($params['idprotection']))
            ->setDnsManagementStatus(!empty($params['dnsmanagement']))
            ->setEmailForwardingStatus(!empty($params['emailforwarding']))
            ->setIsIrtpEnabled(false)
            ->setIrtpOptOutStatus(false)
            ->setIrtpTransferLock(false)
            ->setDomainContactChangePending(false)
            ->setPendingSuspension(false)
            ->setDomainContactChangeExpiryDate(null)
            ->setIrtpVerificationTriggerFields([]);
        if ($expiry !== '') {
            $domain->setExpiryDate(\WHMCS\Carbon::createFromFormat('Y-m-d H:i:s', $expiry . ' 00:00:00'));
        }
        return $domain;
    } catch (\Exception $e) {
        throw $e;
    } catch (\Throwable $e) {
        throw new \Exception('Module error: ' . $e->getMessage(), 0, $e);
    }
}
