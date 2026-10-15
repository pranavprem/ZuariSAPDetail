<?php

declare(strict_types=1);

require_once __DIR__ . '/saprfc.php';

/**
 * Read a .env file into the environment.
 *
 * Existing variables win, so a deployed environment is not overwritten by a
 * local file. Values may be bare or wrapped in single or double quotes.
 */
function ztesting_load_dotenv(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $equals = strpos($line, '=');
        if ($equals === false) {
            continue;
        }

        $key = trim(substr($line, 0, $equals));
        if ($key === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            continue;
        }

        $value = trim(substr($line, $equals + 1));
        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
            }
        }

        if (getenv($key) !== false) {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

/**
 * SAP_* values from the environment, after loading an optional .env file.
 *
 * @return array<string, string>
 */
function ztesting_env(?string $dotenvPath = null): array
{
    ztesting_load_dotenv($dotenvPath ?? (__DIR__ . '/.env'));

    $env = [];
    foreach (['SAP_ASHOST', 'SAP_SYSNR', 'SAP_CLIENT', 'SAP_USER', 'SAP_PASSWD', 'SAP_LANG', 'SAP_DEMO'] as $key) {
        $value = getenv($key);
        if ($value === false && array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        }
        if ($value !== false && $value !== null) {
            $env[$key] = (string) $value;
        }
    }

    return $env;
}

/**
 * @param array<string, string> $env
 * @return array<string, string>
 */
function ztesting_credentials(array $env): array
{
    $map = [
        'SAP_ASHOST' => 'ASHOST',
        'SAP_SYSNR' => 'SYSNR',
        'SAP_CLIENT' => 'CLIENT',
        'SAP_USER' => 'USER',
        'SAP_PASSWD' => 'PASSWD',
        'SAP_LANG' => 'LANG',
    ];

    $login = [];
    foreach ($map as $envKey => $sapKey) {
        $value = isset($env[$envKey]) ? trim($env[$envKey]) : '';
        if ($value !== '') {
            $login[$sapKey] = $value;
        }
    }

    return $login;
}

/**
 * @param array<string, string> $login
 */
function ztesting_credentials_ready(array $login): bool
{
    foreach (['ASHOST', 'SYSNR', 'CLIENT', 'USER', 'PASSWD'] as $key) {
        if (!isset($login[$key]) || $login[$key] === '') {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, string> $env
 */
function ztesting_demo_enabled(array $env): bool
{
    if (!isset($env['SAP_DEMO'])) {
        return false;
    }

    return in_array(strtolower(trim($env['SAP_DEMO'])), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Obvious stand-in numbers. These are not personnel records.
 *
 * @return list<array{PERNR: string}>
 */
function ztesting_demo_rows(): array
{
    return [
        ['PERNR' => '10000001'],
        ['PERNR' => '10000002'],
        ['PERNR' => '10000003'],
    ];
}

/**
 * @param array<string, string> $env
 * @return array{rows: array<int|string, mixed>, error: ?string, demo: bool}
 */
function ztesting_fetch(array $env, bool $extensionLoaded, ?saprfc $sap = null): array
{
    if (ztesting_demo_enabled($env)) {
        return [
            'rows' => ztesting_demo_rows(),
            'error' => null,
            'demo' => true,
        ];
    }

    if (!$extensionLoaded) {
        return [
            'rows' => [],
            'error' => 'The saprfc PHP extension is not loaded, so this page cannot call SAP.',
            'demo' => false,
        ];
    }

    $login = ztesting_credentials($env);
    if (!ztesting_credentials_ready($login)) {
        return [
            'rows' => [],
            'error' => 'SAP logon is not configured. Set SAP_ASHOST, SAP_SYSNR, SAP_CLIENT, SAP_USER, and SAP_PASSWD.',
            'demo' => false,
        ];
    }

    if ($sap === null) {
        $sap = new saprfc([
            'logindata' => $login,
            'show_errors' => false,
            'debug' => false,
        ]);
    } else {
        $sap->setLoginData($login);
    }

    try {
        $result = $sap->callFunction('ZTESTING', [
            ['TABLE', 'TEMPO', []],
        ]);

        if (
            $sap->getStatus() === SAPRFC_OK
            && is_array($result)
            && isset($result['TEMPO'])
            && is_array($result['TEMPO'])
        ) {
            return [
                'rows' => $result['TEMPO'],
                'error' => null,
                'demo' => false,
            ];
        }

        if ($sap->getStatus() === SAPRFC_OK) {
            $error = 'ZTESTING returned no TEMPO table.';
        } else {
            $error = trim($sap->getStatusText());
            if ($error === '') {
                $error = 'The call to ZTESTING failed.';
            }
        }

        return [
            'rows' => [],
            'error' => $error,
            'demo' => false,
        ];
    } finally {
        $sap->logoff();
    }
}

/**
 * Personnel numbers from the TEMPO rows returned by ZTESTING.
 *
 * The classic extension numbers table rows from 1 and uses the ABAP field
 * name PERNR. A lowercase key is accepted as well.
 *
 * @param array<int|string, mixed> $rows
 * @return list<string>
 */
function ztesting_pernr_values(array $rows): array
{
    $values = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if (isset($row['PERNR'])) {
            $value = trim((string) $row['PERNR']);
        } elseif (isset($row['pernr'])) {
            $value = trim((string) $row['pernr']);
        } else {
            $value = '';
        }

        if ($value !== '') {
            $values[] = $value;
        }
    }

    return $values;
}

function ztesting_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array{rows: array<int|string, mixed>, error: ?string, demo: bool} $state
 */
function ztesting_render(array $state): string
{
    $notice = '';
    if ($state['demo']) {
        $notice = '<p class="notice">Sample data. SAP was not contacted.</p>';
    }

    if ($state['error'] !== null) {
        $body = '<p class="error">' . ztesting_h($state['error']) . '</p>';
    } else {
        $values = ztesting_pernr_values($state['rows']);
        if ($values === []) {
            $body = '<p class="empty">No current personnel numbers were returned.</p>';
        } else {
            $cells = '';
            foreach ($values as $pernr) {
                $cells .= '<tr><td>' . ztesting_h($pernr) . '</td></tr>';
            }
            $body = '<table>'
                . '<thead><tr><th scope="col">PERNR</th></tr></thead>'
                . '<tbody>' . $cells . '</tbody>'
                . '</table>';
        }
    }

    return '<!DOCTYPE html>'
        . '<html lang="en">'
        . '<head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>SAP personnel numbers</title>'
        . '<link rel="stylesheet" href="style.css">'
        . '</head>'
        . '<body>'
        . '<main>'
        . '<h1>Personnel numbers</h1>'
        . '<p class="lede">Current PERNR values from SAP infotype 0001, via function module ZTESTING.</p>'
        . $notice
        . $body
        . '</main>'
        . '</body>'
        . '</html>';
}

if (!defined('ZTESTING_LIBRARY_ONLY')) {
    echo ztesting_render(ztesting_fetch(ztesting_env(), extension_loaded('saprfc')));
}
