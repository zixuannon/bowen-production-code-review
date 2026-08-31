<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CentralFinanceLocalizationContractTest extends TestCase
{
    public function test_every_ascii_central_finance_translation_source_has_a_zh_cn_value(): void
    {
        $keys = $this->translationSources();
        $zh = json_decode((string) file_get_contents($this->basePath('resources/lang/zh-cn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($keys as $key) {
            if (! preg_match('/[A-Za-z]/', $key) || preg_match('/[一-龥]/u', $key)) {
                continue;
            }

            self::assertArrayHasKey($key, $zh, "Missing zh-cn Central Finance translation for [{$key}].");
            self::assertNotSame($key, $zh[$key], "Central Finance zh-cn value falls back to English for [{$key}].");
        }
    }

    public function test_chinese_source_labels_have_english_mode_equivalents(): void
    {
        $en = json_decode((string) file_get_contents($this->basePath('resources/lang/en.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($this->translationSources() as $key) {
            if (! preg_match('/[一-龥]/u', $key)) {
                continue;
            }

            self::assertArrayHasKey($key, $en, "Missing English Central Finance translation for Chinese source [{$key}].");
            self::assertMatchesRegularExpression('/[A-Za-z]/', $en[$key]);
        }
    }

    /** @return list<string> */
    private function translationSources(): array
    {
        $files = [$this->basePath('resources/views/layouts/sidebar.blade.php')];
        $directory = new \RecursiveDirectoryIterator($this->basePath('resources/views/central-finance'));
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        foreach (array_merge(
            glob($this->basePath('app/Http/Controllers/CentralFinance*.php')) ?: [],
            glob($this->basePath('app/Services/CentralFinance*.php')) ?: [],
            [$this->basePath('app/Http/Controllers/FinanceOperatingWorkspaceController.php')],
        ) as $file) {
            $files[] = $file;
        }
        $keys = [];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (str_ends_with($file, 'layouts/sidebar.blade.php')) {
                $contents = (string) strstr($contents, '@if ($hasCentralFinanceIdentity)');
                $contents = explode('{{-- XIAOBAILONG-INTEGRATION', $contents, 2)[0];
            }
            preg_match_all('/__\([\'\"]([^\'\"]+)[\'\"]\)/', $contents, $matches);
            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    private function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
