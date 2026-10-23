# PHP Implementation Plan

Goal: Build the V1.2 PHP/MySQL application from the approved Tabler demo.

Architecture: Root entry scripts, protected application/config/storage directories,
PDO repositories and native browser JavaScript. Tabler remains the only stylesheet.
Specification: ../聊天式笔记系统_开发文档_V1.2.md.

- [x] Create schema, configuration template, installer and protected deployment rules.
- [x] Implement expiring PHP sessions, CSRF, password changes and session revocation.
- [x] Implement groups, paginated messages, transactional attachments and deletion.
- [x] Implement serialized change sequence and incremental synchronization.
- [x] Implement HMAC six-digit anonymous codes, expiry, revocation and write-only submission.
- [x] Connect the approved UI to real APIs, preserve keyboard/paste and mobile behavior.
- [ ] Add cleanup, installation instructions and meaningful integration checks.

Cleanup, installation instructions, dedicated-database integration tests and browser tests
are implemented. PHP AST parsing (17 files) and Chrome frontend checks (18 assertions)
passed. PHP 8.4 execution, MySQL integration and full end-to-end acceptance remain pending.

Installer now supports configuration-free setup through a server-rendered database/admin
form. It uses an independent installation session, generated secrets, protected temporary
config files, exclusive publication, filesystem/database install locks and retry progress.
Settings/serialization tests are provided; live automatic-install acceptance is pending.

Verification: PHP 8.4 lint, isolated MySQL integration tests and browser acceptance
against the specification. This workspace currently provides none of PHP/MySQL/Node;
PHP/MySQL runtime verification must be explicitly reported as unavailable until provided.
Isolated Chrome frontend checks are available and run independently of backend tests.

Constraints: No external resources, no frameworks, no persistent-login cookies;
all reads authenticate, all admin mutations check CSRF, uploads stay behind authorization.
