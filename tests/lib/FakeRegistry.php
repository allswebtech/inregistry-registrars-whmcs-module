<?php

namespace AllsWeb\InRegistry\Tests;

/**
 * A small in-memory EPP server. Every command it receives and every
 * response it produces is validated against the official XSDs. State is
 * kept so flows (register, save nameservers, ...) behave like a registry.
 */
class FakeRegistry
{
    const NS = [
        'epp' => 'urn:ietf:params:xml:ns:epp-1.0',
        'domain' => 'urn:ietf:params:xml:ns:domain-1.0',
        'contact' => 'urn:ietf:params:xml:ns:contact-1.0',
        'host' => 'urn:ietf:params:xml:ns:host-1.0',
        'rgp' => 'urn:ietf:params:xml:ns:rgp-1.0',
        'secDNS' => 'urn:ietf:params:xml:ns:secDNS-1.1',
        'loginSec' => 'urn:ietf:params:xml:ns:epp:loginSec-1.0',
    ];

    /** extURIs advertised in the greeting */
    public $extURIs = ['urn:ietf:params:xml:ns:secDNS-1.1', 'urn:ietf:params:xml:ns:rgp-1.0'];
    /** 'canonical' | 'alt' (other prefixes, epp: prefixed) | 'default' (default namespaces) */
    public $style = 'canonical';
    public $clID = 'REGISTRAR1';

    public $domains = [];
    public $contacts = [];
    public $hosts = [];
    public $transfers = [];
    /** names reported unavailable by check, name => reason */
    public $reserved = [];
    /** contact IDs reported as taken by check */
    public $takenContactIds = [];
    /** key ("create:domain", "update:domain", ...) => ['code', 'msg', 'reason', 'match' (substring), 'times'] */
    public $failures = [];
    public $dropOnLogout = false;
    public $pollQueue = [];
    /** raw response XML strings returned in order (after command validation), instead of the state machine */
    public $canned = [];
    /** raw greeting XML replacing the generated one */
    public $greetingOverride = null;
    /** avail attribute values used by domain check: ['true', 'false'] or ['1', '0'] */
    public $availWords = ['true', 'false'];
    /** command keys deliberately NOT validated against the XSDs (each use says why) */
    public $schemaExempt = [];

    /** every command: ['key' => ..., 'xml' => ...] */
    public $commands = [];
    public $loginPw = null;
    public $loginNewPw = null;
    public $loginSecPw = null;
    private $sv = 0;

    public function greeting()
    {
        if ($this->greetingOverride !== null) {
            return $this->greetingOverride;
        }
        $ext = '';
        if ($this->extURIs) {
            $ext = '<svcExtension>';
            foreach ($this->extURIs as $uri) {
                $ext .= '<extURI>' . $uri . '</extURI>';
            }
            $ext .= '</svcExtension>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="' . self::NS['epp'] . '"><greeting>'
            . '<svID>Fake .IN EPP</svID><svDate>2026-11-14T10:00:00.0Z</svDate><svcMenu>'
            . '<version>1.0</version><lang>en</lang>'
            . '<objURI>' . self::NS['domain'] . '</objURI><objURI>' . self::NS['contact'] . '</objURI><objURI>' . self::NS['host'] . '</objURI>'
            . $ext . '</svcMenu>'
            . '<dcp><access><all/></access><statement><purpose><admin/><prov/></purpose><recipient><ours/><public/></recipient><retention><stated/></retention></statement></dcp>'
            . '</greeting></epp>';
        Schema::check($xml, 'greeting');
        return $xml;
    }

    public function keys()
    {
        return array_map(function ($c) {
            return $c['key'];
        }, $this->commands);
    }

    public function commandsFor($key)
    {
        return array_values(array_filter($this->commands, function ($c) use ($key) {
            return $c['key'] === $key;
        }));
    }

    // ------------------------------------------------------------------

    private static function esc($v)
    {
        return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function respond($code, $msg, $resData = '', $extension = '', $extValue = '', $msgQ = '')
    {
        $e = $this->style === 'alt' ? 'epp:' : '';
        $root = $this->style === 'alt' ? '<epp:epp xmlns:epp="' . self::NS['epp'] . '">' : '<epp xmlns="' . self::NS['epp'] . '">';
        $this->sv++;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . $root . "<{$e}response>"
            . "<{$e}result code=\"{$code}\"><{$e}msg>" . self::esc($msg) . "</{$e}msg>" . str_replace('{E}', $e, $extValue) . "</{$e}result>"
            . str_replace('{E}', $e, $msgQ)
            . ($resData !== '' ? "<{$e}resData>{$resData}</{$e}resData>" : '')
            . ($extension !== '' ? "<{$e}extension>{$extension}</{$e}extension>" : '')
            . "<{$e}trID><{$e}clTRID>TEST-{$this->sv}</{$e}clTRID><{$e}svTRID>SV-{$this->sv}</{$e}svTRID></{$e}trID>"
            . "</{$e}response></" . ($e === '' ? 'epp' : 'epp:epp') . '>';
        $xml = $this->restyle($xml);
        Schema::check($xml, 'response(' . $this->style . ')');
        return $xml;
    }

    private function restyle($xml)
    {
        if ($this->style === 'alt') {
            $map = ['domain' => 'dm', 'contact' => 'ct', 'host' => 'hs', 'rgp' => 'rg', 'secDNS' => 'sd'];
            foreach ($map as $from => $to) {
                $xml = preg_replace('#(</?)' . $from . ':#', '$1' . $to . ':', $xml);
                $xml = str_replace('xmlns:' . $from . '=', 'xmlns:' . $to . '=', $xml);
            }
        } elseif ($this->style === 'default') {
            foreach (['domain', 'contact', 'host', 'rgp', 'secDNS'] as $p) {
                $xml = preg_replace('#(</?)' . $p . ':#', '$1', $xml);
                $xml = str_replace('xmlns:' . $p . '=', 'xmlns=', $xml);
            }
        }
        return $xml;
    }

    private function error($code, $msg, $reason = '', $value = '')
    {
        $ext = '';
        if ($reason !== '' || $value !== '') {
            $ext = '<{E}extValue><{E}value>' . ($value !== '' ? $value : '<{E}undef/>') . '</{E}value>'
                . '<{E}reason>' . self::esc($reason) . '</{E}reason></{E}extValue>';
        }
        return $this->respond($code, $msg, '', '', $ext);
    }

    private static function date($ts)
    {
        return gmdate('Y-m-d\TH:i:s', $ts) . '.0Z';
    }

    // ------------------------------------------------------------------

    /** @return string|null response XML, or null to drop the connection */
    public function handle($xml)
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            $this->commands[] = ['key' => 'unparsable', 'xml' => $xml];
            Schema::$errors[] = "client sent unparsable XML:\n" . $xml;
            return $this->error(2001, 'Command syntax error');
        }
        $xp = new \DOMXPath($doc);
        foreach (self::NS as $p => $uri) {
            $xp->registerNamespace($p, $uri);
        }
        $cmd = $xp->query('/epp:epp/epp:command/*[1]')->item(0);
        $verb = $cmd ? $cmd->localName : 'none';
        $object = '';
        $objNode = null;
        if ($cmd && !in_array($verb, ['login', 'logout', 'poll'], true)) {
            foreach ($cmd->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $object = array_search($child->namespaceURI, self::NS, true) ?: 'unknown';
                    $objNode = $child;
                    break;
                }
            }
        }
        $key = $object === '' ? $verb : $verb . ':' . $object;
        $this->commands[] = ['key' => $key, 'xml' => $xml];

        if (!in_array($key, $this->schemaExempt, true) && !Schema::check($xml, "command {$key}")) {
            return $this->error(2001, 'Command syntax error');
        }

        if ($this->canned) {
            return array_shift($this->canned);
        }

        if (isset($this->failures[$key])) {
            $f = $this->failures[$key];
            $matches = !isset($f['match']) || strpos($xml, $f['match']) !== false;
            if ($matches && (!isset($f['times']) || $f['times'] > 0)) {
                if (isset($f['times'])) {
                    $this->failures[$key]['times']--;
                }
                if (!empty($f['drop'])) {
                    return null;
                }
                return $this->error($f['code'], $f['msg'], $f['reason'] ?? '');
            }
        }

        $method = 'on' . ucfirst($verb) . ucfirst($object);
        if (!method_exists($this, $method)) {
            return $this->error(2101, 'Unimplemented command');
        }
        return $this->$method($xp, $cmd, $objNode);
    }

    private function t(\DOMXPath $xp, $q, $ctx)
    {
        $n = $xp->query($q, $ctx)->item(0);
        return $n ? trim($n->textContent) : '';
    }

    private function all(\DOMXPath $xp, $q, $ctx)
    {
        $out = [];
        foreach ($xp->query($q, $ctx) as $n) {
            $out[] = trim($n->textContent);
        }
        return $out;
    }

    // --- session --------------------------------------------------------

    private function onLogin($xp, $cmd)
    {
        $this->loginPw = $this->t($xp, 'epp:pw', $cmd);
        $this->loginNewPw = $xp->query('epp:newPW', $cmd)->length ? $this->t($xp, 'epp:newPW', $cmd) : null;
        $sec = $xp->query('/epp:epp/epp:command/epp:extension/loginSec:loginSec/loginSec:pw')->item(0);
        $this->loginSecPw = $sec ? $sec->textContent : null;
        return $this->respond(1000, 'Command completed successfully');
    }

    private function onLogout()
    {
        if ($this->dropOnLogout) {
            return null;
        }
        return $this->respond(1500, 'Command completed successfully; ending session');
    }

    private function onPoll($xp, $cmd)
    {
        if ($cmd->getAttribute('op') === 'ack') {
            array_shift($this->pollQueue);
            return $this->respond(1000, 'Command completed successfully');
        }
        if (!$this->pollQueue) {
            return $this->respond(1300, 'Command completed successfully; no messages');
        }
        $m = $this->pollQueue[0];
        $q = '<{E}msgQ count="' . count($this->pollQueue) . '" id="' . self::esc($m['id']) . '"><{E}qDate>2026-11-14T10:00:00.0Z</{E}qDate><{E}msg>' . self::esc($m['msg']) . '</{E}msg></{E}msgQ>';
        return $this->respond(1301, 'Command completed successfully; ack to dequeue', '', '', '', $q);
    }

    // --- domain ---------------------------------------------------------

    public function addDomain($name, array $data = [])
    {
        $this->domains[strtolower($name)] = array_merge([
            'name' => strtolower($name),
            'status' => ['ok'],
            'registrant' => 'CON1',
            'contacts' => [['admin', 'CON1'], ['tech', 'CON1'], ['billing', 'CON1']],
            'ns' => [],
            'hosts' => [],
            'crDate' => strtotime('2024-01-10 10:00:00 UTC'),
            'exDate' => strtotime('2027-01-10 10:00:00 UTC'),
            'authInfo' => 'Auth-Code+123',
            'rgp' => [],
            'clID' => $this->clID,
        ], $data);
    }

    public function addContact($id, array $data = [])
    {
        $this->contacts[strtoupper($id)] = array_merge([
            'id' => strtoupper($id),
            'postal' => [['type' => 'int', 'name' => 'Asha Rao', 'org' => 'Rao & Sons', 'street' => ['12 MG Road'], 'city' => 'Bengaluru', 'sp' => 'KA', 'pc' => '560001', 'cc' => 'IN']],
            'voice' => '+91.9876543210',
            'fax' => '',
            'email' => 'asha@example.in',
            'status' => ['ok'],
        ], $data);
    }

    public function addHost($name, array $addr = [])
    {
        $this->hosts[strtolower($name)] = ['name' => strtolower($name), 'addr' => $addr];
    }

    private function onCheckDomain($xp, $cmd, $obj)
    {
        $cds = '';
        foreach ($this->all($xp, 'domain:name', $obj) as $name) {
            $lc = strtolower($name);
            $avail = !isset($this->domains[$lc]) && !isset($this->reserved[$lc]);
            $reason = isset($this->reserved[$lc]) ? $this->reserved[$lc] : (isset($this->domains[$lc]) ? 'In use' : '');
            $cds .= '<domain:cd><domain:name avail="' . ($avail ? $this->availWords[0] : $this->availWords[1]) . '">' . self::esc($name) . '</domain:name>'
                . ($reason !== '' ? '<domain:reason>' . self::esc($reason) . '</domain:reason>' : '') . '</domain:cd>';
        }
        return $this->respond(1000, 'Command completed successfully', '<domain:chkData xmlns:domain="' . self::NS['domain'] . '">' . $cds . '</domain:chkData>');
    }

    private function onCreateDomain($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'domain:name', $obj));
        if (isset($this->domains[$name])) {
            return $this->error(2302, 'Object exists');
        }
        $registrant = $this->t($xp, 'domain:registrant', $obj);
        $contacts = [];
        foreach ($xp->query('domain:contact', $obj) as $c) {
            $contacts[] = [$c->getAttribute('type'), trim($c->textContent)];
        }
        foreach (array_merge([$registrant], array_column($contacts, 1)) as $cid) {
            if ($cid !== '' && !isset($this->contacts[$cid])) {
                return $this->error(2303, 'Object does not exist', "contact {$cid} not found");
            }
        }
        $ns = $this->all($xp, 'domain:ns/domain:hostObj', $obj);
        foreach ($ns as $h) {
            if (!isset($this->hosts[strtolower($h)])) {
                return $this->error(2303, 'Object does not exist', "host {$h} not found");
            }
        }
        $period = (int) ($this->t($xp, 'domain:period', $obj) ?: 1);
        $now = strtotime('2026-11-14 10:00:00 UTC');
        $this->addDomain($name, [
            'registrant' => $registrant,
            'contacts' => $contacts,
            'ns' => $ns,
            'crDate' => $now,
            'exDate' => strtotime("+{$period} years", $now),
            'authInfo' => $this->t($xp, 'domain:authInfo/domain:pw', $obj),
        ]);
        $d = $this->domains[$name];
        return $this->respond(1000, 'Command completed successfully', '<domain:creData xmlns:domain="' . self::NS['domain'] . '">'
            . '<domain:name>' . self::esc($name) . '</domain:name><domain:crDate>' . self::date($d['crDate']) . '</domain:crDate>'
            . '<domain:exDate>' . self::date($d['exDate']) . '</domain:exDate></domain:creData>');
    }

    private function onInfoDomain($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'domain:name', $obj));
        if (!isset($this->domains[$name])) {
            return $this->error(2303, 'Object does not exist');
        }
        $d = $this->domains[$name];
        $x = '<domain:infData xmlns:domain="' . self::NS['domain'] . '">'
            . '<domain:name>' . self::esc($d['name']) . '</domain:name><domain:roid>D' . abs(crc32($name)) . '-IN</domain:roid>';
        foreach ($d['status'] as $s) {
            $x .= is_array($s)
                ? '<domain:status s="' . $s[0] . '" lang="en">' . self::esc($s[1]) . '</domain:status>'
                : '<domain:status s="' . $s . '"/>';
        }
        if ($d['registrant'] !== null && $d['registrant'] !== '') {
            $x .= '<domain:registrant>' . self::esc($d['registrant']) . '</domain:registrant>';
        }
        foreach ($d['contacts'] as $c) {
            $x .= '<domain:contact type="' . $c[0] . '">' . self::esc($c[1]) . '</domain:contact>';
        }
        if ($d['ns']) {
            $x .= '<domain:ns>';
            foreach ($d['ns'] as $h) {
                $x .= '<domain:hostObj>' . self::esc($h) . '</domain:hostObj>';
            }
            $x .= '</domain:ns>';
        }
        foreach ($d['hosts'] as $h) {
            $x .= '<domain:host>' . self::esc($h) . '</domain:host>';
        }
        $x .= '<domain:clID>' . self::esc($d['clID']) . '</domain:clID><domain:crID>' . self::esc($d['clID']) . '</domain:crID>'
            . '<domain:crDate>' . self::date($d['crDate']) . '</domain:crDate>'
            . '<domain:exDate>' . self::date($d['exDate']) . '</domain:exDate>';
        if ($d['authInfo'] !== '') {
            $x .= '<domain:authInfo><domain:pw>' . self::esc($d['authInfo']) . '</domain:pw></domain:authInfo>';
        }
        $x .= '</domain:infData>';
        $ext = '';
        if ($d['rgp']) {
            $ext = '<rgp:infData xmlns:rgp="' . self::NS['rgp'] . '">';
            foreach ($d['rgp'] as $s) {
                $ext .= '<rgp:rgpStatus s="' . $s . '"/>';
            }
            $ext .= '</rgp:infData>';
        }
        return $this->respond(1000, 'Command completed successfully', $x, $ext);
    }

    private function statusList(array $status)
    {
        return array_map(function ($s) {
            return is_array($s) ? $s[0] : $s;
        }, $status);
    }

    private function onUpdateDomain($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'domain:name', $obj));
        if (!isset($this->domains[$name])) {
            return $this->error(2303, 'Object does not exist');
        }
        $d = $this->domains[$name];
        $currentNs = array_map('strtolower', $d['ns']);
        foreach ($this->all($xp, 'domain:add/domain:ns/domain:hostObj', $obj) as $h) {
            if (!isset($this->hosts[strtolower($h)])) {
                return $this->error(2303, 'Object does not exist', "host {$h} not found");
            }
            if (in_array(strtolower($h), $currentNs, true)) {
                return $this->error(2306, 'Parameter value policy error', "{$h} already associated");
            }
            $d['ns'][] = $h;
        }
        foreach ($this->all($xp, 'domain:rem/domain:ns/domain:hostObj', $obj) as $h) {
            $i = array_search($h, $d['ns'], true);
            if ($i === false) {
                return $this->error(2306, 'Parameter value policy error', "{$h} not associated");
            }
            array_splice($d['ns'], $i, 1);
        }
        $status = $this->statusList($d['status']);
        foreach ($xp->query('domain:add/domain:status', $obj) as $s) {
            if (in_array($s->getAttribute('s'), $status, true)) {
                return $this->error(2306, 'Parameter value policy error', 'status already set');
            }
            $status[] = $s->getAttribute('s');
        }
        foreach ($xp->query('domain:rem/domain:status', $obj) as $s) {
            $i = array_search($s->getAttribute('s'), $status, true);
            if ($i === false) {
                return $this->error(2306, 'Parameter value policy error', 'status not set');
            }
            array_splice($status, $i, 1);
        }
        $status = array_values(array_diff($status, ['ok']));
        $d['status'] = $status ?: ['ok'];
        foreach ($xp->query('domain:rem/domain:contact', $obj) as $c) {
            $d['contacts'] = array_values(array_filter($d['contacts'], function ($x) use ($c) {
                return !($x[0] === $c->getAttribute('type') && $x[1] === trim($c->textContent));
            }));
        }
        foreach ($xp->query('domain:add/domain:contact', $obj) as $c) {
            if (!isset($this->contacts[trim($c->textContent)])) {
                return $this->error(2303, 'Object does not exist', 'contact not found');
            }
            $d['contacts'][] = [$c->getAttribute('type'), trim($c->textContent)];
        }
        if ($xp->query('domain:chg/domain:registrant', $obj)->length) {
            $d['registrant'] = $this->t($xp, 'domain:chg/domain:registrant', $obj);
        }
        if ($xp->query('domain:chg/domain:authInfo/domain:pw', $obj)->length) {
            $d['authInfo'] = $this->t($xp, 'domain:chg/domain:authInfo/domain:pw', $obj);
        }
        if ($xp->query('/epp:epp/epp:command/epp:extension/rgp:update/rgp:restore[@op="request"]')->length) {
            $d['rgp'] = ['pendingRestore'];
        }
        $this->domains[$name] = $d;
        return $this->respond(1000, 'Command completed successfully');
    }

    private function onRenewDomain($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'domain:name', $obj));
        if (!isset($this->domains[$name])) {
            return $this->error(2303, 'Object does not exist');
        }
        $d = $this->domains[$name];
        if ($this->t($xp, 'domain:curExpDate', $obj) !== gmdate('Y-m-d', $d['exDate'])) {
            return $this->error(2306, 'Parameter value policy error', 'curExpDate mismatch');
        }
        $period = (int) $this->t($xp, 'domain:period', $obj);
        $d['exDate'] = strtotime("+{$period} years", $d['exDate']);
        $d['rgp'] = [];
        $this->domains[$name] = $d;
        return $this->respond(1000, 'Command completed successfully', '<domain:renData xmlns:domain="' . self::NS['domain'] . '">'
            . '<domain:name>' . self::esc($name) . '</domain:name><domain:exDate>' . self::date($d['exDate']) . '</domain:exDate></domain:renData>');
    }

    private function onDeleteDomain($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'domain:name', $obj));
        if (!isset($this->domains[$name])) {
            return $this->error(2303, 'Object does not exist');
        }
        unset($this->domains[$name]);
        return $this->respond(1000, 'Command completed successfully');
    }

    private function onTransferDomain($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'domain:name', $obj));
        $op = $cmd->getAttribute('op');
        if ($op === 'request') {
            $this->transfers[$name] = ['trStatus' => 'pending', 'exDate' => strtotime('2027-05-01 00:00:00 UTC')];
            return $this->respond(1001, 'Command completed successfully; action pending', $this->trnData($name));
        }
        if (!isset($this->transfers[$name])) {
            return $this->error(2301, 'Object not pending transfer');
        }
        if ($op === 'cancel') {
            $this->transfers[$name]['trStatus'] = 'clientCancelled';
            return $this->respond(1000, 'Command completed successfully');
        }
        return $this->respond(1000, 'Command completed successfully', $this->trnData($name));
    }

    private function trnData($name)
    {
        $t = $this->transfers[$name];
        $ex = isset($t['exDate']) && $t['exDate'] ? '<domain:exDate>' . self::date($t['exDate']) . '</domain:exDate>' : '';
        return '<domain:trnData xmlns:domain="' . self::NS['domain'] . '"><domain:name>' . self::esc($name) . '</domain:name>'
            . '<domain:trStatus>' . $t['trStatus'] . '</domain:trStatus><domain:reID>' . self::esc($this->clID) . '</domain:reID>'
            . '<domain:reDate>2026-11-01T10:00:00.0Z</domain:reDate><domain:acID>LOSINGREG</domain:acID>'
            . '<domain:acDate>2026-11-06T10:00:00.0Z</domain:acDate>' . $ex . '</domain:trnData>';
    }

    // --- contact --------------------------------------------------------

    private function onCheckContact($xp, $cmd, $obj)
    {
        $cds = '';
        foreach ($this->all($xp, 'contact:id', $obj) as $id) {
            $avail = !isset($this->contacts[$id]) && !in_array($id, $this->takenContactIds, true);
            $cds .= '<contact:cd><contact:id avail="' . ($avail ? '1' : '0') . '">' . self::esc($id) . '</contact:id></contact:cd>';
        }
        return $this->respond(1000, 'Command completed successfully', '<contact:chkData xmlns:contact="' . self::NS['contact'] . '">' . $cds . '</contact:chkData>');
    }

    private function contactFrom($xp, $node)
    {
        $postal = [];
        foreach ($xp->query('contact:postalInfo', $node) as $p) {
            $postal[] = [
                'type' => $p->getAttribute('type'),
                'name' => $this->t($xp, 'contact:name', $p),
                'org' => $this->t($xp, 'contact:org', $p),
                'street' => $this->all($xp, 'contact:addr/contact:street', $p),
                'city' => $this->t($xp, 'contact:addr/contact:city', $p),
                'sp' => $this->t($xp, 'contact:addr/contact:sp', $p),
                'pc' => $this->t($xp, 'contact:addr/contact:pc', $p),
                'cc' => $this->t($xp, 'contact:addr/contact:cc', $p),
            ];
        }
        return [
            'postal' => $postal,
            'voice' => $this->t($xp, 'contact:voice', $node),
            'fax' => $this->t($xp, 'contact:fax', $node),
            'email' => $this->t($xp, 'contact:email', $node),
        ];
    }

    private function onCreateContact($xp, $cmd, $obj)
    {
        $id = $this->t($xp, 'contact:id', $obj);
        if (isset($this->contacts[$id])) {
            return $this->error(2302, 'Object exists');
        }
        $this->addContact($id, $this->contactFrom($xp, $obj));
        return $this->respond(1000, 'Command completed successfully', '<contact:creData xmlns:contact="' . self::NS['contact'] . '">'
            . '<contact:id>' . self::esc($id) . '</contact:id><contact:crDate>2026-11-14T10:00:00.0Z</contact:crDate></contact:creData>');
    }

    private function onUpdateContact($xp, $cmd, $obj)
    {
        $id = $this->t($xp, 'contact:id', $obj);
        if (!isset($this->contacts[$id])) {
            return $this->error(2303, 'Object does not exist');
        }
        $chg = $xp->query('contact:chg', $obj)->item(0);
        $new = $this->contactFrom($xp, $chg);
        $c = $this->contacts[$id];
        if ($new['postal']) {
            $c['postal'] = $new['postal'];
        }
        foreach (['voice', 'fax', 'email'] as $k) {
            if ($xp->query('contact:' . $k, $chg)->length) {
                $c[$k] = $new[$k];
            }
        }
        $this->contacts[$id] = $c;
        return $this->respond(1000, 'Command completed successfully');
    }

    private function onDeleteContact($xp, $cmd, $obj)
    {
        $id = $this->t($xp, 'contact:id', $obj);
        if (!isset($this->contacts[$id])) {
            return $this->error(2303, 'Object does not exist');
        }
        unset($this->contacts[$id]);
        return $this->respond(1000, 'Command completed successfully');
    }

    private function onInfoContact($xp, $cmd, $obj)
    {
        $id = $this->t($xp, 'contact:id', $obj);
        if (!isset($this->contacts[$id])) {
            return $this->error(2303, 'Object does not exist');
        }
        $c = $this->contacts[$id];
        $x = '<contact:infData xmlns:contact="' . self::NS['contact'] . '"><contact:id>' . self::esc($id) . '</contact:id>'
            . '<contact:roid>C' . abs(crc32($id)) . '-IN</contact:roid>';
        foreach ($c['status'] as $s) {
            $x .= '<contact:status s="' . $s . '"/>';
        }
        foreach ($c['postal'] as $p) {
            $x .= '<contact:postalInfo type="' . $p['type'] . '"><contact:name>' . self::esc($p['name']) . '</contact:name>';
            if ($p['org'] !== '') {
                $x .= '<contact:org>' . self::esc($p['org']) . '</contact:org>';
            }
            $x .= '<contact:addr>';
            foreach ($p['street'] as $s) {
                $x .= '<contact:street>' . self::esc($s) . '</contact:street>';
            }
            $x .= '<contact:city>' . self::esc($p['city']) . '</contact:city>';
            if ($p['sp'] !== '') {
                $x .= '<contact:sp>' . self::esc($p['sp']) . '</contact:sp>';
            }
            if ($p['pc'] !== '') {
                $x .= '<contact:pc>' . self::esc($p['pc']) . '</contact:pc>';
            }
            $x .= '<contact:cc>' . $p['cc'] . '</contact:cc></contact:addr></contact:postalInfo>';
        }
        if ($c['voice'] !== '') {
            $x .= '<contact:voice>' . $c['voice'] . '</contact:voice>';
        }
        if ($c['fax'] !== '') {
            $x .= '<contact:fax>' . $c['fax'] . '</contact:fax>';
        }
        $x .= '<contact:email>' . self::esc($c['email']) . '</contact:email>'
            . '<contact:clID>' . self::esc($this->clID) . '</contact:clID><contact:crID>' . self::esc($this->clID) . '</contact:crID>'
            . '<contact:crDate>2024-01-10T10:00:00.0Z</contact:crDate>';
        if (!empty($c['authInfo'])) {
            $x .= '<contact:authInfo><contact:pw>' . self::esc($c['authInfo']) . '</contact:pw></contact:authInfo>';
        }
        $x .= '</contact:infData>';
        return $this->respond(1000, 'Command completed successfully', $x);
    }

    // --- host -----------------------------------------------------------

    private function onCheckHost($xp, $cmd, $obj)
    {
        $cds = '';
        foreach ($this->all($xp, 'host:name', $obj) as $name) {
            $avail = !isset($this->hosts[strtolower($name)]);
            $cds .= '<host:cd><host:name avail="' . ($avail ? '1' : '0') . '">' . self::esc($name) . '</host:name></host:cd>';
        }
        return $this->respond(1000, 'Command completed successfully', '<host:chkData xmlns:host="' . self::NS['host'] . '">' . $cds . '</host:chkData>');
    }

    private function onCreateHost($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'host:name', $obj));
        if (isset($this->hosts[$name])) {
            return $this->error(2302, 'Object exists');
        }
        $this->addHost($name, $this->all($xp, 'host:addr', $obj));
        return $this->respond(1000, 'Command completed successfully', '<host:creData xmlns:host="' . self::NS['host'] . '">'
            . '<host:name>' . self::esc($name) . '</host:name><host:crDate>2026-11-14T10:00:00.0Z</host:crDate></host:creData>');
    }

    private function onInfoHost($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'host:name', $obj));
        if (!isset($this->hosts[$name])) {
            return $this->error(2303, 'Object does not exist');
        }
        $x = '<host:infData xmlns:host="' . self::NS['host'] . '"><host:name>' . self::esc($name) . '</host:name>'
            . '<host:roid>H' . abs(crc32($name)) . '-IN</host:roid><host:status s="ok"/>';
        foreach ($this->hosts[$name]['addr'] as $a) {
            $x .= '<host:addr ip="' . (strpos($a, ':') !== false ? 'v6' : 'v4') . '">' . $a . '</host:addr>';
        }
        $x .= '<host:clID>' . self::esc($this->clID) . '</host:clID><host:crID>' . self::esc($this->clID) . '</host:crID>'
            . '<host:crDate>2024-01-10T10:00:00.0Z</host:crDate></host:infData>';
        return $this->respond(1000, 'Command completed successfully', $x);
    }

    private function onUpdateHost($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'host:name', $obj));
        if (!isset($this->hosts[$name])) {
            return $this->error(2303, 'Object does not exist');
        }
        $addr = array_values(array_diff($this->hosts[$name]['addr'], $this->all($xp, 'host:rem/host:addr', $obj)));
        $this->hosts[$name]['addr'] = array_merge($addr, $this->all($xp, 'host:add/host:addr', $obj));
        return $this->respond(1000, 'Command completed successfully');
    }

    private function onDeleteHost($xp, $cmd, $obj)
    {
        $name = strtolower($this->t($xp, 'host:name', $obj));
        if (!isset($this->hosts[$name])) {
            return $this->error(2303, 'Object does not exist');
        }
        unset($this->hosts[$name]);
        return $this->respond(1000, 'Command completed successfully');
    }
}
