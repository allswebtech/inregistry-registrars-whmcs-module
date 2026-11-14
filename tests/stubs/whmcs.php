<?php

// Minimal stand-ins for the parts of WHMCS the module touches.

namespace WHMCS\Domains {
    class Domain
    {
        private $domain;

        public function __construct($domain, $tld = null, $exceptions = true)
        {
            $this->domain = $tld === null ? (string) $domain : $domain . '.' . ltrim($tld, '.');
        }

        public function getDomain($idn = true)
        {
            return $this->domain;
        }
    }
}

namespace WHMCS {
    class Carbon
    {
        private $dt;

        public static function createFromFormat($format, $time, $tz = null)
        {
            $dt = \DateTime::createFromFormat($format, $time, new \DateTimeZone('UTC'));
            if ($dt === false) {
                throw new \InvalidArgumentException("Bad date {$time} for {$format}");
            }
            $c = new self();
            $c->dt = $dt;
            return $c;
        }

        public function toDateString()
        {
            return $this->dt->format('Y-m-d');
        }
    }
}

namespace WHMCS\Exception\Module {
    class InvalidConfiguration extends \Exception
    {
    }
}

namespace WHMCS\Database {
    class Capsule
    {
        public static $tables = [];

        public static function table($name)
        {
            return new CapsuleQuery($name);
        }
    }

    class CapsuleQuery
    {
        private $name;
        private $where = [];

        public function __construct($name)
        {
            $this->name = $name;
        }

        public function where($column, $value)
        {
            $this->where[$column] = $value;
            return $this;
        }

        private function rows()
        {
            $out = [];
            foreach (Capsule::$tables[$this->name] ?? [] as $i => $row) {
                $ok = true;
                foreach ($this->where as $c => $v) {
                    if (!isset($row[$c]) || $row[$c] != $v) {
                        $ok = false;
                    }
                }
                if ($ok) {
                    $out[$i] = $row;
                }
            }
            return $out;
        }

        public function value($column)
        {
            $rows = $this->rows();
            $row = reset($rows);
            return $row === false ? null : ($row[$column] ?? null);
        }

        public function first($columns = ['*'])
        {
            $rows = $this->rows();
            $row = reset($rows);
            return $row === false ? null : (object) $row;
        }

        public function update(array $values)
        {
            foreach (array_keys($this->rows()) as $i) {
                Capsule::$tables[$this->name][$i] = array_merge(Capsule::$tables[$this->name][$i], $values);
            }
            return count($this->rows());
        }
    }
}

namespace WHMCS\Domain\Registrar {
    class Domain
    {
        const STATUS_ACTIVE = "Active";
        const STATUS_ARCHIVED = "Archived";
        const STATUS_DELETED = "Deleted";
        const STATUS_EXPIRED = "Expired";
        const STATUS_INACTIVE = "InActive";
        const STATUS_SUSPENDED = "Suspended";
        const STATUS_PENDING_DELETE = "Pending Delete Restorable";

        const BOOLEAN_SETTERS = [
            'setTransferLock', 'setRestorable', 'setIdProtectionStatus', 'setDnsManagementStatus',
            'setEmailForwardingStatus', 'setIsIrtpEnabled', 'setIrtpOptOutStatus', 'setIrtpTransferLock',
            'setDomainContactChangePending', 'setPendingSuspension',
        ];
        const OTHER_SETTERS = [
            'setDomain', 'setNameservers', 'setRegistrationStatus', 'setRegistrantEmailAddress',
            'setIrtpVerificationTriggerFields', 'setRenewBeforeExpiration',
        ];

        public $values = [];

        public function __call($name, $args)
        {
            if (in_array($name, self::BOOLEAN_SETTERS, true)) {
                if (!is_bool($args[0] ?? null)) {
                    throw new \TypeError("{$name} expects a boolean");
                }
            } elseif (!in_array($name, self::OTHER_SETTERS, true)) {
                throw new \BadMethodCallException("Unknown method {$name}");
            }
            $this->values[substr($name, 3)] = $args[0] ?? null;
            return $this;
        }

        public function setExpiryDate(?\WHMCS\Carbon $date = null)
        {
            $this->values['ExpiryDate'] = $date;
            return $this;
        }

        public function setTransferLockExpiryDate(?\WHMCS\Carbon $date = null)
        {
            $this->values['TransferLockExpiryDate'] = $date;
            return $this;
        }

        public function setDomainContactChangeExpiryDate(?\WHMCS\Carbon $date = null)
        {
            $this->values['DomainContactChangeExpiryDate'] = $date;
            return $this;
        }
    }
}

namespace {
    $GLOBALS['__modulelog'] = [];
    $GLOBALS['__clients'] = [];

    function logModuleCall($module, $action, $requeststring, $responsedata, $processeddata = '', $replacevars = [])
    {
        $GLOBALS['__modulelog'][] = [
            'module' => $module,
            'action' => $action,
            'request' => $requeststring,
            'response' => $responsedata,
            'replace' => $replacevars,
        ];
    }

    function injectDomainObjectIfNecessary($params)
    {
        if (empty($params['domainObj']) && !empty($params['sld']) && !empty($params['tld'])) {
            $params['domainObj'] = new \WHMCS\Domains\Domain($params['sld'], $params['tld']);
        }
        return $params;
    }

    function getClientsDetails($userid = 0)
    {
        return $GLOBALS['__clients'][$userid] ?? [];
    }
}
