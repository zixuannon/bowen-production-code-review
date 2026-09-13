<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UiPolishP3ContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_shared_layout_exposes_named_navigation_loading_and_bowen_branding(): void
    {
        $master = $this->read('resources/views/layouts/master.blade.php');
        $sidebar = $this->read('resources/views/layouts/sidebar.blade.php');
        $footer = $this->read('resources/views/layouts/footer.blade.php');
        $include = $this->read('resources/views/layouts/include.blade.php');

        $this->assertStringContainsString('class="sidebar-fixed ui-page-loading', $master);
        $this->assertStringContainsString('class="ui-page-progress"', $master);
        $this->assertStringContainsString("__('Loading content')", $master);
        $this->assertStringContainsString("aria-label=\"{{ __('Primary navigation') }}\"", $sidebar);
        $this->assertStringContainsString('data-ui-sidebar-nav', $sidebar);
        $this->assertStringContainsString('aria-current="page"', $sidebar);
        $this->assertStringContainsString('class="footer app-footer"', $footer);
        $this->assertStringContainsString('BOWEN SCHOOL', $footer);
        $this->assertStringContainsString("hash_file('sha256', public_path('assets/css/custom.css'))", $include);
    }

    public function test_p3_tokens_and_interaction_states_are_shared(): void
    {
        $css = $this->read('public/assets/css/custom.css');

        foreach ([
            '--ui-radius-sm', '--ui-radius-md', '--ui-shadow-sm', '--ui-success', '--ui-warning',
            '.ui-page-progress', '.ui-skeleton', '.sidebar .nav .nav-item .nav-link.active',
            '.fixed-table-loading.open', '.app-footer__brand', ':focus-visible',
            '@media (prefers-reduced-motion: reduce)',
        ] as $contract) {
            $this->assertStringContainsString($contract, $css, "Missing P3 CSS contract: {$contract}");
        }
    }

    public function test_p3_javascript_enhances_sidebar_tooltips_and_loading_without_business_calls(): void
    {
        $script = $this->read('public/assets/js/custom/custom.js');

        foreach ([
            'function initializeUiPolishP3',
            'function polishSidebarHierarchy',
            "link.setAttribute('aria-current', 'page')",
            'function polishIconTooltips',
            'function setBootstrapTableBusy',
            "document.body.classList.remove('ui-page-loading')",
            "refresh.bs.table",
            "load-success.bs.table load-error.bs.table post-body.bs.table",
            "if (trigger) trigger.focus();",
        ] as $contract) {
            $this->assertStringContainsString($contract, $script, "Missing P3 JS contract: {$contract}");
        }

        $this->assertStringNotContainsString('fetch(', $this->p3Section($script));
        $this->assertStringNotContainsString('$.ajax', $this->p3Section($script));
        $this->assertStringNotContainsString('.blur()', $this->p3Section($script));
    }

    public function test_p3_language_keys_are_localized(): void
    {
        foreach (['en', 'cn', 'zh-cn'] as $locale) {
            $catalog = json_decode($this->read("resources/lang/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
            foreach (['Primary navigation', 'Loading content', 'Workspace'] as $key) {
                $this->assertArrayHasKey($key, $catalog);
                $this->assertNotSame('', trim((string) $catalog[$key]));
            }
        }
    }

    private function p3Section(string $script): string
    {
        return explode('/* UI Polish P3', $script, 2)[1] ?? '';
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root.'/'.$path);
    }
}
