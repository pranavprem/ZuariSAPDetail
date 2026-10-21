<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecordingSap extends saprfc
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    public mixed $result = null;

    public int $forcedStatus = SAPRFC_OK;

    public string $forcedStatusText = '';

    public bool $loggedOff = false;

    public function callFunction($func_name = '', $parameters = [])
    {
        $this->calls[] = [$func_name, $parameters];
        $this->setStatus($this->forcedStatus, $this->forcedStatusText);

        return $this->result;
    }

    public function logoff()
    {
        $this->loggedOff = true;
        parent::logoff();
    }
}

final class ZtestingTest extends TestCase
{
    /** @var list<string> */
    private array $envKeys = [
        'SAP_ASHOST',
        'SAP_SYSNR',
        'SAP_CLIENT',
        'SAP_USER',
        'SAP_PASSWD',
        'SAP_LANG',
        'SAP_DEMO',
    ];

    protected function setUp(): void
    {
        $this->clearSapEnv();
    }

    protected function tearDown(): void
    {
        $this->clearSapEnv();
    }

    public function testWhitespaceCredentialsAreTreatedAsUnset(): void
    {
        $login = ztesting_credentials([
            'SAP_ASHOST' => '   ',
            'SAP_SYSNR' => '00',
            'SAP_CLIENT' => '100',
            'SAP_USER' => 'USER',
            'SAP_PASSWD' => 'secret',
            'SAP_LANG' => 'EN',
        ]);

        $this->assertArrayNotHasKey('ASHOST', $login);
        $this->assertSame('EN', $login['LANG']);
        $this->assertFalse(ztesting_credentials_ready($login));
    }

    public function testCredentialsAreReadyOnlyWhenEveryRequiredFieldIsSet(): void
    {
        $login = ztesting_credentials($this->configuredEnv());

        $this->assertTrue(ztesting_credentials_ready($login));
        $this->assertSame('sap.example.com', $login['ASHOST']);
    }

    public function testDotenvFillsEmptyEnvironmentAndDoesNotOverrideIt(): void
    {
        $dir = sys_get_temp_dir() . '/ztesting-env-' . uniqid('', true);
        mkdir($dir);
        file_put_contents(
            $dir . '/.env',
            "export SAP_ASHOST=from-file\nSAP_SYSNR=\"00\"\nSAP_PASSWD='secret value'\n# comment\n\nnot a pair\n"
        );
        putenv('SAP_ASHOST=from-env');

        try {
            $env = ztesting_env($dir . '/.env');
        } finally {
            unlink($dir . '/.env');
            rmdir($dir);
        }

        $this->assertSame('from-env', $env['SAP_ASHOST']);
        $this->assertSame('00', $env['SAP_SYSNR']);
        $this->assertSame('secret value', $env['SAP_PASSWD']);
    }

    public function testMissingExtensionRendersAnErrorInsteadOfIteratingAStatusCode(): void
    {
        $state = ztesting_fetch([], false);
        $html = ztesting_render($state);

        $this->assertFalse($state['demo']);
        $this->assertSame([], $state['rows']);
        $this->assertStringContainsString('saprfc PHP extension is not loaded', (string) $state['error']);
        $this->assertStringContainsString('saprfc PHP extension is not loaded', $html);
        $this->assertStringNotContainsString('<td>', $html);
        $this->assertStringContainsString('style.css', $html);
        $this->assertStringContainsString('Personnel numbers', $html);
    }

    public function testMissingLogonIsReportedWhenTheExtensionIsPresent(): void
    {
        $state = ztesting_fetch(['SAP_ASHOST' => 'sap.example.com'], true);

        $this->assertStringContainsString('SAP logon is not configured', (string) $state['error']);
    }

    public function testARealClientWithoutTheExtensionDoesNotThrow(): void
    {
        $state = ztesting_fetch($this->configuredEnv(), true);

        $this->assertStringContainsString('not loaded', (string) $state['error']);
        $this->assertSame([], $state['rows']);
    }

    public function testSuccessfulCallRendersEscapedPersonnelNumbers(): void
    {
        $sap = $this->recordingSap();
        $sap->forcedStatus = SAPRFC_OK;
        $sap->forcedStatusText = 'ok';
        $sap->result = [
            'TEMPO' => [
                1 => ['PERNR' => '00009999'],
                2 => ['pernr' => '<10000000>'],
                3 => 'skip-me',
                4 => ['PERNR' => '   '],
            ],
        ];

        $state = ztesting_fetch($this->configuredEnv(), true, $sap);
        $html = ztesting_render($state);

        $this->assertNull($state['error']);
        $this->assertTrue($sap->loggedOff);
        $this->assertSame('ZTESTING', $sap->calls[0][0]);
        $this->assertSame([['TABLE', 'TEMPO', []]], $sap->calls[0][1]);
        $this->assertStringContainsString('>PERNR<', $html);
        $this->assertStringContainsString('00009999', $html);
        $this->assertStringContainsString('&lt;10000000&gt;', $html);
        $this->assertStringNotContainsString('<10000000>', $html);
        $this->assertStringNotContainsString('Sample data', $html);
    }

    public function testFailedCallDoesNotTreatTheStatusIntegerAsRows(): void
    {
        $sap = $this->recordingSap();
        $sap->forcedStatus = SAPRFC_ERROR;
        $sap->forcedStatusText = 'login failed';
        $sap->result = SAPRFC_ERROR;

        $state = ztesting_fetch($this->configuredEnv(), true, $sap);
        $html = ztesting_render($state);

        $this->assertSame([], $state['rows']);
        $this->assertSame('login failed', $state['error']);
        $this->assertStringContainsString('login failed', $html);
        $this->assertStringNotContainsString('<td>', $html);
        $this->assertTrue($sap->loggedOff);
    }

    public function testOkStatusWithoutATempoTableIsAnError(): void
    {
        $sap = $this->recordingSap();
        $sap->forcedStatus = SAPRFC_OK;
        $sap->result = ['OTHER' => []];

        $state = ztesting_fetch($this->configuredEnv(), true, $sap);

        $this->assertSame('ZTESTING returned no TEMPO table.', $state['error']);
    }

    public function testDemoModeRendersSampleRowsWithoutCallingSap(): void
    {
        foreach (['1', 'true', 'yes', 'on'] as $flag) {
            $sap = $this->recordingSap();
            $env = $this->configuredEnv();
            $env['SAP_DEMO'] = $flag;

            $state = ztesting_fetch($env, false, $sap);
            $html = ztesting_render($state);

            $this->assertTrue($state['demo'], $flag);
            $this->assertNull($state['error']);
            $this->assertSame([], $sap->calls);
            $this->assertFalse($sap->loggedOff);
            $this->assertStringContainsString('Sample data. SAP was not contacted.', $html);
            $this->assertStringContainsString('10000001', $html);
        }
    }

    public function testDemoFlagOffStillRequiresSap(): void
    {
        foreach (['0', 'false', 'no', '', 'off'] as $flag) {
            $state = ztesting_fetch(['SAP_DEMO' => $flag], false);
            $this->assertFalse($state['demo'], $flag);
            $this->assertStringContainsString('not loaded', (string) $state['error']);
        }
    }

    public function testEmptySuccessRendersAnEmptyState(): void
    {
        $html = ztesting_render([
            'rows' => [],
            'error' => null,
            'demo' => false,
        ]);

        $this->assertStringContainsString('No current personnel numbers were returned.', $html);
    }

    public function testErrorTextIsEscaped(): void
    {
        $html = ztesting_render([
            'rows' => [],
            'error' => '<b>bad</b>',
            'demo' => false,
        ]);

        $this->assertStringContainsString('&lt;b&gt;bad&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>bad</b>', $html);
    }

    public function testAbapSelectsAssignmentsActiveTodayIncludingBoundaries(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/abap/ztesting.abap');

        $this->assertIsString($source);
        $this->assertStringContainsString('begda <= date', $source);
        $this->assertStringContainsString('endda >= date', $source);
        $this->assertStringContainsString('remote-enabled', $source);
        $this->assertStringNotContainsString('GUI_DOWNLOAD', $source);
        $this->assertStringNotContainsString('OPEN DATASET', $source);
    }

    /**
     * @return array<string, string>
     */
    private function configuredEnv(): array
    {
        return [
            'SAP_ASHOST' => 'sap.example.com',
            'SAP_SYSNR' => '00',
            'SAP_CLIENT' => '100',
            'SAP_USER' => 'USER',
            'SAP_PASSWD' => 'secret',
            'SAP_LANG' => 'EN',
        ];
    }

    private function recordingSap(): RecordingSap
    {
        return new RecordingSap(['show_errors' => false]);
    }

    private function clearSapEnv(): void
    {
        foreach ($this->envKeys as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }
}
