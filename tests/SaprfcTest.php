<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SaprfcTest extends TestCase
{
    public function testConstructorInitializesDefaults(): void
    {
        $sap = new saprfc();

        $this->assertFalse($sap->rfc_conn);
        $this->assertFalse($sap->func_id);
        $this->assertTrue($sap->show_status_flag);
        $this->assertFalse($sap->check_bapi_errors);
        $this->assertFalse($sap->debug);
        $this->assertSame([], $sap->logindata);
        $this->assertSame('', $sap->getStatus());
    }

    public function testConstructorAcceptsConfig(): void
    {
        $sap = new saprfc([
            'show_errors' => false,
            'debug' => true,
            'check_bapi_errors' => true,
            'logindata' => ['ASHOST' => 'sap.example.com'],
        ]);

        $this->assertFalse($sap->show_status_flag);
        $this->assertTrue($sap->debug);
        $this->assertTrue($sap->check_bapi_errors);
        $this->assertSame(['ASHOST' => 'sap.example.com'], $sap->logindata);
    }

    public function testExceptionConstantMatchesClassicRfcSdk(): void
    {
        $this->assertSame(0, SAPRFC_OK);
        $this->assertSame(1, SAPRFC_ERROR);
        $this->assertSame(2, SAPRFC_APPL_ERROR);
        $this->assertTrue(defined('SAPRFC_EXCEPTION'));
        $this->assertSame(2, SAPRFC_EXCEPTION);
    }

    public function testLoginWithoutExtensionSetsAnError(): void
    {
        $sap = new saprfc(['show_errors' => false]);

        $status = $sap->login();

        $this->assertSame(SAPRFC_ERROR, $status);
        $this->assertStringContainsString('not loaded', $sap->getStatusText());
        $this->assertFalse($sap->rfc_conn);
    }

    public function testLogoffWithoutAConnectionIsSafeToRepeat(): void
    {
        $sap = new saprfc(['show_errors' => false]);

        $sap->logoff();
        $sap->logoff();

        $this->assertFalse($sap->rfc_conn);
        $this->assertSame('', $sap->getStatus());
    }

    public function testCallFunctionRejectsABlankNameBeforeCheckingTheExtension(): void
    {
        $sap = new saprfc(['show_errors' => false]);

        $status = $sap->callFunction('   ', [['TABLE', 'TEMPO', []]]);

        $this->assertSame(SAPRFC_ERROR, $status);
        $this->assertStringContainsString('No Function-Name', $sap->getStatusText());
    }

    public function testCallFunctionRejectsParametersThatAreNotAList(): void
    {
        $sap = new saprfc(['show_errors' => false]);

        $status = $sap->callFunction('ZTESTING', 'TEMPO');

        $this->assertSame(SAPRFC_ERROR, $status);
        $this->assertStringContainsString('Parameters must be an array', $sap->getStatusText());
    }

    public function testCallFunctionRejectsAMalformedEntry(): void
    {
        $sap = new saprfc(['show_errors' => false]);

        $status = $sap->callFunction('ZTESTING', ['TEMPO']);

        $this->assertSame(SAPRFC_ERROR, $status);
        $this->assertStringContainsString('type, name', $sap->getStatusText());
    }

    public function testCallFunctionRejectsATableValueThatIsNotAnArray(): void
    {
        $sap = new saprfc(['show_errors' => false]);

        $status = $sap->callFunction('ZTESTING', [['TABLE', 'TEMPO', 'nope']]);

        $this->assertSame(SAPRFC_ERROR, $status);
        $this->assertStringContainsString('Table-Parameter TEMPO', $sap->getStatusText());
    }

    public function testCallFunctionRejectsAnUnknownParameterType(): void
    {
        $sap = new saprfc(['show_errors' => false]);

        $status = $sap->callFunction('ZTESTING', [['FOO', 'BAR']]);

        $this->assertSame(SAPRFC_ERROR, $status);
        $this->assertStringContainsString('Wrong Parameter-Type', $sap->getStatusText());
    }

    public function testCallFunctionReportsAMissingExtensionAfterValidArguments(): void
    {
        $sap = new saprfc(['show_errors' => false]);

        $status = $sap->callFunction('ZTESTING', [['TABLE', 'TEMPO', []]]);

        $this->assertSame(SAPRFC_ERROR, $status);
        $this->assertStringContainsString('saprfc PHP extension is not loaded', $sap->getStatusText());
    }

    public function testApplicationErrorTextToleratesMissingReturnKeys(): void
    {
        $sap = new saprfc(['show_errors' => false]);
        $sap->setStatus(SAPRFC_APPL_ERROR, ['MESSAGE' => 'nope']);

        $this->assertSame(' -: nope', $sap->getStatusText());
    }

    public function testApplicationErrorTextUsesTheBapiReturnStructure(): void
    {
        $sap = new saprfc(['show_errors' => false]);
        $sap->setStatus(SAPRFC_APPL_ERROR, [
            'TYPE' => 'E',
            'ID' => 'RP',
            'NUMBER' => '123',
            'MESSAGE' => 'failed',
        ]);

        $this->assertSame('E RP-123: failed', $sap->getStatusText());
    }

    public function testLongStatusEscapesErrorText(): void
    {
        $sap = new saprfc(['show_errors' => false]);
        $sap->setStatus(SAPRFC_ERROR, '<script>');

        $html = $sap->getStatusTextLong();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
