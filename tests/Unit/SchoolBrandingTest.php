<?php

namespace Tests\Unit;

use App\Support\SchoolBranding;
use PHPUnit\Framework\TestCase;

final class SchoolBrandingTest extends TestCase
{
    public function test_all_three_canonical_bowen_schools_use_bowen_fallbacks(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2).'/public/assets/bowen-school/bowen-logo.jpg');
        foreach (['MMBOWEN01', 'MMBOWEN02', 'MMBOWEN03'] as $code) {
            $this->assertSame(
                ['/assets/bowen-school/bowen-logo.jpg', '/assets/bowen-school/bowen-logo.jpg'],
                SchoolBranding::logoFallbacks($code, true)
            );
        }
    }

    public function test_global_super_admin_and_head_finance_default_to_bowen_brand(): void
    {
        $this->assertSame(
            ['/assets/bowen-school/bowen-logo.jpg', '/assets/bowen-school/bowen-logo.jpg'],
            SchoolBranding::logoFallbacks(null, false)
        );
    }

    public function test_external_school_or_unresolved_school_context_does_not_inherit_bowen_logo(): void
    {
        foreach (['EXTERNAL01', 'MMBOWEN04', null] as $code) {
            $this->assertSame(
                ['/assets/horizontal-logo2.svg', '/assets/vertical-logo.svg'],
                SchoolBranding::logoFallbacks($code, true)
            );
        }
    }
}
