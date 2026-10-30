<?php

namespace Tests\Feature;

use App\Jobs\CreateShareZip;
use App\Models\File;
use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\StorageEncryption;
use App\Services\ZipEntryReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class StorageEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private string $dir;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.storage_encryption_key' => base64_encode(str_repeat("\x42", 32))]);
        // no "share downloaded" email during download tests
        Setting::where('key', 'emails_share_downloaded_enabled')->update(['value' => 'false']);
        app(SettingsService::class)->clearCache();

        // Share files live in storage/app/shares/<owner id>/<share id>: use an id no real install has
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['id' => random_int(900000000, 999999999), 'admin' => false, 'active' => true, 'is_guest' => false])->save();
        $this->dir = storage_path('app/shares/' . $this->owner->id);
        @mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->dir);
        parent::tearDown();
    }

    private function deleteTree(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->deleteTree($path . '/' . $item);
            }
        }
        rmdir($path);
    }

    private function encryption(): StorageEncryption
    {
        return app(StorageEncryption::class);
    }

    private function enable(): void
    {
        Setting::where('key', StorageEncryption::SETTING_ENABLED)->update(['value' => 'true']);
        app(SettingsService::class)->clearCache();
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
        return $path;
    }

    public function test_round_trip_and_sizes(): void
    {
        foreach ([0, 1, 1000, StorageEncryption::CHUNK_SIZE, StorageEncryption::CHUNK_SIZE + 1, 3 * StorageEncryption::CHUNK_SIZE + 12345] as $size) {
            $content = $size > 0 ? random_bytes($size) : '';
            $path = $this->writeFile("file-{$size}.bin", $content);

            $this->encryption()->encryptFile($path);
            $this->assertTrue(StorageEncryption::isEncrypted($path));
            $this->assertSame(StorageEncryption::encryptedSize($size), filesize($path));
            if ($size >= 16) {
                $this->assertStringNotContainsString(substr($content, 0, 16), file_get_contents($path));
            }

            $reader = $this->encryption()->open($path);
            $this->assertSame($size, $reader->size());
            $this->assertSame($content, $reader->read(0, $size));
            // random access across a chunk boundary
            if ($size > StorageEncryption::CHUNK_SIZE) {
                $offset = StorageEncryption::CHUNK_SIZE - 10;
                $this->assertSame(substr($content, $offset, 20), $reader->read($offset, 20));
            }
            $reader->close();

            // encrypting twice is a no-op
            $this->encryption()->encryptFile($path);
            $this->assertSame(StorageEncryption::encryptedSize($size), filesize($path));

            $this->encryption()->decryptFile($path);
            $this->assertSame($content, file_get_contents($path));
        }
    }

    public function test_tampering_truncation_and_wrong_key_are_detected(): void
    {
        $path = $this->writeFile('secret.txt', str_repeat('confidential ', 10000));
        $this->encryption()->encryptFile($path);
        $original = file_get_contents($path);

        // flipped byte in a chunk
        $tampered = $original;
        $tampered[200] = chr(ord($tampered[200]) ^ 1);
        file_put_contents($path, $tampered);
        try {
            $this->encryption()->open($path)->read(0, 10);
            $this->fail('tampering not detected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('authentication', $e->getMessage());
        }

        // truncated
        file_put_contents($path, substr($original, 0, -1));
        try {
            $this->encryption()->open($path);
            $this->fail('truncation not detected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('truncated', $e->getMessage());
        }

        // wrong key
        file_put_contents($path, $original);
        config(['app.storage_encryption_key' => base64_encode(str_repeat("\x43", 32))]);
        $this->app->forgetInstance(StorageEncryption::class);
        try {
            app(StorageEncryption::class)->open($path);
            $this->fail('wrong key not detected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('another STORAGE_ENCRYPTION_KEY', $e->getMessage());
        }
    }

    public function test_zip_entries_can_be_read_from_an_encrypted_archive(): void
    {
        $files = [
            'a.txt' => 'hello',
            'empty.txt' => '',
            'folder/deep/b.bin' => random_bytes(2 * StorageEncryption::CHUNK_SIZE + 77),
            'folder/c.txt' => str_repeat('compressible ', 50000),
        ];
        $zipPath = $this->dir . '/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->setCompressionName('a.txt', \ZipArchive::CM_STORE);
        $zip->close();

        $this->encryption()->encryptFile($zipPath);
        $reader = new ZipEntryReader($this->encryption()->open($zipPath));

        foreach ($files as $name => $content) {
            $entry = $reader->find($name);
            $this->assertNotNull($entry, $name);
            $this->assertSame(strlen($content), $entry['size']);
            $this->assertSame($content, implode('', iterator_to_array($reader->stream($entry), false)), $name);
        }
        $this->assertNull($reader->find('missing.txt'));
    }

    private function makeShare(int $fileCount, string $longId): Share
    {
        $share = new Share();
        $share->forceFill([
            'user_id' => $this->owner->id,
            'name' => 'Encrypted share',
            'path' => $this->owner->id . '/' . $longId,
            'long_id' => $longId,
            'size' => 0,
            'file_count' => $fileCount,
            'expires_at' => now()->addDay(),
        ])->save();

        return $share;
    }

    private function addFile(Share $share, string $name, ?string $folder = null): void
    {
        $file = new File();
        $file->forceFill([
            'share_id' => $share->id,
            'name' => $name,
            'original_name' => $name,
            'type' => 'text/plain',
            'size' => 1,
            'full_path' => $folder,
        ])->save();
    }

    public function test_new_single_file_share_is_encrypted_and_downloads_with_ranges(): void
    {
        $this->enable();
        $share = $this->makeShare(1, 'enc-single');
        $content = random_bytes(StorageEncryption::CHUNK_SIZE + 5000);
        $path = $this->writeFile('enc-single/report.pdf', $content);
        $this->addFile($share, 'report.pdf');

        (new CreateShareZip($share))->handle();

        $this->assertTrue(StorageEncryption::isEncrypted($path));
        $this->assertSame('ready', $share->fresh()->status);

        $response = $this->get('/api/shares/enc-single/download');
        $response->assertOk();
        $this->assertSame($content, $response->streamedContent());
        $this->assertSame((string) strlen($content), $response->headers->get('Content-Length'));
        $this->assertStringContainsString('report.pdf', $response->headers->get('Content-Disposition'));

        $range = $this->get('/api/shares/enc-single/download/file/report.pdf', ['Range' => 'bytes=1048570-1048590']);
        $range->assertStatus(206);
        $this->assertSame(substr($content, 1048570, 21), $range->streamedContent());
        $this->assertSame('bytes 1048570-1048590/' . strlen($content), $range->headers->get('Content-Range'));

        $this->get('/api/shares/enc-single/download/file/report.pdf', ['Range' => 'bytes=99999999-'])->assertStatus(416);
    }

    public function test_new_multi_file_share_archive_is_encrypted(): void
    {
        $this->enable();
        $share = $this->makeShare(2, 'enc-multi');
        $this->writeFile('enc-multi/one.txt', 'first file');
        $this->writeFile('enc-multi/docs/two.txt', str_repeat('second ', 1000));
        $this->addFile($share, 'one.txt');
        $this->addFile($share, 'two.txt', 'docs');

        (new CreateShareZip($share))->handle();

        $zipPath = $this->dir . '/enc-multi.zip';
        $this->assertTrue(StorageEncryption::isEncrypted($zipPath));
        $this->assertDirectoryDoesNotExist($this->dir . '/enc-multi');

        // download all: a valid, plain zip
        $all = $this->get('/api/shares/enc-multi/download');
        $all->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $all->streamedContent());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $this->assertSame('first file', $zip->getFromName('one.txt'));
        $this->assertSame(str_repeat('second ', 1000), $zip->getFromName('docs/two.txt'));
        $zip->close();
        unlink($tmp);

        // one file out of the encrypted archive
        $one = $this->get('/api/shares/enc-multi/download/file/docs/two.txt');
        $one->assertOk();
        $this->assertSame(str_repeat('second ', 1000), $one->streamedContent());
    }

    public function test_shares_stay_plain_when_disabled_and_plain_files_still_download(): void
    {
        $share = $this->makeShare(1, 'plain-single');
        $path = $this->writeFile('plain-single/note.txt', 'plain');
        $this->addFile($share, 'note.txt');

        (new CreateShareZip($share))->handle();

        $this->assertFalse(StorageEncryption::isEncrypted($path));
        $this->assertSame('plain', $this->get('/api/shares/plain-single/download')->assertOk()->streamedContent());
    }

    public function test_enabling_requires_a_key(): void
    {
        config(['app.storage_encryption_key' => null]);
        $this->app->forgetInstance(StorageEncryption::class);
        $admin = User::factory()->create();
        $admin->forceFill(['admin' => true, 'active' => true, 'is_guest' => false])->save();
        $headers = ['Authorization' => 'Bearer ' . Auth::guard('api')->login($admin)];

        $this->putJson('/api/settings', ['settings' => [['key' => StorageEncryption::SETTING_ENABLED, 'value' => 'true']]], $headers)
            ->assertStatus(422)->assertJson(['code' => 'storage_key_missing']);

        $this->getJson('/api/settings/client-ip', $headers)->assertJsonPath('data.storage_encryption_key_configured', false);
        $this->assertFalse(app(StorageEncryption::class)->isEnabled());
    }

    public function test_commands_encrypt_and_decrypt_existing_files(): void
    {
        $a = $this->writeFile('old/a.txt', 'aaa');
        $b = $this->writeFile('old.zip', 'not really a zip');

        $this->artisan('encrypt-existing-files')->assertSuccessful();
        $this->assertTrue(StorageEncryption::isEncrypted($a));
        $this->assertTrue(StorageEncryption::isEncrypted($b));

        $this->artisan('decrypt-files')->assertSuccessful();
        $this->assertSame('aaa', file_get_contents($a));
        $this->assertSame('not really a zip', file_get_contents($b));
    }

    public function test_generated_keys_are_valid(): void
    {
        $key = StorageEncryption::generateKey();
        $this->assertSame(32, strlen(base64_decode($key, true)));
    }
}
