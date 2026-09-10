<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RejectEmailHeaderInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/_security/email-probe', static fn () => response()->json(['ok' => true]));
    }

    public function test_email_header_injection_is_rejected_before_controller_execution(): void
    {
        $this->postJson('/_security/email-probe', [
            'email' => "user@example.com\r\nBcc: attacker@example.com",
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_nested_email_header_injection_is_rejected(): void
    {
        $this->postJson('/_security/email-probe', [
            'students' => [['guardian_email' => "guardian@example.com\nCc: attacker@example.com"]],
        ])->assertUnprocessable()->assertJsonValidationErrors('students.0.guardian_email');
    }

    public function test_multiline_non_email_content_remains_supported(): void
    {
        $this->postJson('/_security/email-probe', [
            'email' => 'user@example.com',
            'message' => "line one\nline two",
        ])->assertOk()->assertJson(['ok' => true]);
    }
}
