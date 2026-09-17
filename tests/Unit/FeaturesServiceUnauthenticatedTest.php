<?php

namespace Tests\Unit;

use App\Services\FeaturesService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

final class FeaturesServiceUnauthenticatedTest extends TestCase
{
    public function test_feature_lookup_is_safe_without_an_authenticated_tenant(): void
    {
        Auth::logout();

        $this->assertSame([], FeaturesService::getFeatures());
        $this->assertFalse(FeaturesService::hasFeature('central-finance'));
    }
}
