<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CustomJavascriptAssetVersionTest extends TestCase
{
    public function test_footer_versions_custom_javascript_by_its_content_hash(): void
    {
        $view = file_get_contents(resource_path('views/layouts/footer_js.blade.php'));
        $this->assertStringContainsString(
            "asset('/assets/js/custom/custom.js') }}?v={{ hash_file('sha256', public_path('assets/js/custom/custom.js'))",
            $view,
        );
        $this->assertStringContainsString(
            "?v=<?php echo e(hash_file('sha256', public_path('assets/js/custom/custom.js'))); ?>",
            Blade::compileString($view),
        );
    }

    public function test_content_hash_is_stable_until_the_javascript_changes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eschool-custom-js-');
        file_put_contents($path, 'const guardian = "existing";');

        try {
            $firstVersion = hash_file('sha256', $path);
            $secondVersion = hash_file('sha256', $path);

            $this->assertSame($firstVersion, $secondVersion);

            file_put_contents($path, 'const guardian = "updated";');

            $this->assertNotSame($firstVersion, hash_file('sha256', $path));
        } finally {
            @unlink($path);
        }
    }
}
