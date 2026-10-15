# Agent guide

This repository is a 2014 internship page: PHP calls SAP function module `ZTESTING` and prints personnel numbers. Keep that shape. Do not turn it into a general SAP framework, and do not add a license file.

`CLAUDE.md` only points here.

## Architecture

- `ztesting.php` loads `.env` if present, reads `SAP_*` from the environment, calls `ZTESTING`, and renders one HTML page. `index.php` includes it so `/` and `/ztesting.php` are the same page.
- The bottom of `ztesting.php` renders only when `ZTESTING_LIBRARY_ONLY` is not defined. Tests define that constant in `tests/bootstrap.php` before including the file.
- `saprfc.php` is Lars Laegner's 2001 class around the `saprfc` extension (`saprfc_open`, `saprfc_function_discover`, `saprfc_import` / `saprfc_table_*`, `saprfc_call_and_receive`, `saprfc_export`). The page always passes `show_errors => false` and prints `getStatusText()` itself.
- `abap/ztesting.abap` is a reference snippet for SE37. Nothing here executes ABAP.
- A live call needs the classic `saprfc` extension and SAP's classic RFC SDK. Neither is installed in CI. Tests cover argument checking, status handling, rendering, and a fake subclass. They do not open a network connection.

Call path:

1. `ztesting_fetch()` returns demo rows when `SAP_DEMO` is `1`, `true`, `yes`, or `on`.
2. Otherwise it refuses to call SAP when `extension_loaded('saprfc')` is false.
3. Otherwise it requires `SAP_ASHOST`, `SAP_SYSNR`, `SAP_CLIENT`, `SAP_USER`, and `SAP_PASSWD` (trimmed; whitespace is unset). `SAP_LANG` is optional.
4. `saprfc::callFunction('ZTESTING', [['TABLE', 'TEMPO', []]])` logs on, passes the empty table, and reads `TEMPO` back.
5. The page prints `PERNR` (or `pernr`). Any other failure becomes the error banner. Do not iterate the return value until `getStatus()` is `SAPRFC_OK` and `TEMPO` is an array. On failure the method returns the integer status, which is what used to crash the page on PHP 8.

## Conventions

- Runtime is PHP 8.3+. New PHP follows PSR-12 (`composer format`). Leave the historical layout of `saprfc.php` alone; the fixer excludes it. Patch that file only for a real bug.
- No production dependencies. Test and formatter versions stay pinned in `composer.lock`.
- Logon fields come from the environment or `.env`. Never commit `.env`, passwords, connection strings, or host names. `.env.example` stays blank. Tests may use `sap.example.com`.
- Do not add a license.
- Escape anything that came from SAP or from an error string before putting it in HTML.
- The class name stays `saprfc` (lowercase). Callers, including this page, construct it that way.

## Commands

```bash
composer install
composer test
composer lint
composer format
composer serve
SAP_DEMO=1 php -S localhost:8080
```

CI (`.github/workflows/ci.yml`) runs `composer lint` on PHP 8.3 and `composer test` on PHP 8.3 and 8.4.

## Gotchas

- PHP 8 does not call the old PHP 4 constructor `saprfc()`. The class now uses `__construct`.
- `SAPRFC_EXCEPTION` is RFC SDK value `2`, same integer as this class's `SAPRFC_APPL_ERROR`. They are different statuses. The extension defines `SAPRFC_EXCEPTION` when it is loaded; the class defines it only as a fallback. Do not compare them to each other.
- `saprfc_export` may return `"0"`. That is success. The export check must stay `isset`, not `empty`. Import results are booleans, so `empty` is still correct there.
- `logoff()` has to set `rfc_conn` back to false. The old method closed the handle and left it set, so a second logoff would close it again.
- `callFunction()` validates the function name and parameter list before it checks that the extension is loaded. A bad argument should not be reported as a missing DLL.
- `getStatusTextLong()` still emits the original `<font>` markup. It escapes the message text. The page does not use it.
- The commented-out `saprfc_function_free()` call stays commented. The original note says freeing the result sometimes stopped PHP entirely.
- ABAP validity is inclusive: `begda <= sy-datum AND endda >= sy-datum`. The 2014 strict `<` / `>` comparison dropped assignments that started or ended today.
- `GUI_DOWNLOAD` and `OPEN DATASET` were internship experiments and are gone from the reference copy. Do not put them back into the function module.
- Demo mode never contacts SAP. Do not leave `SAP_DEMO=1` set on a shared server.
- `example_userlist.php`, mentioned in the header of `saprfc.php`, was part of Laegner's examples. It is not in this repository.
- There is no SAP system in this environment. Do not invent a host, client, or user to make a live call look configured.
