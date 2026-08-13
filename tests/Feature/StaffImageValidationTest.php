<?php

namespace Tests\Feature;

use App\Exceptions\UploadValidationException;
use App\Http\Controllers\StaffController;
use App\Models\User;
use App\Repositories\User\UserRepository;
use App\Services\UploadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StaffImageValidationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Staff 创建时上传正常 JPG 成功。
     */
    public function test_store_accepts_valid_jpg(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);
        $validator = $this->makeStoreValidator($file, 'jpg-test@example.com');

        $this->assertFalse($validator->fails());
    }

    /**
     * Staff 更新时上传正常 PNG/WebP 成功。
     */
    public function test_store_accepts_valid_png(): void
    {
        $file = UploadedFile::fake()->image('avatar.png', 100, 100);
        $validator = $this->makeStoreValidator($file, 'png-test@example.com');

        $this->assertFalse($validator->fails());
    }

    public function test_store_accepts_valid_webp(): void
    {
        $file = UploadedFile::fake()->image('avatar.webp', 100, 100);
        $validator = $this->makeStoreValidator($file, 'webp-test@example.com');

        $this->assertFalse($validator->fails());
    }

    public function test_update_accepts_valid_png(): void
    {
        $file = UploadedFile::fake()->image('avatar.png', 100, 100);
        $validator = $this->makeUpdateValidator($file, 'png-update@example.com');

        $this->assertFalse($validator->fails());
    }

    public function test_update_accepts_valid_webp(): void
    {
        $file = UploadedFile::fake()->image('avatar.webp', 100, 100);
        $validator = $this->makeUpdateValidator($file, 'webp-update@example.com');

        $this->assertFalse($validator->fails());
    }

    /**
     * 未上传新头像时保留旧头像（nullable 通过）。
     */
    public function test_image_is_nullable(): void
    {
        $validator = $this->makeStoreValidator(null);

        $this->assertFalse($validator->fails());
    }

    public function test_update_image_is_nullable(): void
    {
        $validator = $this->makeUpdateValidator(null);

        $this->assertFalse($validator->fails());
    }

    /**
     * .php56 被拒绝。
     */
    public function test_store_rejects_php56(): void
    {
        $file = UploadedFile::fake()->create('shell.php56', 100, 'application/octet-stream');
        $validator = $this->makeStoreValidator($file);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('image', $validator->errors()->toArray());
    }

    /**
     * .php 被拒绝。
     */
    public function test_store_rejects_php(): void
    {
        $file = UploadedFile::fake()->create('shell.php', 100, 'application/x-httpd-php');
        $validator = $this->makeStoreValidator($file);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('image', $validator->errors()->toArray());
    }

    /**
     * .phtml 被拒绝。
     */
    public function test_store_rejects_phtml(): void
    {
        $file = UploadedFile::fake()->create('shell.phtml', 100, 'application/octet-stream');
        $validator = $this->makeStoreValidator($file);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('image', $validator->errors()->toArray());
    }

    /**
     * .phar 被拒绝。
     */
    public function test_store_rejects_phar(): void
    {
        $file = UploadedFile::fake()->create('shell.phar', 100, 'application/octet-stream');
        $validator = $this->makeStoreValidator($file);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('image', $validator->errors()->toArray());
    }

    /**
     * JPEG 文件头后追加 PHP 代码的 polyglot：Laravel 'image' 规则通过
     * getimagesize() 检测，如果 JPEG 头部完整会判定为合法图片。
     * 此案例由 UploadService 的重新编码机制防御 —— 重新编码后 payload 不再存在。
     * 此处验证该 polyglot 能被 UploadService 正确处理。
     */
    public function test_upload_service_strips_jpeg_polyglot_payload(): void
    {
        $phpPayload = "<?php system(\$_GET['cmd']); ?>";
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);
        file_put_contents($file->getPathname(), $phpPayload, FILE_APPEND);
        $this->assertStringContainsString($phpPayload, file_get_contents($file->getPathname()));

        Storage::fake('public');
        $storedPath = UploadService::upload($file, 'staff', 'image');

        Storage::disk('public')->assertExists($storedPath);
        $this->assertStringNotContainsString($phpPayload, Storage::disk('public')->get($storedPath));
    }

    /**
     * 伪造 MIME 与扩展名不一致的文件被拒绝。
     * e.g. a .php file claiming to be image/jpeg.
     */
    public function test_store_rejects_mime_extension_mismatch(): void
    {
        $phpContent = "<?php echo 'not an image'; ?>";
        $tempPath = tempnam(sys_get_temp_dir(), 'test_mismatch_');
        file_put_contents($tempPath, $phpContent);

        // Pretend this .php content is actually a .jpg with image/jpeg MIME
        $file = new UploadedFile(
            $tempPath,
            'fake.jpg',
            'image/jpeg',
            null,
            true
        );

        $validator = $this->makeStoreValidator($file);

        // The 'image' rule should detect the MIME/content mismatch
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('image', $validator->errors()->toArray());

        unlink($tempPath);
    }

    /**
     * 超过 2MB 的 Staff 图片被拒绝。
     */
    public function test_store_rejects_image_over_2mb(): void
    {
        // Create a file larger than 2048 KB
        $file = UploadedFile::fake()->create('large.jpg', 3000); // 3000 KB

        $validator = $this->makeStoreValidator($file);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('image', $validator->errors()->toArray());
    }

    /**
     * 允许 2MB 以内的图片。
     */
    public function test_store_accepts_image_under_2mb(): void
    {
        $file = UploadedFile::fake()->image('normal.jpg', 100, 100)->size(1024); // 1024 KB

        $validator = $this->makeStoreValidator($file);

        $this->assertFalse($validator->fails());
    }

    /**
     * GIF 被拒绝（不在允许列表中）。
     */
    public function test_store_rejects_gif(): void
    {
        $file = UploadedFile::fake()->image('avatar.gif', 100, 100);
        $validator = $this->makeStoreValidator($file);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('image', $validator->errors()->toArray());
    }

    /**
     * SVG 被拒绝（不在允许列表中，且可能包含 XSS）。
     */
    public function test_store_rejects_svg(): void
    {
        $file = UploadedFile::fake()->create('icon.svg', 100, 'image/svg+xml');
        $validator = $this->makeStoreValidator($file);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('image', $validator->errors()->toArray());
    }

    /**
     * API 请求：UploadValidationException → 422 JSON，不暴露堆栈。
     */
    public function test_api_upload_exception_returns_json_422(): void
    {
        $exception = new \App\Exceptions\UploadValidationException('Blocked for test.', 'image');
        $handler = new \App\Exceptions\Handler(app());

        $request = \Illuminate\Http\Request::create('/api/dummy', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = $handler->render($request, $exception);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertTrue($response->isClientError());

        $body = $response->getData(true);
        $this->assertArrayHasKey('error', $body);
        $this->assertTrue($body['error']);
        $this->assertEquals('File upload rejected.', $body['message']);
    }

    /**
     * Web 表单请求：UploadValidationException → 302 redirect，
     * session 中包含对应字段的 validation error。
     */
    public function test_web_upload_exception_redirects_with_errors(): void
    {
        $this->withoutMiddleware();

        $exception = new \App\Exceptions\UploadValidationException('Blocked extension', 'image');
        $handler = new \App\Exceptions\Handler(app());

        // Simulate a browser form POST (no JSON Accept header)
        $request = \Illuminate\Http\Request::create('/staff/store', 'POST', [], [], [], [
            'HTTP_REFERER' => 'http://localhost/staff/create',
        ]);

        $response = $handler->render($request, $exception);

        // Browser request → redirect back
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertTrue($response->isRedirection());

        // Session should contain the validation error
        $errors = session('errors');
        $this->assertNotNull($errors, 'Session should have validation errors.');
        $this->assertTrue($errors->has('image'), 'Errors should contain the "image" field.');
    }

    /**
     * 危险上传应产生脱敏 warning 日志（user_id、route、IP、原因）。
     * 不得记录文件内容、Cookie、Token。
     */
    public function test_dangerous_upload_logs_desensitized_warning(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                // Context must contain only safe fields
                $allowed = ['reason', 'user_id', 'route', 'ip', 'field'];
                $actual  = array_keys($context);

                // No extra (sensitive) keys
                $extra = array_diff($actual, $allowed);
                if (!empty($extra)) {
                    return false;
                }

                // Required keys must be present
                return isset($context['reason'])
                    && isset($context['route'])
                    && isset($context['ip'])
                    && $context['field'] === 'image'
                    && strpos($context['reason'], 'Cookie') === false
                    && strpos($context['reason'], 'token') === false;
            });

        $exception = new \App\Exceptions\UploadValidationException('Blocked extension', 'image');
        $handler = new \App\Exceptions\Handler(app());

        $request = \Illuminate\Http\Request::create('/staff/store', 'POST', [], [], [], [
            'HTTP_REFERER' => 'http://localhost/staff/create',
        ]);

        $handler->render($request, $exception);
        // Log::shouldReceive will auto-verify
    }

    /**
     * 响应体中不得包含堆栈跟踪或服务器路径。
     */
    public function test_upload_exception_response_has_no_stack_trace(): void
    {
        $exception = new \App\Exceptions\UploadValidationException('Blocked for test.', 'image');
        $handler = new \App\Exceptions\Handler(app());

        // API variant
        $apiRequest = \Illuminate\Http\Request::create('/api/dummy', 'POST');
        $apiRequest->headers->set('Accept', 'application/json');
        $apiResponse = $handler->render($apiRequest, $exception);
        $apiBody = json_encode($apiResponse->getData(true));
        $this->assertStringNotContainsStringIgnoringCase('Stack trace', $apiBody);
        $this->assertStringNotContainsStringIgnoringCase('/app/', $apiBody);
        $this->assertStringNotContainsStringIgnoringCase('vendor', $apiBody);

        // Web variant
        $webRequest = \Illuminate\Http\Request::create('/staff/store', 'POST', [], [], [], [
            'HTTP_REFERER' => 'http://localhost/staff/create',
        ]);
        $webResponse = $handler->render($webRequest, $exception);
        $webContent = method_exists($webResponse, 'content') ? $webResponse->content() : (string) $webResponse;

        // Redirect itself won't contain stack traces, but we still verify
        $this->assertStringNotContainsStringIgnoringCase('Stack trace', $webContent);
        $this->assertStringNotContainsStringIgnoringCase('/app/', $webContent);
    }

    public function test_valid_staff_image_replacement_updates_the_record_and_cleans_up_the_old_file(): void
    {
        [$user, $oldPath] = $this->staffUserWithExistingImage();

        $updated = app(UserRepository::class)->update(
            $user->id,
            ['image' => UploadedFile::fake()->image('replacement.png', 80, 80)]
        );

        $this->assertSame($user->id, $updated->id);
        $this->assertNotSame($oldPath, $updated->getRawOriginal('image'));
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($updated->getRawOriginal('image'));
    }

    public function test_invalid_staff_image_replacement_keeps_the_existing_file_and_record_intact(): void
    {
        [$user, $oldPath] = $this->staffUserWithExistingImage();
        $invalid = UploadedFile::fake()->create('spoofed.jpg', 10, 'text/plain');

        try {
            app(UserRepository::class)->update($user->id, ['image' => $invalid]);
            $this->fail('An invalid image must not be accepted by UploadService.');
        } catch (UploadValidationException) {
            $this->assertSame($oldPath, User::findOrFail($user->id)->getRawOriginal('image'));
            Storage::disk('public')->assertExists($oldPath);
        }
    }

    /**
     * Create a Validator instance with store() rules.
     */
    private function makeStoreValidator($image, string $email = 'valid-store-test@example.com'): \Illuminate\Validation\Validator
    {
        return Validator::make(
            [
                'first_name' => 'Test',
                'last_name' => 'User',
                'mobile' => '1234567890',
                'email' => $email,
                'role_id' => 1,
                'dob' => '2000-01-01',
                'image' => $image,
            ],
            [
                'first_name' => 'required',
                'last_name' => 'required',
                'mobile' => 'required|digits_between:6,15',
                'email' => 'required|email|max:255|regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/|unique:users,email',
                'role_id' => 'required|numeric',
                'dob' => 'required',
                'image' => StaffController::STAFF_IMAGE_RULES,
            ]
        );
    }

    /**
     * Create a Validator instance with update() rules (unique excludes current ID).
     */
    private function makeUpdateValidator($image, string $email = 'valid-update-test@example.com'): \Illuminate\Validation\Validator
    {
        $userId = 99999;
        return Validator::make(
            [
                'first_name' => 'Test',
                'last_name' => 'User',
                'mobile' => '1234567890',
                'email' => $email,
                'role_id' => 1,
                'dob' => '2000-01-01',
                'image' => $image,
            ],
            [
                'first_name' => 'required',
                'last_name' => 'required',
                'mobile' => 'required|digits_between:6,15',
                'email' => 'required|email|max:255|regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/|unique:users,email,' . $userId,
                'role_id' => 'required|numeric',
                'dob' => 'required',
                'image' => StaffController::STAFF_IMAGE_RULES,
            ]
        );
    }

    private function staffUserWithExistingImage(): array
    {
        Storage::fake('public');
        $oldPath = '1/user/staff-image-old-' . bin2hex(random_bytes(6)) . '.jpg';
        Storage::disk('public')->put($oldPath, 'synthetic-old-image');
        $userId = DB::table('users')->insertGetId([
            'first_name' => 'Staff',
            'last_name' => 'Upload Test',
            'email' => 'staff-upload-' . bin2hex(random_bytes(6)) . '@local.test',
            'mobile' => '1234567890',
            'password' => bcrypt('local-only'),
            'school_id' => 1,
            'image' => $oldPath,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::findOrFail($userId);
        Auth::login($user);

        return [$user, $oldPath];
    }
}
