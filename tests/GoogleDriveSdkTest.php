<?php

use FunnyDev\GoogleDrive\GoogleDriveSdk;
use Google\Service\Drive;
use Google\Service\Drive\Permission as GooglePermission;
use PHPUnit\Framework\TestCase;

class GoogleDriveSdkTest extends TestCase
{
    private function makeSdkWithDrive(Drive $drive): GoogleDriveSdk
    {
        $ref = new ReflectionClass(GoogleDriveSdk::class);
        /** @var GoogleDriveSdk $sdk */
        $sdk = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('drive');
        $prop->setAccessible(true);
        $prop->setValue($sdk, $drive);
        return $sdk;
    }

    public function testCreateFolderReturnsId(): void
    {
        $files = new FakeFilesResource();
        $permissions = new FakePermissionsResource();
        $drive = new Drive([]);
        $drive->files = $files;
        $drive->permissions = $permissions;

        $sdk = $this->makeSdkWithDrive($drive);

        $id = $sdk->createFolder('test-folder', 'parent-1');

        $this->assertNotEmpty($id);
        $this->assertSame('create', $files->lastCall['method'] ?? null);
        $this->assertSame(['fields' => 'id', 'supportsAllDrives' => true], $files->lastCall['params'] ?? null);
    }

    public function testReadFolderAggregatesPages(): void
    {
        $files = new FakeFilesResource();
        $files->setListPages([
            (object) ['files' => [(object)['id' => 'f1', 'name' => 'A']], 'pageToken' => 't2'],
            (object) ['files' => [(object)['id' => 'f2', 'name' => 'B']], 'pageToken' => null],
        ]);
        $drive = new Drive([]);
        $drive->files = $files;
        $drive->permissions = new FakePermissionsResource();

        $sdk = $this->makeSdkWithDrive($drive);

        $result = $sdk->readFolder('ignored-folder-id');

        $this->assertCount(2, $result);
        $this->assertEquals('f1', $result[0]->id);
        $this->assertEquals('f2', $result[1]->id);
    }

    public function testUploadFileReturnsId(): void
    {
        $files = new FakeFilesResource();
        $drive = new Drive([]);
        $drive->files = $files;
        $drive->permissions = new FakePermissionsResource();

        $sdk = $this->makeSdkWithDrive($drive);

        $id = $sdk->uploadFile('parent-1', 'file.txt', 'CONTENT', 'text/plain');

        $this->assertNotEmpty($id);
        $this->assertSame('create', $files->lastCall['method'] ?? null);
        $this->assertSame('multipart', $files->lastCall['params']['uploadType'] ?? null);
    }

    public function testMoveFileReturnsFalsePerCurrentLogic(): void
    {
        $files = new FakeFilesResource();
        $files->setFileParents('file-1', ['old-parent']);
        $drive = new Drive([]);
        $drive->files = $files;
        $drive->permissions = new FakePermissionsResource();

        $sdk = $this->makeSdkWithDrive($drive);

        $result = $sdk->moveFile('file-1', 'new-parent');

        // With stub returning non-empty parents after update, the current implementation returns false
        $this->assertFalse($result);
        $this->assertSame('update', $files->lastCall['method'] ?? null);
        $this->assertSame('new-parent', $files->lastCall['params']['addParents'] ?? null);
    }

    public function testDownloadFileReturnsExactContent(): void
    {
        $files = new FakeFilesResource();
        $files->setFileContent('file-1', 'HELLO-WORLD');
        $drive = new Drive([]);
        $drive->files = $files;
        $drive->permissions = new FakePermissionsResource();

        $sdk = $this->makeSdkWithDrive($drive);

        $content = $sdk->downloadFile('file-1');
        $this->assertSame('HELLO-WORLD', $content);
    }

    public function testDeleteResourceReturnsTrue(): void
    {
        $files = new FakeFilesResource();
        $drive = new Drive([]);
        $drive->files = $files;
        $drive->permissions = new FakePermissionsResource();

        $sdk = $this->makeSdkWithDrive($drive);

        $this->assertTrue($sdk->deleteResource('x-id'));
        $this->assertSame('x-id', $files->lastCall['id'] ?? null);
    }

    public function testShareResourceCallsPermissionsCreate(): void
    {
        $files = new FakeFilesResource();
        $permissions = new FakePermissionsResource();
        $drive = new Drive([]);
        $drive->files = $files;
        $drive->permissions = $permissions;

        $sdk = $this->makeSdkWithDrive($drive);

        $sdk->shareResource('rid-1', 'user@example.com', 'reader');

        $this->assertNotNull($permissions->lastCreateArgs);
        $this->assertSame('rid-1', $permissions->lastCreateArgs['resourceId']);
        $this->assertInstanceOf(GooglePermission::class, $permissions->lastCreateArgs['permission']);
        /** @var GooglePermission $perm */
        $perm = $permissions->lastCreateArgs['permission'];
        $this->assertSame('user@example.com', $perm->emailAddress);
        $this->assertSame('reader', $perm->role);
        $this->assertSame(['sendNotificationEmail' => false, 'supportsAllDrives' => true], $permissions->lastCreateArgs['params']);
    }

    public function testEmptyTrashReturnsTrue(): void
    {
        $files = new FakeFilesResource();
        $drive = new Drive([]);
        $drive->files = $files;
        $drive->permissions = new FakePermissionsResource();

        $sdk = $this->makeSdkWithDrive($drive);

        $this->assertTrue($sdk->emptyTrash());
    }
}

// ----------------------------
// Test Doubles for Google Drive Resources
// ----------------------------

class FakeFilesResource
{
    public array $lastCall = [];
    private array $listPages = [];
    private int $listIndex = 0;
    private array $fileParents = [];
    private array $fileContents = [];

    public function setListPages(array $pages): void
    {
        $this->listPages = $pages;
        $this->listIndex = 0;
    }

    public function setFileParents(string $fileId, array $parents): void
    {
        $this->fileParents[$fileId] = $parents;
    }

    public function setFileContent(string $fileId, string $content): void
    {
        $this->fileContents[$fileId] = $content;
    }

    public function create($fileMetadata, array $params = [])
    {
        $this->lastCall = ['method' => 'create', 'params' => $params, 'metadata' => $fileMetadata];
        return (object)['id' => uniqid('id_', true)];
    }

    public function listFiles(array $params = [])
    {
        $this->lastCall = ['method' => 'listFiles', 'params' => $params];
        if ($this->listIndex < count($this->listPages)) {
            return $this->listPages[$this->listIndex++];
        }
        return (object)['files' => [], 'pageToken' => null];
    }

    public function get(string $fileId, array $params = [])
    {
        $this->lastCall = ['method' => 'get', 'id' => $fileId, 'params' => $params];
        if (isset($params['alt']) && $params['alt'] === 'media') {
            $content = $this->fileContents[$fileId] ?? '';
            return new FakeResponse($content);
        }
        $parents = $this->fileParents[$fileId] ?? [];
        return (object)['parents' => $parents];
    }

    public function update(string $fileId, $fileMetadata, array $params = [])
    {
        $this->lastCall = ['method' => 'update', 'id' => $fileId, 'params' => $params];
        // Simulate new parents applied
        $parents = [];
        if (!empty($params['addParents'])) {
            $parents = array_map('trim', explode(',', (string)$params['addParents']));
        }
        return (object)['parents' => $parents];
    }

    public function delete(string $fileId, array $params = [])
    {
        $this->lastCall = ['method' => 'delete', 'id' => $fileId, 'params' => $params];
        return 1;
    }

    public function emptyTrash(array $params = [])
    {
        $this->lastCall = ['method' => 'emptyTrash', 'params' => $params];
        return 1;
    }
}

class FakePermissionsResource
{
    public ?array $lastCreateArgs = null;

    public function create(string $resourceId, GooglePermission $permission, array $params = [])
    {
        $this->lastCreateArgs = [
            'resourceId' => $resourceId,
            'permission' => $permission,
            'params' => $params,
        ];
        return (object)['id' => uniqid('perm_', true)];
    }
}

class FakeResponse
{
    private FakeBody $body;

    public function __construct(string $content)
    {
        $this->body = new FakeBody($content);
    }

    public function getBody(): FakeBody
    {
        return $this->body;
    }
}

class FakeBody
{
    private string $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function getContents(): string
    {
        return $this->content;
    }
}
