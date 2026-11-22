<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MySQL and MariaDB refuse index and foreign key names longer than 64 characters; SQLite does not, so a long
 * auto-generated name passes the default test run and then breaks `migrate` on the production database.
 */
class IndexNameLengthTest extends TestCase
{
    public function test_every_index_and_foreign_key_name_fits_mysql_and_mariadb(): void
    {
        $tooLong = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (strlen($index['name']) > 64) {
                    $tooLong[] = "{$table}: index {$index['name']}";
                }
            }
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (is_string($fk['name'] ?? null) && strlen($fk['name']) > 64) {
                    $tooLong[] = "{$table}: foreign key {$fk['name']}";
                }
            }
        }

        $this->assertSame([], $tooLong, 'Give these an explicit name of at most 64 characters.');
    }
}
