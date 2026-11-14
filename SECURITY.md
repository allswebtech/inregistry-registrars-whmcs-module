# Security policy

## Supported versions

Security fixes are made on the latest 2.x release. Version 1.x is not maintained; it does
not work with the current registry platform.

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately through GitHub:
**[Report a vulnerability](https://github.com/allswebtech/inregistry-registrars-whmcs-module/security/advisories/new)**
(Security tab → Advisories → Report a vulnerability).

Include the module version, the WHMCS and PHP versions, and the steps to reproduce.
Remove EPP passwords, auth codes, certificates and private keys from anything you send.

You will get a first answer within 5 working days. Once a fix is released, the advisory
is published and you are credited, unless you prefer not to be named.

## Handling credentials

- Keep the client certificate and private key outside the web root, readable only by the
  user PHP runs as (`chmod 600`).
- Set the *Registry CA Certificate* option in production so the registry's TLS
  certificate is verified.
- If you upgrade from 1.x, delete `modules/registrars/inregistry/logs`. Old debug logs in
  that folder can contain EPP passwords and auth codes. If such a log ever existed on a
  public server, change your EPP password.
