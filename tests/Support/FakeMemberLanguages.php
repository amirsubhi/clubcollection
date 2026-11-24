<?php

namespace Tests\Support;

use App\Support\UiLang;

/**
 * Tests only: a copy of lang/member in a temporary folder with extra languages, so a test can show what a third
 * language does (the picker, "(beta)", <html lang>, its date order) without shipping one. UiLang reads the copy
 * until forgetFakeMemberLanguages() (call it in tearDown).
 */
trait FakeMemberLanguages
{
    private ?string $fakeLangDir = null;

    /**
     * Add lang/member/<code>.json to the copy: English's texts with $overrides, and $meta as its _meta (null: none).
     *
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, string>  $overrides
     */
    protected function fakeMemberLanguage(string $code, ?array $meta, array $overrides = []): void
    {
        $real = dirname(__DIR__, 2).'/lang/member';
        if ($this->fakeLangDir === null) {
            $this->fakeLangDir = sys_get_temp_dir().'/cp-member-lang-'.bin2hex(random_bytes(6));
            mkdir($this->fakeLangDir);
            foreach (glob($real.'/*.json') ?: [] as $file) {
                copy($file, $this->fakeLangDir.'/'.basename($file));
            }
        }
        $en = json_decode((string) file_get_contents($real.'/en.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($en['_meta']);
        $file = ($meta === null ? [] : ['_meta' => $meta]) + array_merge($en, $overrides);
        file_put_contents($this->fakeLangDir."/{$code}.json", json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        UiLang::useDirectory($this->fakeLangDir);
    }

    protected function forgetFakeMemberLanguages(): void
    {
        UiLang::useDirectory(null);
        if ($this->fakeLangDir !== null) {
            array_map('unlink', glob($this->fakeLangDir.'/*.json') ?: []);
            rmdir($this->fakeLangDir);
            $this->fakeLangDir = null;
        }
    }
}
