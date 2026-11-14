# .IN Registry (NIXI) WHMCS Registrar Module — EPP

[![CI](https://github.com/allswebtech/inregistry-registrars-whmcs-module/actions/workflows/ci.yml/badge.svg)](https://github.com/allswebtech/inregistry-registrars-whmcs-module/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/allswebtech/inregistry-registrars-whmcs-module?include_prereleases&sort=semver)](https://github.com/allswebtech/inregistry-registrars-whmcs-module/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP 7.4 – 8.4](https://img.shields.io/badge/PHP-7.4%20–%208.4-777bb4.svg)

A free, open-source **WHMCS registrar module for `.in` domains**. It connects WHMCS
directly to the **.IN Registry (NIXI)** over **EPP**, so an accredited .IN registrar can
register, transfer, renew and manage `.in`, `.co.in`, `.net.in`, `.org.in` and the other
.IN zones from WHMCS, without a reseller in between.

It speaks plain IETF-standard EPP (RFC 5730 – 5734, RFC 3915, RFC 5910, RFC 8807) to the
registry platform that NIXI has used since 28 May 2025 (`epp.nixiregistry.in`, operated
by Tucows Registry Services).

> **Unofficial module.** This project is made by [AllsWeb](https://www.allsweb.com) and
> is not affiliated with, or endorsed by, NIXI, the .IN Registry, Tucows or WHMCS. You
> need your own .IN registrar accreditation and EPP credentials to use it.

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Registry connection details](#registry-connection-details)
- [How the module behaves](#how-the-module-behaves)
- [Troubleshooting](#troubleshooting)
- [Upgrading from 1.x](#upgrading-from-1x)
- [FAQ](#faq)
- [Development and tests](#development-and-tests)
- [Security](#security)
- [License](#license)

## Features

| In WHMCS | What the module does at the registry |
|---|---|
| Register a domain | Checks availability, creates the contact, creates missing nameserver host objects, creates the domain, then sets the transfer lock |
| Transfer a domain in | Sends the transfer request with the EPP/auth code; a custom admin button cancels a pending transfer |
| Renew a domain | Renews for the period chosen in WHMCS; understands the registry's auto-renew grace period (see below) |
| Nameservers | Reads and saves nameservers; creates host objects that do not exist yet |
| Registrar lock | Reads and toggles `clientTransferProhibited` |
| Contact details | Reads and updates registrant, admin, tech and billing contacts |
| EPP code | Shows the transfer auth code; an admin button generates a new one |
| Private nameservers | Registers, modifies and deletes child nameservers (glue records) |
| Delete | Sends a domain delete request |
| Domain sync and transfer sync | Keeps expiry dates and transfer status in WHMCS up to date from the cron |
| Domain information | Live status, nameservers, lock and expiry on the WHMCS domain page |

Under the hood:

- Extensions are negotiated from the server greeting, so the module only uses what the
  registry advertises.
- Passwords longer than 16 characters are sent with the EPP Login Security extension
  (RFC 8807) when the registry supports it.
- Optional verification of the registry's TLS certificate against the registry CA file.
- Every request and response goes to the WHMCS **Module Log**, with the login password
  and all domain and contact auth codes masked. Nothing is written to web-reachable files.
- No Composer packages and no ionCube: plain PHP that you can read.

## Requirements

- **WHMCS 8.0 or later** (the module uses module API version 1.1).
- **PHP 7.4 to 8.4** with the `openssl`, `dom` and `xml` extensions. The test suite runs
  on every one of those PHP versions.
- **.IN registrar accreditation** from NIXI, with:
  - your EPP **client ID** and **password**,
  - the **client certificate** issued to you by the registry and its **private key**
    (PEM files),
  - your registrar **contact ID prefix**, if the registry gave you one.
- Outbound **TCP port 700** from the WHMCS server to the registry.

## Installation

1. Download the latest release ZIP from the
   [releases page](https://github.com/allswebtech/inregistry-registrars-whmcs-module/releases)
   (or clone this repository).
2. Copy the `modules/registrars/inregistry` folder into your WHMCS installation, so that
   this file exists:

   ```text
   <whmcs>/modules/registrars/inregistry/inregistry.php
   ```

3. Put your certificate and private key on the server, **outside the web root**, and make
   them readable only by the user PHP runs as:

   ```bash
   mkdir -p /home/whmcs/epp-certs
   cp registrar-cert.pem registrar-key.pem /home/whmcs/epp-certs/
   chmod 600 /home/whmcs/epp-certs/*.pem
   ```

4. In WHMCS go to **Configuration → System Settings → Domain Registrars**, find
   **IN Registry** and click **Activate**.
5. Fill in the settings described below and click **Save Changes**. WHMCS tests the
   connection and login when you save.
6. Go to **Configuration → System Settings → Domain Pricing**, add the TLDs you sell
   (`.in`, `.co.in`, …) and choose **IN Registry** in the *Auto Registration* column.

Start with **OT&E (Test) Mode** switched on and run a full register, update, renew and
transfer cycle against the registry's test system before you go live.

## Configuration

| Setting | What to enter |
|---|---|
| **Signed Certificate** | Absolute path to the client certificate (PEM) the registry issued to you, for example `/home/whmcs/epp-certs/registrar-cert.pem` |
| **Private Key** | Absolute path to the private key (PEM) that belongs to the certificate. Leave empty if the key is in the same file as the certificate |
| **Pass Phrase** | Pass phrase of the private key, if it has one |
| **Registry CA Certificate** (optional) | Absolute path to the registry's CA certificate (PEM). When set, the module verifies the registry's TLS certificate and host name. Recommended for production |
| **Client ID** | Your EPP login (registrar ID) |
| **Password** | Your EPP password |
| **Identity Prefix** | Up to 4 letters or digits. The module puts it at the start of every contact ID and transaction ID it creates |
| **OT&E (Test) Mode** | Tick to connect to the test system `epp.ote.nixiregistry.in` instead of production |

OT&E and production have separate credentials and certificates.

## Registry connection details

| | Production | OT&E (test) |
|---|---|---|
| EPP server (TCP 700) | `epp.nixiregistry.in` | `epp.ote.nixiregistry.in` |
| Registrar console | <https://console.nixiregistry.in> | <https://console.ote.nixiregistry.in> |
| WHOIS (web) | <https://whois.nixiregistry.in/> | <https://whois.ote.nixiregistry.in/> |
| WHOIS (TCP 43) | `whois.nixiregistry.in` | |
| RDAP | `rdap.nixiregistry.in` | |

- Registry status page: <https://www.tucowsregistrystatus.com/>
- Registry support for registrars:
  [Tucows Registry knowledge base, NIXI section](https://support.tucowsregistry.com/support/solutions/201000106008)
- The registry authenticates you by client certificate. According to the registry's
  documentation, IP allow-listing is not required on this platform.

## How the module behaves

### Registration

The module creates **one contact** from the WHMCS client's details and uses it as
registrant, admin, tech and billing contact. Nameservers that are not yet known to the
registry are created as host objects first. After the domain is created the module adds
`clientTransferProhibited` (the registrar lock).

If the lock cannot be set, the registration is still reported as successful, because the
domain exists and has been billed; the failure is written to the Module Log.

A nameserver under the domain itself (for example `ns1.example.in` for `example.in`)
needs an IP address at the registry. Register the domain with other nameservers first,
create the nameserver under **Private Nameservers**, then switch to it.

### Auto-renew grace period

The registry **renews a domain automatically on its expiry date** and adds one year. The
domain then stays in a 45-day *auto-renew grace period*. The registry charges the renewal
when those 45 days end, or earlier if you send a renew or a transfer. Deleting the domain
inside the 45 days costs nothing.

The module handles this so that WHMCS and the registry do not drift apart. While a domain
is in the auto-renew grace period it compares the registry's expiry date with the expiry
date stored in WHMCS:

- **Not renewed in WHMCS yet:** the sync reports the *paid-through* date (registry expiry
  minus one year), so WHMCS shows the domain as expired and keeps sending reminders. When
  the customer then renews for one year, the module sends no renew command, because the
  registry has already added that year. A renewal for *N* years sends a renew for *N − 1*.
- **Already renewed in WHMCS:** the sync reports the registry's expiry date, and a further
  renewal is sent to the registry as usual.

WHMCS does not delete unpaid domains at the registry. **Delete domains your customer did
not renew within the 45 days**, otherwise the registry charges you for the year.

### Transfers

A transfer in is requested with a one-year extension. When the transfer
completes, the sync cron marks it complete in WHMCS and then, as a best effort, replaces
the contacts with a new contact from the client's details, sets the registrar lock and
sets a new auth code.

The registry does not enforce a 60-day lock after registration or transfer. Use the
registrar lock if your policy needs one.

### Contact IDs and phone numbers

Contact IDs are upper-case, at most 16 characters, and start with your identity prefix.
Phone numbers are sent in the EPP format `+CC.NUMBER` (for example `+91.9876543210`); the
module converts the usual ways of writing a number into that format.

### Supported TLDs

The module does not keep its own allow-list. It works with every zone the registry lets
you register: `.in` and the second-level zones such as `.co.in`, `.net.in`, `.org.in`,
`.firm.in`, `.gen.in`, `.ind.in`, `.ai.in`, `.io.in`, `.me.in`, `.info.in`, `.biz.in`
and the others listed in the registrar console. Add the ones you sell under
**Domain Pricing**.

## Troubleshooting

Open **Configuration → System Logs → Module Log** in WHMCS (enable module logging first)
to see the exact EPP request and the registry's answer.

| Symptom | Likely cause and fix |
|---|---|
| `Command syntax error` on registration with version 1.x | Version 1.x still used an availability-check extension from the previous registry platform. Upgrade to 2.x |
| Connection error with `(code 0)` or an empty message | The TLS handshake failed. Check the certificate and key paths, that PHP can read both files, the pass phrase, and that port 700 is open outbound |
| `certificate required` or `unknown ca` | The certificate is missing, expired, or belongs to the other environment (OT&E vs production) |
| `Authentication error` (2200) | Wrong client ID or password, or the certificate does not belong to this client ID |
| `Object does not exist` (2303) when saving nameservers on 1.x | 1.x did not create missing host objects. 2.x does |
| `Parameter value policy error` (2306) on a contact | The registry rejected a contact field. The Module Log shows which one |
| Certificate verification fails after you set *Registry CA Certificate* | The CA file is not the one that issued the registry's server certificate. Use the CA file for the environment you connect to (OT&E or production) |

Check the handshake from the WHMCS server with OpenSSL:

```bash
openssl s_client -connect epp.ote.nixiregistry.in:700 \
  -cert /home/whmcs/epp-certs/registrar-cert.pem \
  -key  /home/whmcs/epp-certs/registrar-key.pem
```

A working connection prints the certificate chain and then an XML `<greeting>`.

## Upgrading from 1.x

1. Back up `modules/registrars/inregistry`.
2. Replace the folder with the one from the new release. Your saved settings stay as
   they are: all setting names are unchanged.
3. Delete the old `modules/registrars/inregistry/logs` folder. Version 2 does not use it,
   and old debug logs in it can contain EPP passwords and auth codes.
4. Open the registrar settings and click **Save Changes** once to test the connection.

What changed is listed in the [changelog](CHANGELOG.md).

## FAQ

**Can I use this module without being a .IN accredited registrar?**
No. It connects to the registry itself. If you buy `.in` domains through a reseller
account, use your reseller's WHMCS module instead.

**Does it work with the new NIXI registry platform (nixiregistry.in)?**
Yes, version 2 is written for it. The old `epp.registry.in` endpoints were retired at the
platform transition on 28 May 2025.

**Is this the official NIXI or WHMCS module?**
No. It is an independent open-source project under the MIT license.

**Does it support ID protection (WHOIS privacy)?**
No. The module has no ID-protection toggle. What the .IN WHOIS shows is decided by
registry policy.

**Does it support DNS management, email forwarding or premium domains in WHMCS?**
No. DNS hosting and email forwarding are not registry services. Premium pricing and the
WHMCS domain-lookup provider are not implemented.

**Does it support DNSSEC?**
The EPP client can send DS records (RFC 5910), but there is no client-area screen for it
yet.

**Where do I report a bug?**
Open an issue with the WHMCS and PHP versions and the relevant Module Log entry. Remove
passwords and auth codes before you post it.

## Development and tests

The EPP client lives in `modules/registrars/inregistry/lib` under the PHP namespace
`AllsWeb\InRegistry`. The tests need only PHP:

```bash
php tests/run.php
```

They validate every EPP command the module can send against the official IETF XML
schemas (in `tests/xsd`) and run the WHMCS functions against a scripted registry. The
same suite runs in GitHub Actions on PHP 7.4 to 8.4. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Please report vulnerabilities privately, as described in [SECURITY.md](SECURITY.md).

## License

Released under the [MIT license](LICENSE).

---

Built and maintained by [AllsWeb](https://www.allsweb.com): managed IT services, hosting
and AI automation from Jaipur, India.
