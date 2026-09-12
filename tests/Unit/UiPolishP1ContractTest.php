<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UiPolishP1ContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_header_and_sidebar_have_one_named_navigation_search_and_named_icon_controls(): void
    {
        $header = $this->read('resources/views/layouts/header.blade.php');
        $sidebar = $this->read('resources/views/layouts/sidebar.blade.php');

        $this->assertSame(1, substr_count($sidebar, 'id="menu-search"'));
        $this->assertStringNotContainsString('menu-search-mini', $sidebar);
        $this->assertStringContainsString('aria-label="{{ __(\'search_menu\') }}"', $sidebar);

        foreach ([
            'Toggle sidebar navigation',
            'Change language',
            'Open user menu',
            'Open navigation menu',
        ] as $label) {
            $this->assertStringContainsString("aria-label=\"{{ __('{$label}') }}\"", $header);
        }
    }

    public function test_ui_language_catalogs_cover_the_p1_raw_keys(): void
    {
        $keys = [
            'session_years', 'my_attendance', 'student_admission', 'admission_inquiries',
            'student_details', 'add_bulk_data', 'class_section', 'file_upload',
            'send_notification', 'download_dummy_file', 'all_rights_reserved',
            'important_note', 'click_here', 'schools_details', 'database_backup',
            'system_update', 'verify_email', 'active_plan', 'assign_roll_no',
            'upload_profile_images', 'reset_password', 'custom_fields', 'school_phone',
        ];

        foreach (['en', 'cn', 'zh-cn'] as $locale) {
            $catalog = json_decode($this->read("resources/lang/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $catalog, "{$locale} is missing {$key}");
                $this->assertNotSame($key, $catalog[$key], "{$locale} still exposes {$key}");
            }

            foreach ([
                'resources/views/layouts/header.blade.php',
                'resources/views/layouts/sidebar.blade.php',
                'resources/views/schools/index.blade.php',
                'resources/views/students/add_bulk_data.blade.php',
            ] as $view) {
                preg_match_all('/__\([\'\"]([^\'\"]+)[\'\"]\)/', $this->read($view), $matches);
                foreach ($matches[1] as $key) {
                    if (! str_contains($key, '_')) {
                        continue;
                    }

                    $this->assertArrayHasKey($key, $catalog, "{$locale} is missing {$key} referenced by {$view}");
                    $this->assertNotSame($key, $catalog[$key], "{$locale} still exposes {$key} from {$view}");
                }
            }
        }
    }

    public function test_tables_and_dynamic_actions_fail_closed_to_readable_accessible_output(): void
    {
        $formatter = $this->read('public/assets/js/custom/bootstrap-table/formatter.js');
        $custom = $this->read('public/assets/js/custom/custom.js');

        $this->assertStringContainsString("String(value) === 'undefined'", $formatter);
        $this->assertStringContainsString('function plainTextFormatter(value)', $formatter);
        $this->assertStringContainsString('var guardian = row && row.guardian ? row.guardian : {};', $formatter);
        $this->assertStringContainsString('translatedLabel("verified", "Verified")', $formatter);
        $this->assertStringContainsString('polishInteractiveAccessibility', $custom);
        $this->assertStringContainsString("element.setAttribute('aria-label', inferAccessibleName(element))", $custom);
        $this->assertStringContainsString("state.className = 'ui-empty-state'", $custom);
    }

    public function test_every_dashboard_chart_helper_checks_its_dom_target(): void
    {
        $functions = $this->read('public/assets/js/custom/function.js');
        $dashboard = $this->read('resources/views/dashboard.blade.php');

        $this->assertStringContainsString('function getApexChartTarget(selector)', $functions);
        $this->assertStringContainsString('function safeChartNumbers(values)', $functions);
        $this->assertStringContainsString('function renderApexChart(chartElement, options)', $functions);
        $this->assertStringContainsString('renderResult.catch(function ()', $functions);
        foreach (['#expenseChart', '#gender-ratio-chart', '#attendanChart', '#subscriptionTransactionChart', '#addonChart', '#packageChart'] as $selector) {
            $this->assertStringContainsString("getApexChartTarget(\"{$selector}\")", $functions);
        }
        $this->assertStringContainsString('const chartElement = document.querySelector("#fees_details_chart")', $functions);
        $this->assertStringNotContainsString('window.onload = setTimeout', $dashboard);
    }

    public function test_shared_error_empty_and_sticky_action_states_are_present(): void
    {
        $css = $this->read('public/assets/css/custom.css');
        $school = $this->read('resources/views/schools/index.blade.php');

        foreach (['.ui-empty-state', '.ui-error-state', '.ui-sticky-actions', '.modal .modal-footer'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }

        foreach (['400', '403', '404', '503'] as $status) {
            $this->assertStringContainsString("@extends('errors.layout')", $this->read("resources/views/errors/{$status}.blade.php"));
        }

        $this->assertStringContainsString('id="school-code-lock-reason"', $school);
        $this->assertStringContainsString('canonical tenant identity and cannot be edited here', $school);

        foreach ([
            'resources/views/schools/index.blade.php',
            'resources/views/bank-account/index.blade.php',
            'resources/views/bank-account/transfer/index.blade.php',
            'resources/views/bank-account/handover/index.blade.php',
            'resources/views/students/add_bulk_data.blade.php',
        ] as $view) {
            $this->assertStringContainsString('ui-sticky-actions', $this->read($view), "{$view} has no persistent primary action");
        }
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root.'/'.$path);
    }
}
