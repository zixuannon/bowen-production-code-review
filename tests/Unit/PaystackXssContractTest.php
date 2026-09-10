<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PaystackXssContractTest extends TestCase
{
    public function test_gateway_payloads_are_never_echoed_as_browser_html(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Http/Controllers/PaystackController.php');

        $this->assertStringNotContainsString('echo $response', $source);
        $this->assertStringNotContainsString('echo $result', $source);
        $this->assertStringContainsString('json_decode($payload, true, 512, JSON_THROW_ON_ERROR)', $source);
        $this->assertStringContainsString('return response()->json($decoded, $safeStatus)', $source);
    }

    public function test_verification_reference_is_bounded_and_url_encoded(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Http/Controllers/PaystackController.php');

        $this->assertStringContainsString("preg_match('/\\A[A-Za-z0-9._-]{1,100}\\z/'", $source);
        $this->assertStringContainsString('rawurlencode($reference)', $source);
        $this->assertStringContainsString("'allow_redirects' => false", $source);
        $this->assertStringNotContainsString("'error' => \$e->getMessage()", $source);
    }
}
