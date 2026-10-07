<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveSchoolHost;
use App\Http\Middleware\TrustProxies;
use App\Services\SchoolHostResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class SchoolHostResolverTest extends TestCase
{
    private string $database;
    private array $originalMysql;
    private string $originalAppUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'school_host_registry_');
        $this->originalMysql = config('database.connections.mysql');
        $this->originalAppUrl = (string) config('app.url');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');
        Config::set('app.url', 'https://school.example.test');

        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('domain_type')->nullable();
            $table->string('database_name')->nullable();
            $table->boolean('status')->default(1);
            $table->boolean('installed')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });
        DB::connection('mysql')->table('schools')->insert([
            $this->schoolRow(15, 'Zixuan', 'zixuan', 'default'),
            $this->schoolRow(16, 'Future School', 'future-school', 'default'),
            $this->schoolRow(17, 'Custom School', 'portal.example.org', 'custom'),
            $this->schoolRow(18, 'Inactive School', 'inactive', 'default', 0),
            array_merge($this->schoolRow(19, 'Deleted School', 'deleted', 'default'), ['deleted_at' => now()]),
            array_merge($this->schoolRow(21, 'Uninstalled School', 'uninstalled', 'default'), ['installed' => 0]),
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        Config::set('database.connections.mysql', $this->originalMysql);
        Config::set('app.url', $this->originalAppUrl);
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_registered_default_and_custom_hosts_resolve_only_their_exact_school(): void
    {
        $resolver = app(SchoolHostResolver::class);

        $zixuan = $resolver->resolveTenantHost('zixuan.school.example.test');
        $future = $resolver->resolveTenantHost('future-school.school.example.test');
        $this->assertSame(15, $zixuan?->id);
        $this->assertSame('eschool_tenant_15', $zixuan?->database_name);
        $this->assertSame(16, $future?->id);
        $this->assertSame('eschool_tenant_16', $future?->database_name);
        $this->assertSame(17, $resolver->resolveTenantHost('portal.example.org')?->id);
        $this->assertNull($resolver->resolveTenantHost('zixuan.attacker.example.test'));
        $this->assertNull($resolver->resolveTenantHost('zixuan.example.test'));
    }

    public function test_unknown_inactive_deleted_ip_and_ambiguous_hosts_are_denied(): void
    {
        $resolver = app(SchoolHostResolver::class);
        foreach ([
            'random.school.example.test',
            'inactive.school.example.test',
            'deleted.school.example.test',
            'uninstalled.school.example.test',
            '43.160.241.126',
            'unknown-custom.example.org',
        ] as $host) {
            try {
                $resolver->schoolForRequestHost($host);
                $this->fail("Host {$host} must fail closed.");
            } catch (NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }

        DB::connection('mysql')->table('schools')->insert($this->schoolRow(20, 'Duplicate', 'zixuan', 'default'));
        try {
            $resolver->schoolForRequestHost('zixuan.school.example.test');
            $this->fail('Ambiguous registered hosts must fail closed.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_untrusted_forwarded_host_does_not_select_a_registered_school(): void
    {
        $request = Request::create('http://43.160.241.126/', 'GET', [], [], [], [
            'HTTP_X_FORWARDED_HOST' => 'zixuan.school.example.test',
        ]);

        $this->assertSame('43.160.241.126', $request->getHost());
        $this->expectException(NotFoundHttpException::class);
        app(TrustProxies::class)->handle($request, fn (Request $request) =>
            app(ResolveSchoolHost::class)->handle($request, fn () => response('unexpected'))
        );
    }

    public function test_request_middleware_binds_only_the_registry_resolved_school(): void
    {
        $request = Request::create('https://zixuan.school.example.test/');
        $response = app(ResolveSchoolHost::class)->handle($request, fn (Request $request) => response((string) $request->attributes->get('resolved_school_host_id')));

        $this->assertSame('15', $response->getContent());
    }

    public function test_only_the_configured_application_root_is_an_allowed_non_tenant_host(): void
    {
        $resolver = app(SchoolHostResolver::class);

        $this->assertNull($resolver->schoolForRequestHost('school.example.test'));
        $this->assertNotNull($resolver->schoolForRequestHost('portal.example.org'));
    }

    private function schoolRow(int $id, string $name, string $domain, string $domainType, int $status = 1): array
    {
        return [
            'id' => $id, 'name' => $name, 'domain' => $domain, 'domain_type' => $domainType,
            'database_name' => 'eschool_tenant_'.$id, 'status' => $status, 'installed' => 1,
            'deleted_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
