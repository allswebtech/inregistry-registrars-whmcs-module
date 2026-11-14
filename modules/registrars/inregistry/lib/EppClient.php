<?php

namespace AllsWeb\InRegistry;

/**
 * An EPP error result (code >= 2000) or a transport/protocol failure.
 * getCode() is the EPP result code, or 0 when the registry sent none.
 */
class EppException extends \Exception
{
}

/**
 * A parsed EPP document. Queries use fixed prefixes bound to namespace URIs,
 * so parsing does not depend on the prefixes (or default namespace) the
 * server chose.
 */
class EppResponse
{
    const NAMESPACES = [
        'epp' => 'urn:ietf:params:xml:ns:epp-1.0',
        'domain' => 'urn:ietf:params:xml:ns:domain-1.0',
        'contact' => 'urn:ietf:params:xml:ns:contact-1.0',
        'host' => 'urn:ietf:params:xml:ns:host-1.0',
        'rgp' => 'urn:ietf:params:xml:ns:rgp-1.0',
        'secDNS' => 'urn:ietf:params:xml:ns:secDNS-1.1',
        'loginSec' => 'urn:ietf:params:xml:ns:epp:loginSec-1.0',
    ];

    /** @var \DOMDocument */
    public $doc;
    /** @var \DOMXPath */
    public $xpath;
    public $code = 0;
    public $msg = '';
    public $lang = 'en';

    /**
     * @throws EppException when the document is empty or not well-formed EPP XML
     */
    public static function parse($xml)
    {
        $xml = (string) $xml;
        if (trim($xml) === '') {
            throw new EppException('Empty response from the registry');
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$doc->documentElement) {
            throw new EppException('Unparsable response from the registry');
        }
        if ($doc->doctype !== null) {
            throw new EppException('Registry response contains a DTD; refused');
        }
        if ($doc->documentElement->namespaceURI !== self::NAMESPACES['epp'] || $doc->documentElement->localName !== 'epp') {
            throw new EppException('Registry response is not an EPP document');
        }

        $res = new self();
        $res->doc = $doc;
        $res->xpath = new \DOMXPath($doc);
        foreach (self::NAMESPACES as $prefix => $uri) {
            $res->xpath->registerNamespace($prefix, $uri);
        }
        return $res;
    }

    /** @return \DOMElement[] */
    public function nodes($query, $context = null)
    {
        $list = $context === null ? $this->xpath->query($query) : $this->xpath->query($query, $context);
        $out = [];
        if ($list !== false) {
            foreach ($list as $node) {
                $out[] = $node;
            }
        }
        return $out;
    }

    public function node($query, $context = null)
    {
        $nodes = $this->nodes($query, $context);
        return $nodes ? $nodes[0] : null;
    }

    public function text($query, $context = null, $default = '')
    {
        $node = $this->node($query, $context);
        return $node === null ? $default : trim($node->textContent);
    }

    public function texts($query, $context = null)
    {
        $out = [];
        foreach ($this->nodes($query, $context) as $node) {
            $value = trim($node->textContent);
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return $out;
    }

    public function attr($query, $attribute, $context = null, $default = '')
    {
        $node = $this->node($query, $context);
        return ($node instanceof \DOMElement && $node->hasAttribute($attribute)) ? $node->getAttribute($attribute) : $default;
    }

    public function meta()
    {
        return ['code' => $this->code, 'msg' => $this->msg, 'lang' => $this->lang];
    }
}

/**
 * EPP client (RFC 5730-5734, RFC 3915, RFC 5910, RFC 8807) for the .IN registry.
 */
class EppClient
{
    const NS_EPP = 'urn:ietf:params:xml:ns:epp-1.0';
    const NS_DOMAIN = 'urn:ietf:params:xml:ns:domain-1.0';
    const NS_CONTACT = 'urn:ietf:params:xml:ns:contact-1.0';
    const NS_HOST = 'urn:ietf:params:xml:ns:host-1.0';
    const NS_SECDNS = 'urn:ietf:params:xml:ns:secDNS-1.1';
    const NS_RGP = 'urn:ietf:params:xml:ns:rgp-1.0';
    const NS_IDN = 'urn:ietf:params:xml:ns:idn-1.0';
    const NS_LOGINSEC = 'urn:ietf:params:xml:ns:epp:loginSec-1.0';

    /** Extensions this module can use; login declares those the server also offers. */
    const KNOWN_EXTENSIONS = [
        'urn:ietf:params:xml:ns:secDNS-1.1',
        'urn:ietf:params:xml:ns:rgp-1.0',
        'urn:ietf:params:xml:ns:idn-1.0',
        'urn:ietf:params:xml:ns:epp:loginSec-1.0',
    ];

    const MAX_FRAME_BYTES = 16777216; // 16 MB sanity limit for one EPP frame

    protected $config;
    protected $params = [];
    protected $socket = null;
    protected $isLogined = false;
    protected $greeting = ['parsed' => false, 'svID' => '', 'objURI' => [], 'extURI' => []];
    protected $loginExtensions = [];
    protected $secrets = [];

    public function __construct($config)
    {
        $this->config = $config;
        $this->connect();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function getConfig($key, $default = null)
    {
        if (isset($this->config[$key])) {
            return $this->config[$key];
        }
        if (func_num_args() > 1) {
            return $default;
        }
        throw new EppException("Configuration Undeclared Parameters: {$key}");
    }

    public function getParams($key)
    {
        if (isset($this->params[$key])) {
            return $this->params[$key];
        }
        throw new EppException("Undeclared Parameters: {$key}");
    }

    public function setParams($params)
    {
        $this->params = (array) $params;
    }

    /** Parsed server greeting: ['parsed' => bool, 'svID' => string, 'objURI' => [], 'extURI' => []]. */
    public function getGreeting()
    {
        return $this->greeting;
    }

    /** Extension URIs declared at login (greeting ∩ KNOWN_EXTENSIONS). */
    public function getLoginExtensions()
    {
        return $this->loginExtensions;
    }

    public function isLoggedIn()
    {
        return $this->isLogined;
    }

    // ------------------------------------------------------------------
    // Transport (RFC 5734)
    // ------------------------------------------------------------------

    public function connect($timeout = null)
    {
        $timeout = $timeout === null ? (int) $this->getConfig('connect_timeout', 30) : (int) $timeout;
        $this->socket = $this->openSocket($timeout);
        $this->greeting = self::parseGreeting($this->readFrame());
        return true;
    }

    /**
     * Opens the TLS connection. Overridden in tests to use a scripted transport.
     *
     * @return resource
     */
    protected function openSocket($timeout)
    {
        $host = (string) $this->getConfig('host');
        $port = (int) $this->getConfig('port', 700);

        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        $ssl = [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'crypto_method' => (int) $this->getConfig('crypto_method', $crypto),
        ];

        $cert = trim((string) $this->getConfig('local_cert', ''));
        $key = trim((string) $this->getConfig('local_pk', ''));
        $passphrase = (string) $this->getConfig('passphrase', '');
        if ($cert !== '') {
            $ssl['local_cert'] = $cert;
        }
        if ($key !== '') {
            $ssl['local_pk'] = $key;
        }
        if ($passphrase !== '') {
            $ssl['passphrase'] = $passphrase;
        }

        // The registry uses a private CA: verify only when its CA file is configured.
        $cafile = trim((string) $this->getConfig('cafile', ''));
        if ($cafile !== '' && is_readable($cafile)) {
            $ssl['verify_peer'] = true;
            $ssl['verify_peer_name'] = true;
            $ssl['allow_self_signed'] = false;
            $ssl['cafile'] = $cafile;
        } else {
            $ssl['verify_peer'] = false;
            $ssl['verify_peer_name'] = false;
            $ssl['allow_self_signed'] = true;
        }

        $target = "tls://{$host}:{$port}";
        $context = stream_context_create(['ssl' => $ssl]);

        $warnings = [];
        set_error_handler(function ($errno, $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        });
        try {
            $socket = stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($socket)) {
            $reasons = [];
            foreach (array_merge([(string) $errstr], $warnings) as $reason) {
                $reasons[] = $reason;
            }
            if (function_exists('openssl_error_string')) {
                while (($sslError = openssl_error_string()) !== false) {
                    $reasons[] = $sslError;
                }
            }
            $clean = [];
            foreach ($reasons as $reason) {
                $reason = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) $reason, ENT_QUOTES, 'UTF-8'))));
                if ($reason !== '' && !in_array($reason, $clean, true)) {
                    $clean[] = $reason;
                }
            }
            throw new EppException(
                "Could not connect to {$target}" . ($clean ? ': ' . implode('; ', $clean) : '') . " (error {$errno})"
            );
        }

        stream_set_timeout($socket, (int) $this->getConfig('timeout', 60));
        return $socket;
    }

    /** Reads at most $length bytes; returns a non-empty string or throws. */
    protected function readChunk($length)
    {
        if (!is_resource($this->socket)) {
            throw new EppException('Not connected to the registry');
        }
        $data = @fread($this->socket, $length);
        if ($data === false || $data === '') {
            $meta = @stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) {
                throw new EppException('Timed out waiting for the registry response');
            }
            throw new EppException('Connection closed by the registry');
        }
        return $data;
    }

    /** Writes part of $data; returns the number of bytes written (> 0) or throws. */
    protected function writeChunk($data)
    {
        if (!is_resource($this->socket)) {
            throw new EppException('Not connected to the registry');
        }
        $written = @fwrite($this->socket, $data);
        if ($written === false || $written === 0) {
            $meta = @stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) {
                throw new EppException('Timed out writing to the registry');
            }
            throw new EppException('Error writing to the registry connection');
        }
        return $written;
    }

    protected function readBytes($length)
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $buffer .= $this->readChunk(min(65536, $length - strlen($buffer)));
        }
        return $buffer;
    }

    protected function writeAll($data)
    {
        $data = (string) $data;
        while ($data !== '') {
            $written = (int) $this->writeChunk($data);
            if ($written <= 0) {
                throw new EppException('Error writing to the registry connection');
            }
            $data = (string) substr($data, $written);
        }
    }

    /** Reads one EPP data unit: 4-byte big-endian total length, then the XML. */
    protected function readFrame()
    {
        $header = $this->readBytes(4);
        $unpacked = unpack('N', $header);
        $length = (int) $unpacked[1];
        if ($length < 4 || $length > self::MAX_FRAME_BYTES) {
            throw new EppException("Invalid EPP frame length {$length} from the registry");
        }
        return $length === 4 ? '' : $this->readBytes($length - 4);
    }

    protected function writeFrame($xml)
    {
        $this->writeAll(pack('N', strlen($xml) + 4) . $xml);
    }

    /** Closes the connection quietly; never throws. */
    public function disconnect()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        return true;
    }

    /**
     * Parses a <greeting>; on any problem returns 'parsed' => false (and then
     * login declares no extensions).
     */
    public static function parseGreeting($xml)
    {
        $greeting = ['parsed' => false, 'svID' => '', 'objURI' => [], 'extURI' => []];
        try {
            $res = EppResponse::parse($xml);
        } catch (\Exception $e) {
            return $greeting;
        }
        $node = $res->node('/epp:epp/epp:greeting');
        if ($node === null) {
            return $greeting;
        }
        $greeting['parsed'] = true;
        $greeting['svID'] = $res->text('epp:svID', $node);
        $greeting['objURI'] = $res->texts('epp:svcMenu/epp:objURI', $node);
        $greeting['extURI'] = $res->texts('epp:svcMenu/epp:svcExtension/epp:extURI', $node);
        return $greeting;
    }

    /** greeting extURIs ∩ KNOWN_EXTENSIONS, in KNOWN_EXTENSIONS order. */
    public function negotiableExtensions()
    {
        if (empty($this->greeting['parsed'])) {
            return [];
        }
        return array_values(array_intersect(self::KNOWN_EXTENSIONS, $this->greeting['extURI']));
    }

    // ------------------------------------------------------------------
    // Command plumbing
    // ------------------------------------------------------------------

    /** Unique, token-safe client transaction ID (3-64 chars). */
    protected function newClTRID($action)
    {
        $prefix = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->getConfig('identityprefix', '')), 0, 8);
        $action = substr(preg_replace('/[^A-Za-z0-9\-]/', '', (string) $action), 0, 30);
        $id = ($prefix !== '' ? $prefix . '-' : '') . $action . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        return substr($id, 0, 64);
    }

    /**
     * Literal template substitution. $values are XML-escaped, $raw are
     * pre-built XML fragments. Each tag listed in $optional is removed when
     * it ended up empty (listed children before parents).
     */
    protected function render($action, $template, array $values = [], array $raw = [], array $optional = [])
    {
        $map = ['{{ clTRID }}' => $this->newClTRID($action)];
        foreach ($values as $key => $value) {
            $map['{{ ' . $key . ' }}'] = ok_epp_xmlEscape(trim((string) $value));
        }
        foreach ($raw as $key => $value) {
            $map['{{ ' . $key . ' }}'] = (string) $value;
        }
        $xml = strtr($template, $map);
        foreach ($optional as $tag) {
            $quoted = preg_quote($tag, '#');
            $xml = preg_replace('#<' . $quoted . '>\s*</' . $quoted . '>\s*#', '', $xml);
        }
        return $xml;
    }

    /** One <tag>escaped</tag> per non-empty value. */
    protected function tags($tag, $values, $transform = null)
    {
        $xml = '';
        foreach ((array) $values as $value) {
            $value = trim((string) $value);
            if ($transform !== null) {
                $value = call_user_func($transform, $value);
            }
            if ($value !== '') {
                $xml .= '<' . $tag . '>' . ok_epp_xmlEscape($value) . '</' . $tag . '>';
            }
        }
        return $xml;
    }

    protected function requireExtension($uri)
    {
        if (!in_array($uri, $this->loginExtensions, true)) {
            throw new EppException("The registry did not offer the EPP extension {$uri} for this session");
        }
    }

    /**
     * Sends one command and returns the parsed response.
     *
     * @throws EppException on transport errors, unparsable responses and EPP error results
     */
    protected function request($command, $action)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n"
            . '<epp xmlns="' . self::NS_EPP . '">' . $command . '</epp>';

        try {
            $this->writeFrame($xml);
            $raw = $this->readFrame();
        } catch (\Exception $e) {
            ok_epp_modulelog($xml, 'Transport error: ' . $e->getMessage(), $action, $this->secrets);
            throw $e;
        }
        ok_epp_modulelog($xml, $raw, $action, $this->secrets);

        $res = EppResponse::parse($raw);
        $results = $res->nodes('/epp:epp/epp:response/epp:result');
        if (!$results) {
            throw new EppException('Registry response has no result');
        }

        $errors = [];
        $errorCode = 0;
        foreach ($results as $result) {
            $code = (int) $result->getAttribute('code');
            if ($code < 2000) {
                continue;
            }
            if ($errorCode === 0) {
                $errorCode = $code;
            }
            $details = $res->texts('epp:extValue/epp:reason', $result);
            foreach ($res->nodes('epp:value | epp:extValue/epp:value', $result) as $value) {
                $first = null;
                foreach ($value->childNodes as $child) {
                    if ($child instanceof \DOMElement) {
                        $first = $child;
                        break;
                    }
                }
                if ($first !== null && in_array($first->localName, ['pw', 'newPW'], true)) {
                    continue; // never echo secrets into an error message
                }
                $text = trim(preg_replace('/\s+/', ' ', $value->textContent));
                if ($text !== '') {
                    $details[] = $text;
                }
            }
            $msg = trim(preg_replace('/\s+/', ' ', $res->text('epp:msg', $result)));
            if ($msg === '') {
                $msg = 'Registry error';
            }
            $details = array_values(array_unique(array_filter($details, 'strlen')));
            $errors[] = $msg . ($details ? ': ' . implode('; ', $details) : '') . " (EPP {$code})";
        }
        if ($errors) {
            throw new EppException(implode(' | ', $errors), $errorCode);
        }

        $first = $results[0];
        $res->code = (int) $first->getAttribute('code');
        $res->msg = $res->text('epp:msg', $first);
        $res->lang = $res->attr('epp:msg', 'lang', $first, 'en');
        return $res;
    }

    protected function assertDomain($domainName)
    {
        if (!is_string($domainName) || trim($domainName) === '') {
            throw new EppException('Invalid Domain Type.');
        }
    }

    // ------------------------------------------------------------------
    // Session
    // ------------------------------------------------------------------

    public function login($usr, $pwd, $newPwd = null)
    {
        $extensions = $this->negotiableExtensions();
        $loginSec = in_array(self::NS_LOGINSEC, $extensions, true);

        $pwd = (string) $pwd;
        $pwLong = ok_epp_strlen($pwd) > 16;
        $newLong = $newPwd !== null && ok_epp_strlen((string) $newPwd) > 16;
        $this->secrets = array_values(array_filter([$pwd, (string) $newPwd], 'strlen'));

        // RFC 8807: with loginSec offered, a password longer than 16 characters goes in
        // the extension. Without it the classic <pw> is sent whatever the length
        // (RFC 5730 allows 16) and the registry decides.
        $pwXml = ($loginSec && $pwLong) ? '[LOGIN-SECURITY]' : ok_epp_xmlEscape($pwd);
        $newPwXml = '';
        if ($newPwd !== null) {
            $newPwXml = '<newPW>' . (($loginSec && $newLong) ? '[LOGIN-SECURITY]' : ok_epp_xmlEscape($newPwd)) . '</newPW>';
        }

        $svcExtension = '';
        if ($extensions) {
            $svcExtension = '<svcExtension>' . $this->tags('extURI', $extensions) . '</svcExtension>';
        }

        $extension = '';
        if ($loginSec && ($pwLong || $newLong)) {
            $extension = '<extension><loginSec:loginSec xmlns:loginSec="' . self::NS_LOGINSEC . '">'
                . '<loginSec:userAgent>'
                . '<loginSec:app>' . ok_epp_xmlEscape('AllsWeb inregistry-whmcs-module ' . VERSION) . '</loginSec:app>'
                . '<loginSec:tech>' . ok_epp_xmlEscape('PHP ' . PHP_VERSION) . '</loginSec:tech>'
                . '</loginSec:userAgent>'
                . ($pwLong ? '<loginSec:pw>' . ok_epp_xmlEscape($pwd) . '</loginSec:pw>' : '')
                . ($newLong ? '<loginSec:newPW>' . ok_epp_xmlEscape($newPwd) . '</loginSec:newPW>' : '')
                . '</loginSec:loginSec></extension>';
        }

        $xml = $this->render('login', '<command><login>'
            . '<clID>{{ clID }}</clID><pw>{{ pw }}</pw>{{ newPW }}'
            . '<options><version>1.0</version><lang>en</lang></options>'
            . '<svcs>'
            . '<objURI>' . self::NS_DOMAIN . '</objURI>'
            . '<objURI>' . self::NS_CONTACT . '</objURI>'
            . '<objURI>' . self::NS_HOST . '</objURI>'
            . '{{ svcExtension }}'
            . '</svcs></login>{{ extension }}<clTRID>{{ clTRID }}</clTRID></command>', ['clID' => $usr], [
            'pw' => $pwXml,
            'newPW' => $newPwXml,
            'svcExtension' => $svcExtension,
            'extension' => $extension,
        ]);

        $this->request($xml, 'login');
        $this->isLogined = true;
        $this->loginExtensions = $extensions;
        return true;
    }

    public function changePassword($usr, $pwd, $newPwd)
    {
        return $this->login($usr, $pwd, $newPwd);
    }

    /** Ends the session and always closes the socket; never throws. */
    public function logout()
    {
        try {
            if ($this->isLogined) {
                $this->request($this->render('logout', '<command><logout/><clTRID>{{ clTRID }}</clTRID></command>'), 'logout');
            }
        } catch (\Exception $e) {
            // Already logged by request(); the operation before logout stands.
        } catch (\Throwable $e) {
            // Same for PHP errors raised while logging out.
        } finally {
            $this->isLogined = false;
            $this->disconnect();
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Domain (RFC 5731, RFC 3915, RFC 5910)
    // ------------------------------------------------------------------

    public function domainCheck($domainNames)
    {
        $names = $this->tags('domain:name', $domainNames);
        if ($names === '') {
            throw new EppException('No domain name to check');
        }
        $xml = $this->render('domain-check', '<command><check>'
            . '<domain:check xmlns:domain="' . self::NS_DOMAIN . '">{{ names }}</domain:check>'
            . '</check><clTRID>{{ clTRID }}</clTRID></command>', [], ['names' => $names]);

        $r = $this->request($xml, __FUNCTION__);
        $result = [];
        foreach ($r->nodes('//domain:chkData/domain:cd') as $cd) {
            $avail = ok_epp_bool($r->attr('domain:name', 'avail', $cd, '0'));
            $result[] = [
                'name' => $r->text('domain:name', $cd),
                'avail' => $avail,
                'status' => $avail ? 'available' : 'unavailable',
                'reason' => $r->text('domain:reason', $cd),
            ];
        }
        return array_merge($r->meta(), ['domain' => $result]);
    }

    protected function contactsXml($contacts)
    {
        $registrant = '';
        $others = '';
        foreach ((array) $contacts as $type => $id) {
            if (!ok_epp_contactsType($type)) {
                throw new EppException("Invalid contact type: {$type}");
            }
            if (trim((string) $id) === '') {
                continue;
            }
            if ($type === 'registrant') {
                $registrant = '<domain:registrant>' . ok_epp_xmlEscape(trim($id)) . '</domain:registrant>';
            } else {
                $others .= '<domain:contact type="' . $type . '">' . ok_epp_xmlEscape(trim($id)) . '</domain:contact>';
            }
        }
        return $registrant . $others;
    }

    protected function period($periodYr)
    {
        if ($periodYr === null || $periodYr === '' || !is_numeric($periodYr)) {
            return 1;
        }
        $period = (int) $periodYr;
        if ($period < 1 || $period > 99 || (string) $period !== trim((string) $periodYr)) {
            throw new EppException("Invalid period: {$periodYr}");
        }
        return $period;
    }

    protected function buildDomainCreate($domainName, $periodYr, $contacts, $ns, $authInfo, $extension)
    {
        $this->assertDomain($domainName);
        $authInfo = $authInfo === null ? ok_epp_generateObjectPW() : $authInfo;

        return $this->render('domain-create', '<command><create>'
            . '<domain:create xmlns:domain="' . self::NS_DOMAIN . '">'
            . '<domain:name>{{ name }}</domain:name>'
            . '<domain:period unit="y">{{ period }}</domain:period>'
            . '<domain:ns>{{ hostObjs }}</domain:ns>'
            . '{{ contacts }}'
            . '<domain:authInfo><domain:pw>{{ authInfoPw }}</domain:pw></domain:authInfo>'
            . '</domain:create></create>{{ extension }}<clTRID>{{ clTRID }}</clTRID></command>', [
            'name' => $domainName,
            'period' => $this->period($periodYr),
            'authInfoPw' => $authInfo,
        ], [
            'hostObjs' => $this->tags('domain:hostObj', $ns),
            'contacts' => $this->contactsXml($contacts),
            'extension' => $extension,
        ], ['domain:ns']);
    }

    protected function parseCreate(EppResponse $r)
    {
        $cre = $r->node('//domain:creData');
        return array_merge($r->meta(), ['domain' => [
            'success' => true,
            'name' => $r->text('domain:name', $cre),
            'crDate' => $r->text('domain:crDate', $cre),
            'exDate' => $r->text('domain:exDate', $cre),
        ]]);
    }

    public function domainCreate($domainName, $periodYr, $contacts, $ns, $authInfo = null)
    {
        $xml = $this->buildDomainCreate($domainName, $periodYr, $contacts, $ns, $authInfo, '');
        return $this->parseCreate($this->request($xml, __FUNCTION__));
    }

    /**
     * Domain create with DS records (RFC 5910 secDNS:create).
     * $dsRecords: [['keyTag' => .., 'alg' => .., 'digestType' => .., 'digest' => ..], ...]
     */
    public function domainCreateDNSSEC($domainName, $periodYr, $contacts, $ns, array $dsRecords, $authInfo = null)
    {
        $this->requireExtension(self::NS_SECDNS);
        if (!$dsRecords) {
            throw new EppException('At least one DS record is required');
        }
        $extension = '<extension><secDNS:create xmlns:secDNS="' . self::NS_SECDNS . '">'
            . $this->dsDataXml($dsRecords) . '</secDNS:create></extension>';
        $xml = $this->buildDomainCreate($domainName, $periodYr, $contacts, $ns, $authInfo, $extension);
        return $this->parseCreate($this->request($xml, __FUNCTION__));
    }

    protected function dsDataXml(array $records)
    {
        $xml = '';
        foreach ($records as $i => $ds) {
            $keyTag = isset($ds['keyTag']) ? trim((string) $ds['keyTag']) : '';
            $alg = isset($ds['alg']) ? trim((string) $ds['alg']) : '';
            $digestType = isset($ds['digestType']) ? trim((string) $ds['digestType']) : '';
            $digest = isset($ds['digest']) ? preg_replace('/\s+/', '', (string) $ds['digest']) : '';
            if (!ctype_digit($keyTag) || (int) $keyTag > 65535
                || !ctype_digit($alg) || (int) $alg > 255
                || !ctype_digit($digestType) || (int) $digestType > 255
                || $digest === '' || strlen($digest) % 2 !== 0 || !ctype_xdigit($digest)) {
                throw new EppException('Invalid DS record #' . ($i + 1) . ': keyTag 0-65535, alg and digestType 0-255, digest hex');
            }
            $xml .= '<secDNS:dsData>'
                . '<secDNS:keyTag>' . (int) $keyTag . '</secDNS:keyTag>'
                . '<secDNS:alg>' . (int) $alg . '</secDNS:alg>'
                . '<secDNS:digestType>' . (int) $digestType . '</secDNS:digestType>'
                . '<secDNS:digest>' . strtoupper($digest) . '</secDNS:digest>'
                . '</secDNS:dsData>';
        }
        return $xml;
    }

    public function domainTransfer($domainName, $oprationType, $authInfo = '')
    {
        $this->assertDomain($domainName);
        if (!in_array($oprationType, ['request', 'query', 'cancel', 'reject', 'approve'], true)) {
            throw new EppException('Invalid value for transfer:op specified.');
        }
        if ($oprationType === 'request' && trim((string) $authInfo) === '') {
            throw new EppException('Invalid value for authInfo.');
        }

        $extra = '';
        if ($oprationType === 'request') {
            // The registry transfers for one year.
            $extra = '<domain:period unit="y">1</domain:period>'
                . '<domain:authInfo><domain:pw>' . ok_epp_xmlEscape(trim($authInfo)) . '</domain:pw></domain:authInfo>';
        }

        $xml = $this->render('domain-transfer-' . $oprationType, '<command>'
            . '<transfer op="' . $oprationType . '">'
            . '<domain:transfer xmlns:domain="' . self::NS_DOMAIN . '">'
            . '<domain:name>{{ name }}</domain:name>{{ extra }}'
            . '</domain:transfer></transfer><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $domainName], ['extra' => $extra]);

        $r = $this->request($xml, __FUNCTION__);
        $trn = $r->node('//domain:trnData');
        return array_merge($r->meta(), ['domain' => [
            'success' => true,
            'name' => $trn ? $r->text('domain:name', $trn) : $domainName,
            'trStatus' => $trn ? $r->text('domain:trStatus', $trn) : '',
            'reID' => $trn ? $r->text('domain:reID', $trn) : '',
            'reDate' => $trn ? $r->text('domain:reDate', $trn) : '',
            'acID' => $trn ? $r->text('domain:acID', $trn) : '',
            'acDate' => $trn ? $r->text('domain:acDate', $trn) : '',
            'exDate' => $trn ? $r->text('domain:exDate', $trn) : '',
        ]]);
    }

    public function domainRenew($domainName, $periodYr = 1, $exDate = null)
    {
        $this->assertDomain($domainName);
        if ($exDate === null || $exDate === '') {
            $info = $this->domainInfo($domainName);
            $exDate = $info['domain']['exDate'];
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', trim((string) $exDate), $m)) {
            throw new EppException("Invalid current expiry date: {$exDate}");
        }

        $xml = $this->render('domain-renew', '<command><renew>'
            . '<domain:renew xmlns:domain="' . self::NS_DOMAIN . '">'
            . '<domain:name>{{ name }}</domain:name>'
            . '<domain:curExpDate>{{ expDate }}</domain:curExpDate>'
            . '<domain:period unit="y">{{ period }}</domain:period>'
            . '</domain:renew></renew><clTRID>{{ clTRID }}</clTRID></command>', [
            'name' => $domainName,
            'expDate' => $m[1],
            'period' => $this->period($periodYr),
        ]);

        $r = $this->request($xml, __FUNCTION__);
        $ren = $r->node('//domain:renData');
        return array_merge($r->meta(), ['domain' => [
            'success' => true,
            'name' => $ren ? $r->text('domain:name', $ren) : $domainName,
            'exDate' => $ren ? $r->text('domain:exDate', $ren) : '',
        ]]);
    }

    public function domainDelete($domainName)
    {
        $this->assertDomain($domainName);
        $xml = $this->render('domain-delete', '<command><delete>'
            . '<domain:delete xmlns:domain="' . self::NS_DOMAIN . '"><domain:name>{{ name }}</domain:name></domain:delete>'
            . '</delete><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $domainName]);

        $r = $this->request($xml, __FUNCTION__);
        return array_merge($r->meta(), ['domain' => ['success' => true, 'name' => $domainName, 'msg' => 'Domain Deleted']]);
    }

    /** RFC 3915 restore request. */
    public function domainRestore($domainName)
    {
        $this->assertDomain($domainName);
        $this->requireExtension(self::NS_RGP);
        $xml = $this->render('domain-restore', '<command><update>'
            . '<domain:update xmlns:domain="' . self::NS_DOMAIN . '"><domain:name>{{ name }}</domain:name><domain:chg/></domain:update>'
            . '</update><extension>'
            . '<rgp:update xmlns:rgp="' . self::NS_RGP . '"><rgp:restore op="request"/></rgp:update>'
            . '</extension><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $domainName]);

        $r = $this->request($xml, __FUNCTION__);
        return array_merge($r->meta(), ['domain' => [
            'success' => true,
            'name' => $domainName,
            'rgpStatus' => $r->attr('//rgp:upData/rgp:rgpStatus', 's'),
            'rgpDate' => $r->text('//rgp:upData/rgp:rgpStatus'),
            'msg' => 'Domain Restored',
        ]]);
    }

    /**
     * RFC 3915 restore report. Every field is supplied by the caller:
     * preData, postData, delTime, resTime (ISO 8601 date-time), resReason,
     * statements (1 or 2 strings), other (optional).
     */
    public function domainRestoreReport($domainName, array $report)
    {
        $this->assertDomain($domainName);
        $this->requireExtension(self::NS_RGP);

        foreach (['preData', 'postData', 'delTime', 'resTime', 'resReason'] as $key) {
            if (!isset($report[$key]) || trim((string) $report[$key]) === '') {
                throw new EppException("Missing restore report field: {$key}");
            }
        }
        foreach (['delTime', 'resTime'] as $key) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+\-]\d{2}:\d{2})$/', trim($report[$key]))) {
                throw new EppException("Invalid restore report {$key}: expected an ISO 8601 date-time");
            }
        }
        $statements = array_values(array_filter(array_map('trim', (array) ($report['statements'] ?? [])), 'strlen'));
        if (count($statements) < 1 || count($statements) > 2) {
            throw new EppException('A restore report needs one or two statements');
        }
        $other = trim((string) ($report['other'] ?? ''));

        $xml = $this->render('domain-restoreReport', '<command><update>'
            . '<domain:update xmlns:domain="' . self::NS_DOMAIN . '"><domain:name>{{ name }}</domain:name><domain:chg/></domain:update>'
            . '</update><extension>'
            . '<rgp:update xmlns:rgp="' . self::NS_RGP . '"><rgp:restore op="report"><rgp:report>'
            . '<rgp:preData>{{ preData }}</rgp:preData>'
            . '<rgp:postData>{{ postData }}</rgp:postData>'
            . '<rgp:delTime>{{ delTime }}</rgp:delTime>'
            . '<rgp:resTime>{{ resTime }}</rgp:resTime>'
            . '<rgp:resReason>{{ resReason }}</rgp:resReason>'
            . '{{ statements }}'
            . '<rgp:other>{{ other }}</rgp:other>'
            . '</rgp:report></rgp:restore></rgp:update>'
            . '</extension><clTRID>{{ clTRID }}</clTRID></command>', [
            'name' => $domainName,
            'preData' => $report['preData'],
            'postData' => $report['postData'],
            'delTime' => $report['delTime'],
            'resTime' => $report['resTime'],
            'resReason' => $report['resReason'],
            'other' => $other,
        ], ['statements' => $this->tags('rgp:statement', $statements)], ['rgp:other']);

        $r = $this->request($xml, __FUNCTION__);
        return array_merge($r->meta(), ['domain' => ['success' => true, 'name' => $domainName, 'msg' => 'Domain Restore Report']]);
    }

    public function domainInfo($domainName, $authInfo = null)
    {
        $this->assertDomain($domainName);
        $auth = '';
        if ($authInfo !== null && trim((string) $authInfo) !== '') {
            $auth = '<domain:authInfo><domain:pw>' . ok_epp_xmlEscape(trim($authInfo)) . '</domain:pw></domain:authInfo>';
        }
        $xml = $this->render('domain-info', '<command><info>'
            . '<domain:info xmlns:domain="' . self::NS_DOMAIN . '">'
            . '<domain:name hosts="all">{{ name }}</domain:name>{{ authInfo }}'
            . '</domain:info></info><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $domainName], ['authInfo' => $auth]);

        $r = $this->request($xml, __FUNCTION__);
        $d = $r->node('//domain:infData');
        if ($d === null) {
            throw new EppException('Registry response has no domain information');
        }

        $status = [];
        foreach ($r->nodes('domain:status', $d) as $node) {
            if ($node->getAttribute('s') !== '') {
                $status[] = $node->getAttribute('s');
            }
        }

        $contact = [];
        $contacts = [];
        foreach ($r->nodes('domain:contact', $d) as $node) {
            $type = $node->getAttribute('type');
            $id = trim($node->textContent);
            $contacts[] = ['type' => $type, 'id' => $id];
            if ($type !== '' && !isset($contact[$type])) {
                $contact[$type] = $id;
            }
        }

        $ns = array_merge(
            $r->texts('domain:ns/domain:hostObj', $d),
            $r->texts('domain:ns/domain:hostAttr/domain:hostName', $d)
        );
        sort($ns);
        $host = $r->texts('domain:host', $d);
        sort($host);

        $rgpStatus = [];
        foreach ($r->nodes('//epp:extension/rgp:infData/rgp:rgpStatus') as $node) {
            if ($node->getAttribute('s') !== '') {
                $rgpStatus[] = $node->getAttribute('s');
            }
        }

        return array_merge($r->meta(), ['domain' => [
            'name' => $r->text('domain:name', $d),
            'roid' => $r->text('domain:roid', $d),
            'status' => $status,
            'registrant' => $r->text('domain:registrant', $d),
            'contact' => $contact,
            'contacts' => $contacts,
            'ns' => $ns,
            'host' => $host,
            'clID' => $r->text('domain:clID', $d),
            'crID' => $r->text('domain:crID', $d),
            'upID' => $r->text('domain:upID', $d),
            'upDate' => $r->text('domain:upDate', $d),
            'crDate' => $r->text('domain:crDate', $d),
            'exDate' => $r->text('domain:exDate', $d),
            'trDate' => $r->text('domain:trDate', $d),
            'authInfo' => $r->text('domain:authInfo/domain:pw', $d),
            'rgpStatus' => $rgpStatus,
        ]]);
    }

    protected function noChange($key, $name, $msg)
    {
        return ['code' => 1000, 'msg' => 'No change', 'lang' => 'en', $key => ['success' => true, 'name' => $name, 'msg' => $msg]];
    }

    protected function domainUpdate($action, $domainName, $add, $rem, $chg)
    {
        return $this->render($action, '<command><update>'
            . '<domain:update xmlns:domain="' . self::NS_DOMAIN . '">'
            . '<domain:name>{{ name }}</domain:name>{{ add }}{{ rem }}{{ chg }}'
            . '</domain:update></update><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $domainName], [
            'add' => $add === '' ? '' : '<domain:add>' . $add . '</domain:add>',
            'rem' => $rem === '' ? '' : '<domain:rem>' . $rem . '</domain:rem>',
            'chg' => $chg === '' ? '' : '<domain:chg>' . $chg . '</domain:chg>',
        ]);
    }

    public function domainUpdateNS($domainName, $addNS = null, $remNS = null)
    {
        $this->assertDomain($domainName);
        $add = $this->tags('domain:hostObj', $addNS);
        $rem = $this->tags('domain:hostObj', $remNS);
        if ($add === '' && $rem === '') {
            return $this->noChange('domain', $domainName, 'Domain NS unchanged');
        }
        $xml = $this->domainUpdate(
            'domain-updateNS',
            $domainName,
            $add === '' ? '' : '<domain:ns>' . $add . '</domain:ns>',
            $rem === '' ? '' : '<domain:ns>' . $rem . '</domain:ns>',
            ''
        );
        $r = $this->request($xml, __FUNCTION__);
        return array_merge($r->meta(), ['domain' => ['success' => true, 'name' => $domainName, 'msg' => 'Domain NS Updated']]);
    }

    /**
     * After an incoming transfer: one new contact for every role, a new
     * auth code, and clientTransferProhibited.
     */
    public function domainTransferUpdate($domainName, $newContactId, $authInfo = null)
    {
        $this->assertDomain($domainName);
        $dInfo = $this->domainInfo($domainName);
        if (in_array('pendingTransfer', $dInfo['domain']['status'], true)) {
            throw new EppException('Invalid Request: transfer still pending');
        }
        $authInfo = $authInfo === null ? ok_epp_generateObjectPW() : $authInfo;
        $newId = ok_epp_xmlEscape(trim((string) $newContactId));

        $addTypes = [];
        $rem = '';
        foreach ($dInfo['domain']['contacts'] as $c) {
            if (!in_array($c['type'], ['admin', 'tech', 'billing'], true) || $c['id'] === trim((string) $newContactId)) {
                continue;
            }
            $addTypes[$c['type']] = true;
            $rem .= '<domain:contact type="' . $c['type'] . '">' . ok_epp_xmlEscape($c['id']) . '</domain:contact>';
        }
        $add = '';
        foreach (array_keys($addTypes) as $type) {
            $add .= '<domain:contact type="' . $type . '">' . $newId . '</domain:contact>';
        }
        if (!in_array('clientTransferProhibited', $dInfo['domain']['status'], true)) {
            $add .= '<domain:status s="clientTransferProhibited"/>';
        }
        $chg = '<domain:registrant>' . $newId . '</domain:registrant>'
            . '<domain:authInfo><domain:pw>' . ok_epp_xmlEscape($authInfo) . '</domain:pw></domain:authInfo>';

        $r = $this->request($this->domainUpdate('domain-transferUpdate', $domainName, $add, $rem, $chg), __FUNCTION__);
        return array_merge($r->meta(), ['domain' => [
            'success' => true,
            'name' => $domainName,
            'authInfo' => $authInfo,
            'msg' => 'Domain Contact Updated',
        ]]);
    }

    public function domainUpdateContact($domainName, $contactType, $newContactId = null, $oldContactId = null)
    {
        if (!is_string($domainName) || trim($domainName) === '') {
            throw new EppException('Invalid Domain type');
        }
        if (!ok_epp_contactsType($contactType)) {
            throw new EppException('Invalid Contact type');
        }
        $add = $rem = $chg = '';
        if ($contactType === 'registrant') {
            if (trim((string) $newContactId) === '') {
                throw new EppException('A new registrant contact ID is required');
            }
            $chg = '<domain:registrant>' . ok_epp_xmlEscape(trim($newContactId)) . '</domain:registrant>';
        } else {
            if (trim((string) $newContactId) !== '') {
                $add = '<domain:contact type="' . $contactType . '">' . ok_epp_xmlEscape(trim($newContactId)) . '</domain:contact>';
            }
            if (trim((string) $oldContactId) !== '') {
                $rem = '<domain:contact type="' . $contactType . '">' . ok_epp_xmlEscape(trim($oldContactId)) . '</domain:contact>';
            }
        }
        if ($add === '' && $rem === '' && $chg === '') {
            return $this->noChange('domain', $domainName, 'Domain Contact unchanged');
        }
        $r = $this->request($this->domainUpdate('domain-updateContact', $domainName, $add, $rem, $chg), __FUNCTION__);
        return array_merge($r->meta(), ['domain' => ['success' => true, 'name' => $domainName, 'msg' => 'Domain Contact Updated']]);
    }

    public function domainUpdateStatus($domainName, $status, $command = 'add')
    {
        $this->assertDomain($domainName);
        $statusXml = '';
        foreach (array_unique(array_filter(array_map('trim', (array) $status), 'strlen')) as $s) {
            $statusXml .= '<domain:status s="' . ok_epp_xmlEscape($s) . '"/>';
        }
        if ($statusXml === '') {
            return $this->noChange('domain', $domainName, 'Domain Status unchanged');
        }
        $add = $command === 'add' ? $statusXml : '';
        $rem = $command === 'add' ? '' : $statusXml;
        $r = $this->request($this->domainUpdate('domain-updateStatus', $domainName, $add, $rem, ''), __FUNCTION__);
        return array_merge($r->meta(), ['domain' => ['success' => true, 'name' => $domainName, 'msg' => 'Domain Status Updated']]);
    }

    public function domainUpdateAuthinfo($domainName, $authInfo)
    {
        $this->assertDomain($domainName);
        if (trim((string) $authInfo) === '') {
            throw new EppException('Empty auth code');
        }
        $chg = '<domain:authInfo><domain:pw>' . ok_epp_xmlEscape(trim($authInfo)) . '</domain:pw></domain:authInfo>';
        $r = $this->request($this->domainUpdate('domain-updateAuthinfo', $domainName, '', '', $chg), __FUNCTION__);
        return array_merge($r->meta(), ['domain' => ['success' => true, 'name' => $domainName, 'msg' => 'Domain AuthInfo Updated']]);
    }

    /**
     * RFC 5910 DS-data update. $addDs/$remDs: lists of
     * ['keyTag' => .., 'alg' => .., 'digestType' => .., 'digest' => ..];
     * $removeAll sends <secDNS:rem><secDNS:all>true</secDNS:all></secDNS:rem>.
     */
    public function domainUpdateDNSSEC($domainName, array $addDs = [], array $remDs = [], $removeAll = false)
    {
        $this->assertDomain($domainName);
        $this->requireExtension(self::NS_SECDNS);
        if ($removeAll && $remDs) {
            throw new EppException('Use either removeAll or a list of DS records to remove, not both');
        }
        $rem = '';
        if ($removeAll) {
            $rem = '<secDNS:rem><secDNS:all>true</secDNS:all></secDNS:rem>';
        } elseif ($remDs) {
            $rem = '<secDNS:rem>' . $this->dsDataXml($remDs) . '</secDNS:rem>';
        }
        $add = $addDs ? '<secDNS:add>' . $this->dsDataXml($addDs) . '</secDNS:add>' : '';
        if ($rem === '' && $add === '') {
            return $this->noChange('domain', $domainName, 'Domain DNSSEC unchanged');
        }

        $xml = $this->render('domain-updateDNSSEC', '<command><update>'
            . '<domain:update xmlns:domain="' . self::NS_DOMAIN . '"><domain:name>{{ name }}</domain:name></domain:update>'
            . '</update><extension>'
            . '<secDNS:update xmlns:secDNS="' . self::NS_SECDNS . '">{{ rem }}{{ add }}</secDNS:update>'
            . '</extension><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $domainName], ['rem' => $rem, 'add' => $add]);

        $r = $this->request($xml, __FUNCTION__);
        return array_merge($r->meta(), ['domain' => ['success' => true, 'name' => $domainName, 'msg' => 'Domain DNSSEC Updated']]);
    }

    // ------------------------------------------------------------------
    // Contact (RFC 5733)
    // ------------------------------------------------------------------

    public function contactCheck($contactIdentifier)
    {
        $ids = $this->tags('contact:id', $contactIdentifier, 'strtoupper');
        if ($ids === '') {
            throw new EppException('No contact ID to check');
        }
        $xml = $this->render('contact-check', '<command><check>'
            . '<contact:check xmlns:contact="' . self::NS_CONTACT . '">{{ ids }}</contact:check>'
            . '</check><clTRID>{{ clTRID }}</clTRID></command>', [], ['ids' => $ids]);

        $r = $this->request($xml, __FUNCTION__);
        $result = [];
        foreach ($r->nodes('//contact:chkData/contact:cd') as $cd) {
            $result[] = [
                'id' => $r->text('contact:id', $cd),
                'avail' => ok_epp_bool($r->attr('contact:id', 'avail', $cd, '0')),
                'reason' => $r->text('contact:reason', $cd),
            ];
        }
        return array_merge($r->meta(), ['contact' => $result]);
    }

    public function contactInfo($contactIdentifier)
    {
        if (!is_string($contactIdentifier) || trim($contactIdentifier) === '') {
            throw new EppException('Invalid Contact Type.');
        }
        $xml = $this->render('contact-info', '<command><info>'
            . '<contact:info xmlns:contact="' . self::NS_CONTACT . '"><contact:id>{{ id }}</contact:id></contact:info>'
            . '</info><clTRID>{{ clTRID }}</clTRID></command>', ['id' => $contactIdentifier]);

        $r = $this->request($xml, __FUNCTION__);
        $c = $r->node('//contact:infData');
        if ($c === null) {
            throw new EppException('Registry response has no contact information');
        }

        // Prefer the internationalized (ASCII) form, else the first block.
        $postal = $r->node('contact:postalInfo[@type="int"]', $c);
        if ($postal === null) {
            $postal = $r->node('contact:postalInfo', $c);
        }
        $streets = $postal ? $r->texts('contact:addr/contact:street', $postal) : [];

        $status = [];
        foreach ($r->nodes('contact:status', $c) as $node) {
            if ($node->getAttribute('s') !== '') {
                $status[] = $node->getAttribute('s');
            }
        }

        $addr = [
            'street' => $streets,
            'city' => $postal ? $r->text('contact:addr/contact:city', $postal) : '',
            'state' => $postal ? $r->text('contact:addr/contact:sp', $postal) : '',
            'postal' => $postal ? $r->text('contact:addr/contact:pc', $postal) : '',
            'country' => $postal ? $r->text('contact:addr/contact:cc', $postal) : '',
        ];
        foreach (array_values($streets) as $i => $street) {
            $addr['street' . ($i + 1)] = $street;
        }

        return array_merge($r->meta(), ['contact' => [
            'id' => $r->text('contact:id', $c),
            'roid' => $r->text('contact:roid', $c),
            'status' => $status,
            'postalInfo' => [
                'name' => $postal ? $r->text('contact:name', $postal) : '',
                'org' => $postal ? $r->text('contact:org', $postal) : '',
                'addr' => $addr,
                'type' => $postal ? $postal->getAttribute('type') : '',
            ],
            'voice' => $r->text('contact:voice', $c),
            'fax' => $r->text('contact:fax', $c),
            'email' => $r->text('contact:email', $c),
            'clID' => $r->text('contact:clID', $c),
            'crID' => $r->text('contact:crID', $c),
            'upID' => $r->text('contact:upID', $c),
            'upDate' => $r->text('contact:upDate', $c),
            'crDate' => $r->text('contact:crDate', $c),
            'authInfo' => $r->text('contact:authInfo/contact:pw', $c),
        ]]);
    }

    /**
     * Contact details of a domain in WHMCS form:
     * ['contents' => ['Registrant' => [...], 'Admin' => [...]], 'cId' => ['registrant' => id, ...], 'unqId' => [...]]
     */
    public function domainContactInfo($domainName)
    {
        $this->assertDomain($domainName);
        $dInfo = $this->domainInfo($domainName);

        $contents = [];
        if ($dInfo['domain']['registrant'] !== '') {
            $contents['registrant'] = $dInfo['domain']['registrant'];
        }
        foreach ($dInfo['domain']['contact'] as $type => $id) {
            if ($id !== '') {
                $contents[$type] = $id;
            }
        }

        $ucIds = array_values(array_unique(array_values($contents)));
        $cidData = [];
        foreach ($ucIds as $cid) {
            $c = $this->contactInfo($cid)['contact'];
            $cf = [
                'Full Name' => $c['postalInfo']['name'],
                'Email' => $c['email'],
                'Company Name' => $c['postalInfo']['org'],
                'Address 1' => $c['postalInfo']['addr']['street1'] ?? '',
                'Address 2' => $c['postalInfo']['addr']['street2'] ?? '',
            ];
            if (!empty($c['postalInfo']['addr']['street3'])) {
                $cf['Address 3'] = $c['postalInfo']['addr']['street3'];
            }
            $cf['City'] = $c['postalInfo']['addr']['city'];
            $cf['State'] = $c['postalInfo']['addr']['state'];
            $cf['Postcode'] = $c['postalInfo']['addr']['postal'];
            $cf['Country'] = $c['postalInfo']['addr']['country'];
            // Shown exactly as stored (+CC.NUMBER) so a save sends a valid number back.
            $cf['Phone Number'] = $c['voice'];
            $cidData[$cid] = $cf;
        }

        $cccData = [];
        foreach ($contents as $type => $cid) {
            $cccData[ucfirst($type)] = $cidData[$cid];
        }
        return ['contents' => $cccData, 'cId' => $contents, 'unqId' => $ucIds];
    }

    /**
     * Validates and normalises WHMCS-style contact params into escaped
     * template values. Required: name, address1, city, country, email.
     */
    protected function contactValues(array $params)
    {
        $get = function ($key) use ($params) {
            return isset($params[$key]) && is_scalar($params[$key]) ? trim((string) $params[$key]) : '';
        };

        $name = trim(preg_replace('/\s+/', ' ', $get('firstname') . ' ' . $get('lastname')));
        $values = [
            'name' => $name,
            'org' => $get('companyname'),
            'street1' => $get('address1'),
            'street2' => $get('address2'),
            'street3' => $get('address3'),
            'city' => $get('city'),
            'sp' => $get('state'),
            'pc' => $get('postcode'),
            'cc' => strtoupper($get('country')),
            'email' => $get('email'),
        ];
        $labels = ['name' => 'name', 'street1' => 'address 1', 'city' => 'city', 'cc' => 'country', 'email' => 'email'];
        foreach ($labels as $key => $label) {
            if ($values[$key] === '') {
                throw new EppException("Missing required contact field: {$label}");
            }
        }
        if (!preg_match('/^[A-Z]{2}$/', $values['cc'])) {
            throw new EppException("Invalid contact country code: {$values['cc']}");
        }

        $cc = $get('phonecc') !== '' ? $get('phonecc') : $get('Phone Country Code');
        $phone = $get('fullphonenumber') !== '' ? $get('fullphonenumber') : $get('phonenumber');
        // <contact:voice> is optional (RFC 5733): an empty phone is omitted
        // (on update: left unchanged at the registry).
        $voice = $phone === '' ? '' : ok_epp_normalisePhone($phone, $cc, $values['cc']);
        if ($phone !== '' && $voice === '') {
            throw new EppException("Invalid phone number \"{$phone}\": use the international format +CC.NUMBER");
        }
        $values['voice'] = $voice;
        $values['fax'] = $get('fax') === '' ? '' : ok_epp_normalisePhone($get('fax'), $cc, $values['cc']);

        $type = $get('postalInfo_type') !== '' ? $get('postalInfo_type') : $get('type');
        $values['type'] = in_array($type, ['int', 'loc'], true) ? $type : 'int';
        return $values;
    }

    const CONTACT_OPTIONAL = ['contact:org', 'contact:street', 'contact:sp', 'contact:pc', 'contact:voice', 'contact:fax'];

    protected function postalInfoTemplate()
    {
        return '<contact:postalInfo type="{{ type }}">'
            . '<contact:name>{{ name }}</contact:name>'
            . '<contact:org>{{ org }}</contact:org>'
            . '<contact:addr>'
            . '<contact:street>{{ street1 }}</contact:street>'
            . '<contact:street>{{ street2 }}</contact:street>'
            . '<contact:street>{{ street3 }}</contact:street>'
            . '<contact:city>{{ city }}</contact:city>'
            . '<contact:sp>{{ sp }}</contact:sp>'
            . '<contact:pc>{{ pc }}</contact:pc>'
            . '<contact:cc>{{ cc }}</contact:cc>'
            . '</contact:addr>'
            . '</contact:postalInfo>'
            . '<contact:voice>{{ voice }}</contact:voice>'
            . '<contact:fax>{{ fax }}</contact:fax>'
            . '<contact:email>{{ email }}</contact:email>';
    }

    protected function contactId($contactIdentifier)
    {
        $id = strtoupper(trim((string) $contactIdentifier));
        if (!preg_match('/^\S{3,16}$/', $id)) {
            throw new EppException("Invalid contact ID: {$contactIdentifier}");
        }
        return $id;
    }

    public function contactCreate($contactIdentifier, $params)
    {
        if (!is_string($contactIdentifier) || !is_array($params)) {
            throw new EppException('Invalid Contact Type.');
        }
        $values = $this->contactValues($params);
        $values['id'] = $this->contactId($contactIdentifier);
        $values['authInfo'] = ok_epp_generateObjectPW();

        $xml = $this->render('contact-create', '<command><create>'
            . '<contact:create xmlns:contact="' . self::NS_CONTACT . '">'
            . '<contact:id>{{ id }}</contact:id>'
            . $this->postalInfoTemplate()
            . '<contact:authInfo><contact:pw>{{ authInfo }}</contact:pw></contact:authInfo>'
            . '</contact:create></create><clTRID>{{ clTRID }}</clTRID></command>', $values, [], self::CONTACT_OPTIONAL);

        $r = $this->request($xml, __FUNCTION__);
        $cre = $r->node('//contact:creData');
        return array_merge($r->meta(), ['contact' => [
            'success' => true,
            'id' => $cre ? $r->text('contact:id', $cre) : $values['id'],
            'crDate' => $cre ? $r->text('contact:crDate', $cre) : '',
        ]]);
    }

    public function contactDelete($contactIdentifier)
    {
        if (!is_string($contactIdentifier) || trim($contactIdentifier) === '') {
            throw new EppException('Invalid contact type');
        }
        $xml = $this->render('contact-delete', '<command><delete>'
            . '<contact:delete xmlns:contact="' . self::NS_CONTACT . '"><contact:id>{{ id }}</contact:id></contact:delete>'
            . '</delete><clTRID>{{ clTRID }}</clTRID></command>', ['id' => $contactIdentifier]);

        $r = $this->request($xml, __FUNCTION__);
        return array_merge($r->meta(), ['contact' => ['success' => true, 'id' => $contactIdentifier, 'msg' => 'Contact Deleted']]);
    }

    public function contactUpdate($contactIdentifier, $params)
    {
        if (!is_string($contactIdentifier) || trim($contactIdentifier) === '' || !is_array($params)) {
            throw new EppException('Invalid contact type');
        }
        $values = $this->contactValues($params);
        $values['id'] = $contactIdentifier;

        $xml = $this->render('contact-update', '<command><update>'
            . '<contact:update xmlns:contact="' . self::NS_CONTACT . '">'
            . '<contact:id>{{ id }}</contact:id>'
            . '<contact:chg>' . $this->postalInfoTemplate() . '</contact:chg>'
            . '</contact:update></update><clTRID>{{ clTRID }}</clTRID></command>', $values, [], self::CONTACT_OPTIONAL);

        $r = $this->request($xml, __FUNCTION__);
        return array_merge($r->meta(), ['contact' => ['success' => true, 'id' => $contactIdentifier, 'msg' => 'Contact Updated']]);
    }

    // ------------------------------------------------------------------
    // Host (RFC 5732)
    // ------------------------------------------------------------------

    public function hostCheck($hostNames)
    {
        $names = $this->tags('host:name', $hostNames);
        if ($names === '') {
            throw new EppException('No host name to check');
        }
        $xml = $this->render('host-check', '<command><check>'
            . '<host:check xmlns:host="' . self::NS_HOST . '">{{ names }}</host:check>'
            . '</check><clTRID>{{ clTRID }}</clTRID></command>', [], ['names' => $names]);

        $r = $this->request($xml, __FUNCTION__);
        $result = [];
        foreach ($r->nodes('//host:chkData/host:cd') as $cd) {
            $result[] = [
                'name' => $r->text('host:name', $cd),
                'avail' => ok_epp_bool($r->attr('host:name', 'avail', $cd, '0')),
                'reason' => $r->text('host:reason', $cd),
            ];
        }
        return array_merge($r->meta(), ['host' => $result]);
    }

    public function hostInfo($hostName)
    {
        if (!is_string($hostName) || trim($hostName) === '') {
            throw new EppException('Invalid Host type');
        }
        $xml = $this->render('host-info', '<command><info>'
            . '<host:info xmlns:host="' . self::NS_HOST . '"><host:name>{{ name }}</host:name></host:info>'
            . '</info><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $hostName]);

        $r = $this->request($xml, __FUNCTION__);
        $h = $r->node('//host:infData');
        if ($h === null) {
            throw new EppException('Registry response has no host information');
        }
        $status = [];
        foreach ($r->nodes('host:status', $h) as $node) {
            if ($node->getAttribute('s') !== '') {
                $status[] = $node->getAttribute('s');
            }
        }
        return array_merge($r->meta(), ['host' => [
            'name' => $r->text('host:name', $h),
            'roid' => $r->text('host:roid', $h),
            'status' => $status,
            'addr' => $r->texts('host:addr', $h),
            'clID' => $r->text('host:clID', $h),
            'crID' => $r->text('host:crID', $h),
            'crDate' => $r->text('host:crDate', $h),
            'upID' => $r->text('host:upID', $h),
            'upDate' => $r->text('host:upDate', $h),
        ]]);
    }

    /** $ips: glue addresses, only for hosts under a domain of this registry. */
    public function hostCreate($hostName, $ips = null)
    {
        if (!is_string($hostName) || trim($hostName) === '') {
            throw new EppException('Invalid Host type');
        }
        $xml = $this->render('host-create', '<command><create>'
            . '<host:create xmlns:host="' . self::NS_HOST . '"><host:name>{{ name }}</host:name>{{ ip }}</host:create>'
            . '</create><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $hostName], ['ip' => ok_epp_simple_IpsArrStrTag($ips, 'host:addr')]);

        $r = $this->request($xml, __FUNCTION__);
        $cre = $r->node('//host:creData');
        return array_merge($r->meta(), ['host' => [
            'success' => true,
            'name' => $cre ? $r->text('host:name', $cre) : $hostName,
            'crDate' => $cre ? $r->text('host:crDate', $cre) : '',
        ]]);
    }

    public function hostDelete($hostName)
    {
        if (!is_string($hostName) || trim($hostName) === '') {
            throw new EppException('Invalid Host type');
        }
        $xml = $this->render('host-delete', '<command><delete>'
            . '<host:delete xmlns:host="' . self::NS_HOST . '"><host:name>{{ name }}</host:name></host:delete>'
            . '</delete><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $hostName]);

        $r = $this->request($xml, __FUNCTION__);
        return array_merge($r->meta(), ['host' => ['success' => true, 'name' => $hostName, 'msg' => 'Host Deleted']]);
    }

    public function hostUpdate($hostName, $addIps = null, $remIps = null)
    {
        if (!is_string($hostName) || trim($hostName) === '') {
            throw new EppException('Invalid Host type');
        }
        $add = ok_epp_simple_IpsArrStrTag($addIps, 'host:addr');
        $rem = ok_epp_simple_IpsArrStrTag($remIps, 'host:addr');
        if ($add === '' && $rem === '') {
            return $this->noChange('host', $hostName, 'Host unchanged');
        }
        $xml = $this->render('host-update', '<command><update>'
            . '<host:update xmlns:host="' . self::NS_HOST . '"><host:name>{{ name }}</host:name>{{ add }}{{ rem }}</host:update>'
            . '</update><clTRID>{{ clTRID }}</clTRID></command>', ['name' => $hostName], [
            'add' => $add === '' ? '' : '<host:add>' . $add . '</host:add>',
            'rem' => $rem === '' ? '' : '<host:rem>' . $rem . '</host:rem>',
        ]);

        $r = $this->request($xml, __FUNCTION__);
        return array_merge($r->meta(), ['host' => ['success' => true, 'name' => $hostName, 'msg' => 'Host Updated']]);
    }

    // ------------------------------------------------------------------
    // Poll (RFC 5730)
    // ------------------------------------------------------------------

    public function pollReq()
    {
        $xml = $this->render('poll-req', '<command><poll op="req"/><clTRID>{{ clTRID }}</clTRID></command>');
        $r = $this->request($xml, __FUNCTION__);
        $q = $r->node('//epp:response/epp:msgQ');
        return array_merge($r->meta(), ['poll' => [
            'qDate' => $q ? $r->text('epp:qDate', $q) : '',
            'msg' => $q ? $r->text('epp:msg', $q) : '',
            'msg_last_id' => $q ? $q->getAttribute('id') : '',
            'msg_count' => $q ? $q->getAttribute('count') : '',
        ]]);
    }

    public function pollAck($msgID)
    {
        if (trim((string) $msgID) === '') {
            throw new EppException('Empty poll message ID');
        }
        $xml = $this->render('poll-ack', '<command><poll op="ack" msgID="{{ message }}"/><clTRID>{{ clTRID }}</clTRID></command>', ['message' => $msgID]);
        $r = $this->request($xml, __FUNCTION__);
        return $r->meta();
    }
}
