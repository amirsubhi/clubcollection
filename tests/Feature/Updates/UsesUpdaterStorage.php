<?php

namespace Tests\Feature\Updates;

use App\Support\Updates\UpdaterFiles;
use Illuminate\Support\Facades\File;

/** A throwaway storage folder, so a test never touches the real storage/app/updater. */
trait UsesUpdaterStorage
{
    private string $tmpStorage;

    private function useUpdaterStorage(): void
    {
        $this->tmpStorage = sys_get_temp_dir().'/updout-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->tmpStorage.'/framework');
        File::ensureDirectoryExists($this->tmpStorage.'/app');
        touch($this->tmpStorage.'/app/.installed');
        $this->app->useStoragePath($this->tmpStorage);
        File::ensureDirectoryExists(UpdaterFiles::dir());
    }

    private function dropUpdaterStorage(): void
    {
        File::deleteDirectory($this->tmpStorage);
    }

    /** @param array<string, mixed> $over */
    private function writeOutcome(array $over = [], bool $history = true): array
    {
        $o = array_merge([
            'outcome' => 'rolled_back', 'from' => '2.2.0', 'to' => '2.2.1', 'step' => 'health',
            'reason' => 'The new version showed an error on this host.', 'at' => 1796330000, 'started_at' => 1796329800,
            'report' => "Club Portal update report\nStep: health",
        ], $over);
        file_put_contents(UpdaterFiles::outcomePath(), json_encode($o));
        if ($history) {
            File::ensureDirectoryExists(UpdaterFiles::historyDir());
            file_put_contents(UpdaterFiles::historyDir().'/'.gmdate('YmdHis', $o['started_at']).'-'.$o['from'].'-'.$o['to'].'.json', json_encode($o));
        }

        return $o;
    }
}
