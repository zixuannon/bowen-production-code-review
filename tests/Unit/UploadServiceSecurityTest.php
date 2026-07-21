<?php

namespace Tests\Unit;

use App\Exceptions\UploadValidationException;
use App\Services\UploadService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class UploadServiceSecurityTest extends TestCase
{
    /**
     * Test that blocked extensions are rejected.
     */
    public function test_blocked_extensions_are_rejected(): void
    {
        $blocked = UploadService::BLOCKED_EXTENSIONS;
        $this->assertContains('php', $blocked);
        $this->assertContains('php56', $blocked);
        $this->assertContains('phtml', $blocked);
        $this->assertContains('phar', $blocked);
        $this->assertContains('pht', $blocked);
        $this->assertContains('cgi', $blocked);
        $this->assertContains('pl', $blocked);
        $this->assertContains('py', $blocked);
        $this->assertContains('sh', $blocked);
        $this->assertContains('shtml', $blocked);
        $this->assertContains('htaccess', $blocked);
    }

    /**
     * Test that validateFilename rejects path traversal sequences.
     */
    public function test_validate_filename_rejects_path_traversal(): void
    {
        $method = $this->getMethod('validateFilename');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, '../../../etc/passwd.jpg');
    }

    public function test_validate_filename_rejects_forward_slash(): void
    {
        $method = $this->getMethod('validateFilename');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, 'etc/passwd.jpg');
    }

    public function test_validate_filename_rejects_backslash(): void
    {
        $method = $this->getMethod('validateFilename');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, 'etc\\passwd.jpg');
    }

    public function test_validate_filename_rejects_null_byte(): void
    {
        $method = $this->getMethod('validateFilename');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, "avatar.jpg\0.php");
    }

    /**
     * Test that double-extension attacks (e.g. avatar.php56.jpg) are rejected.
     */
    public function test_validate_filename_rejects_double_extension_php56(): void
    {
        $method = $this->getMethod('validateFilename');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, 'avatar.php56.jpg');
    }

    public function test_validate_filename_rejects_double_extension_php(): void
    {
        $method = $this->getMethod('validateFilename');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, 'avatar.php.jpg');
    }

    public function test_validate_filename_rejects_double_extension_phtml(): void
    {
        $method = $this->getMethod('validateFilename');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, 'image.phtml.png');
    }

    public function test_validate_filename_rejects_double_extension_phar(): void
    {
        $method = $this->getMethod('validateFilename');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, 'image.phar.jpeg');
    }

    /**
     * Test that valid filenames pass validation.
     */
    public function test_validate_filename_accepts_normal_names(): void
    {
        $method = $this->getMethod('validateFilename');

        // These should not throw
        $method->invoke(null, 'avatar.jpg');
        $method->invoke(null, 'profile.png');
        $method->invoke(null, 'document.pdf');
        $method->invoke(null, 'report.user.csv');

        // If we get here without exception, test passes
        $this->assertTrue(true);
    }

    /**
     * Test that sanitizePath rejects path traversal.
     */
    public function test_sanitize_path_rejects_traversal(): void
    {
        $method = $this->getMethod('sanitizePath');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, '../etc');
    }

    public function test_sanitize_path_rejects_null_byte(): void
    {
        $method = $this->getMethod('sanitizePath');

        $this->expectException(UploadValidationException::class);
        $method->invoke(null, "user\0hidden");
    }

    public function test_sanitize_path_accepts_normal_path(): void
    {
        $method = $this->getMethod('sanitizePath');

        $result = $method->invoke(null, 'user');
        $this->assertEquals('user', $result);
    }

    public function test_sanitize_path_trims_slashes(): void
    {
        $method = $this->getMethod('sanitizePath');

        $result = $method->invoke(null, '/user/');
        $this->assertEquals('user', $result);
    }

    /**
     * Test resolveSafeExtension returns canonical extension for image MIME types.
     */
    public function test_resolve_safe_extension_maps_jpeg_mime(): void
    {
        $method = $this->getMethod('resolveSafeExtension');

        $result = $method->invoke(null, 'jpg', 'image/jpeg');
        $this->assertEquals('jpg', $result);
    }

    public function test_resolve_safe_extension_maps_png_mime(): void
    {
        $method = $this->getMethod('resolveSafeExtension');

        $result = $method->invoke(null, 'png', 'image/png');
        $this->assertEquals('png', $result);
    }

    public function test_resolve_safe_extension_maps_webp_mime(): void
    {
        $method = $this->getMethod('resolveSafeExtension');

        $result = $method->invoke(null, 'webp', 'image/webp');
        $this->assertEquals('webp', $result);
    }

    public function test_resolve_safe_extension_falls_back_for_unknown_mime(): void
    {
        $method = $this->getMethod('resolveSafeExtension');

        $result = $method->invoke(null, 'csv', 'text/csv');
        $this->assertEquals('csv', $result);
    }

    /**
     * Test that image extension indicators are defined and cover common types.
     */
    public function test_image_extension_indicators_are_defined(): void
    {
        $indicators = UploadService::IMAGE_EXTENSION_INDICATORS;
        $this->assertContains('jpg', $indicators);
        $this->assertContains('jpeg', $indicators);
        $this->assertContains('png', $indicators);
        $this->assertContains('gif', $indicators);
        $this->assertContains('webp', $indicators);
        $this->assertContains('svg', $indicators);
        $this->assertContains('bmp', $indicators);
    }

    /**
     * Test that blocked extensions do NOT appear in image indicators.
     */
    public function test_blocked_extensions_not_in_image_indicators(): void
    {
        $blocked = UploadService::BLOCKED_EXTENSIONS;
        $indicators = UploadService::IMAGE_EXTENSION_INDICATORS;

        foreach ($blocked as $ext) {
            $this->assertNotContains($ext, $indicators, "Blocked extension '$ext' should NOT be an image indicator.");
        }
    }

    /**
     * Test resolveSafeExtension returns canonical jpg even for 'jpeg' client ext.
     */
    public function test_resolve_safe_extension_canonicalizes_jpeg_to_jpg(): void
    {
        $method = $this->getMethod('resolveSafeExtension');

        $result = $method->invoke(null, 'jpeg', 'image/jpeg');
        $this->assertEquals('jpg', $result);
    }

    /**
     * UploadValidationException must extend RuntimeException so that
     * try/catch blocks catching RuntimeException still work.
     * It must also carry the upload field name for targeted validation errors.
     */
    public function test_upload_validation_exception_is_a_runtime_exception(): void
    {
        $e = new UploadValidationException('test');
        $this->assertInstanceOf(\RuntimeException::class, $e);

        // Default field
        $this->assertEquals('file', $e->getField());

        // Custom field
        $e2 = new UploadValidationException('test', 'image');
        $this->assertEquals('image', $e2->getField());
    }

    /**
     * Helper to access private/protected methods via reflection.
     */
    private function getMethod(string $name): \ReflectionMethod
    {
        $class = new ReflectionClass(UploadService::class);
        return $class->getMethod($name);
    }
}
