# Contributing

Bug reports and pull requests are welcome.

## Reporting a bug

Open an issue and include:

- the module version (see `whmcs.json`), the WHMCS version and the PHP version;
- what you did in WHMCS and what happened;
- the matching entry from **Configuration → System Logs → Module Log** (request and
  response).

Remove passwords, auth codes and personal data before you post a log.

## Making a change

1. Fork the repository and create a branch from `main`.
2. Keep the code compatible with **PHP 7.4 to 8.4**. No Composer packages at runtime.
3. Send only IETF-standard EPP. A registry-specific extension must be used only when the
   registry advertises it in its greeting.
4. Add or update a test for what you change, then run:

   ```bash
   php tests/run.php
   ```

   The suite validates every EPP command against the XML schemas in `tests/xsd`, so a
   change that produces invalid XML fails locally.
5. Describe the change under a new heading in `CHANGELOG.md`.
6. Open a pull request. GitHub Actions runs the suite on every supported PHP version.

## Layout

| Path | Purpose |
|---|---|
| `modules/registrars/inregistry/inregistry.php` | The functions WHMCS calls |
| `modules/registrars/inregistry/lib/EppClient.php` | The EPP client: connection, framing, commands, response parsing |
| `modules/registrars/inregistry/lib/Helper.php` | One function per WHMCS action, built on the client |
| `modules/registrars/inregistry/lib/Config.php` | Connection settings and log masking |
| `tests/` | Test runner, WHMCS stubs and the IETF XML schemas |

By contributing you agree that your contribution is licensed under the MIT license.
