# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## [0.6.7] - 2026-11-18
- Add `system/server_setting`: change the log level at runtime through an override stored in the system database. Administrators only; restricted administrators through `server_setting/*` on the system service. Each change is written to the log.
- Add `DF_OUTBOUND_ALLOWED_HOSTS` (`df.outbound_allowed_hosts`): hostnames, IP addresses and CIDR ranges on your internal network that DreamFactory may connect to. Empty by default.
- Stability and reliability improvements.

## 0.1.0 - 2017-12-28
First official release working with the new [dreamfactory](https://github.com/dreamfactorysoftware/dreamfactory) project.

[Unreleased]: https://github.com/dreamfactorysoftware/df-system/compare/0.1.0...HEAD
[0.1.1]: https://github.com/dreamfactorysoftware/df-system/compare/0.1.0...0.1.1
