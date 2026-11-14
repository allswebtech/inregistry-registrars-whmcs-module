# Changelog

All notable changes to this project are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [2.0.0] - 2026-11-14

Version 2 makes the module work on the registry platform NIXI has used since 28 May 2025
(Tucows Registry Services, `epp.nixiregistry.in`). Version 1.x had only been pointed at
the new host name and still sent requests that only the previous platform understood.

The EPP commands are validated against the official IETF XML schemas and the WHMCS
functions are tested against a scripted registry on PHP 7.4 to 8.4. Run your own cycle in
OT&E mode before you use it in production.

### Fixed

- **Domain registration failed with "Command syntax error"**
  ([#3](https://github.com/allswebtech/inregistry-registrars-whmcs-module/issues/3)).
  The availability check used an extension of the previous registry platform
  (`urn:ar:params:xml:ns:exAvail-1.0`). It now uses the standard EPP domain check.
- Values that contain `$` or `\` followed by a digit (common in passwords) were altered
  before they were sent to the registry.
- Registry answers longer than about 8 KB were cut off while reading.
- An error answer with more than one result was treated as success.
- Domains with exactly one nameserver, or with fewer than three contacts, could not be
  read (PHP error on PHP 8).
- A registration was reported as failed when the domain had been created but the transfer
  lock or the logout afterwards failed.
- Saving nameservers failed when a nameserver did not exist at the registry yet, or when
  only the letter case differed.
- Saving contact details sent the phone number without the `+CC.NUMBER` separator, which
  the registry rejects.
- A completed transfer was never marked complete by the sync cron, because the follow-up
  contact update needed client details that WHMCS does not pass to the cron.
- The domain page showed invalid data instead of an error when the registry could not be
  reached.
- Contact IDs could become longer than the 16 characters EPP allows.
- Contact data with `&` was encoded twice (the module declared an invalid module API
  version).
- Error messages no longer end in a dangling `:` and now include the EPP result code.
- A nameserver under the domain itself that is not registered yet is refused with a clear
  message before anything is created at the registry.

### Changed

- The login now declares only the extensions the registry advertises in its greeting.
- Renewals, the domain sync and the domain page understand the registry's 45-day
  auto-renew grace period, so a late renewal is not charged twice (see the README).
- The connection uses TLS 1.2 or 1.3, whichever the registry offers.
- "Get EPP Code" sets and returns a new auth code when the registry does not return the
  stored one.
- The registrar lock is only changed when its state really changes.
- The fixed list of TLDs no longer blocks registrations. The registry decides which zones
  you may register.
- All library code is in the PHP namespace `AllsWeb\InRegistry`, so it cannot collide
  with another EPP module in the same WHMCS installation.
- Auth codes and contact IDs are generated with a cryptographically secure random source.

### Added

- Optional verification of the registry's TLS certificate (setting
  *Registry CA Certificate*).
- EPP Login Security extension (RFC 8807) for passwords longer than 16 characters.
- A test suite and a GitHub Actions workflow for PHP 7.4 to 8.4.

### Removed

- File logging into `modules/registrars/inregistry/logs`. In debug mode that folder
  received EPP passwords and auth codes and could be reached from the web. Requests are
  logged in the WHMCS Module Log only, with secrets masked. **Delete the old `logs`
  folder when you upgrade.**
- The module no longer changes PHP's `error_log`, `error_reporting` and `display_errors`
  settings for the whole WHMCS request.
- Client methods for extensions of the previous registry platform (price check, claims
  check, extended availability check).

### Security

- The login password and all auth codes are masked in the WHMCS Module Log, in requests
  and in responses.

## [1.1.0] - 2025-12-12

Not published as a tagged release; this is the state of the `main` branch at commit
`ef48d57`.

- Changed the EPP host names to `epp.nixiregistry.in` and `epp.ote.nixiregistry.in`.
- Removed duplicated code.

## [1.0.2] - 2023-04-28

- Improved the transfer sync.
- Reworked reading of domain information and disabled the ID-protection toggle, which the
  registry does not support.
- Fixed cancelling a pending transfer.

## [1.0.1] - 2023-04-08

- First release.

[2.0.0]: https://github.com/allswebtech/inregistry-registrars-whmcs-module/releases
[1.0.2]: https://github.com/allswebtech/inregistry-registrars-whmcs-module/releases/tag/v1.0.2
[1.0.1]: https://github.com/allswebtech/inregistry-registrars-whmcs-module/releases/tag/v1.0.1
