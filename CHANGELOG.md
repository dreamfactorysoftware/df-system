# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
- Add `system/server_setting`: runtime override of the log level, admin accounts only, role-based for restricted admins (`server_setting/*` on system) (DB-backed, allow-listed, never baked into `config:cache`).
- Fix `PATCH system/app/{id}?regenerate=true` rotating the API key of the first app in the table (the `admin` app) instead of app `{id}`; an unknown id now returns 404.
- Add `DF_OUTBOUND_ALLOWED_HOSTS` (`df.outbound_allowed_hosts`): comma-separated hostnames, IP addresses and CIDR ranges on the internal network that remote URLs may point at. It applies to SOAP WSDL, endpoint and proxy hosts, package, app and data import URLs, file uploads and email attachments given by URL, and redirects followed by outbound requests. Hostnames match exactly; addresses and ranges match the resolved address. Empty by default.

## 0.1.0 - 2017-12-28
First official release working with the new [dreamfactory](https://github.com/dreamfactorysoftware/dreamfactory) project.

[Unreleased]: https://github.com/dreamfactorysoftware/df-system/compare/0.1.0...HEAD
[0.1.1]: https://github.com/dreamfactorysoftware/df-system/compare/0.1.0...0.1.1
