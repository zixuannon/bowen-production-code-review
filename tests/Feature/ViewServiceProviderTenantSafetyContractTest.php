<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ViewServiceProviderTenantSafetyContractTest extends TestCase
{
    public function test_global_composers_use_the_trusted_tenant_context_service_before_tenant_reads(): void
    {
        $source = (string) file_get_contents(app_path('Providers/ViewServiceProvider.php'));

        $this->assertStringContainsString('trustedTenantUserForCurrentRequest(request())', $source);
        $this->assertStringNotContainsString('auth()->user()', $source);
        $this->assertStringNotContainsString('Auth::user()', $source);
        $this->assertSame(4, substr_count($source, '$tenantViewContext()[1] !== null'));
    }
}
