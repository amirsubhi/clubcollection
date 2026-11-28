<?php

namespace Tests\Unit\Updater;

use ClubPortalUpdater\Htaccess;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../public/update/lib/load.php';

class HtaccessTest extends TestCase
{
    private const BLOCK = "# php -- BEGIN cPanel-generated handler, do not edit\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php84___lsphp .php .php8 .phtml\n</IfModule>\n# php -- END cPanel-generated handler, do not edit\n";

    public function test_the_old_block_is_carried_into_the_new_file(): void
    {
        $old = "Options -Indexes\n".self::BLOCK."# tail\n";
        $new = "RewriteEngine On\n";

        $out = Htaccess::carryCpanelBlock($old, $new);

        $this->assertSame(self::BLOCK."RewriteEngine On\n", $out);
    }

    public function test_a_block_the_new_file_already_has_is_not_duplicated(): void
    {
        $new = "RewriteEngine On\n".str_replace('php84', 'php83', self::BLOCK)."# end\n";

        $out = Htaccess::carryCpanelBlock("x\n".self::BLOCK, $new);

        $this->assertSame(1, substr_count($out, 'BEGIN cPanel-generated handler'));
        $this->assertSame(1, substr_count($out, 'END cPanel-generated handler'));
        $this->assertStringContainsString('php84', $out, 'the host\'s own handler wins');
        $this->assertStringContainsString("RewriteEngine On\n# end\n", $out);
        $this->assertSame($out, Htaccess::carryCpanelBlock("x\n".self::BLOCK, $out), 'carrying twice changes nothing');
    }

    public function test_without_an_old_block_the_new_file_is_unchanged(): void
    {
        $this->assertSame("a\n", Htaccess::carryCpanelBlock("b\n", "a\n"));
    }

    public function test_an_unfinished_old_block_is_not_carried(): void
    {
        $this->assertSame("a\n", Htaccess::carryCpanelBlock("# php -- BEGIN cPanel-generated handler\nHALF\n", "a\n"));
    }

    public function test_a_file_without_a_trailing_newline_still_gets_a_clean_block(): void
    {
        $out = Htaccess::carryCpanelBlock(rtrim(self::BLOCK), 'new');

        $this->assertSame(self::BLOCK.'new', $out);
    }
}
