<?php

namespace FunnyDev\GoogleDrive;

use FunnyDev\GoogleClient\GoogleServiceClient;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveSdk
{
    public Client $client;
    protected Drive $drive;

    /**
     * @throws \Exception
     * @throws \Google\Service\Exception
     */
    public function __construct(array $credentials=null, string $credentials_path=null)
    {
        $this->client = (new GoogleServiceClient($credentials, $credentials_path))->instance();
        $this->client->addScope(Drive::DRIVE);
        $this->drive = new Drive($this->client);
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function createFolder(string $name, string $parentFolderId=''): string
    {
        $folderMetadata = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];
        if ($parentFolderId) {
            $folderMetadata['parents'] = [$parentFolderId];
        }
        $fileMetadata = new Drive\DriveFile($folderMetadata);
        $folder = $this->drive->files->create($fileMetadata, array('fields' => 'id'));
        return $folder->id;
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function readFolder(string $folderId): array
    {
        $files = array();
        $pageToken = null;
        do {
            $response = $this->drive->files->listFiles(array(
                'q' => '',
                'spaces' => 'drive',
                'pageToken' => $pageToken,
                'fields' => 'nextPageToken, files(id, name)',
            ));
            if (!empty($response->files)) {
                $files[] = $response->files;
            }
            if (isset($response->pageToken) && $response->pageToken) {
                $pageToken = $response->pageToken;
            } else {
                $pageToken = null;
            }
        } while ($pageToken != null);
        return array_merge(...$files);
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function deleteResource(string $resourceId): bool
    {
        return boolval($this->drive->files->delete($resourceId));
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function uploadFile(string $parentFolderId, string $name, mixed $content, string $mimeType='application/octet-stream'): string {
        $fileMetadata = new Drive\DriveFile(array(
            'name' => $name,
            'parents' => array($parentFolderId)
        ));
        $file = $this->drive->files->create($fileMetadata, array('data' => $content, 'mimeType' => $mimeType, 'uploadType' => 'multipart', 'fields' => 'id'));
        return $file->id;
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function moveFile(string $fileId, string $newParentFolderId): bool
    {
        $emptyFileMetadata = new DriveFile();
        $file = $this->drive->files->get($fileId, array('fields' => 'parents'));
        $previousParents = join(',', $file->parents);
        $file = $this->drive->files->update($fileId, $emptyFileMetadata, array(
            'addParents' => $newParentFolderId,
            'removeParents' => $previousParents,
            'fields' => 'id, parents'
        ));
        return !!empty($file->parents);
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function deleteFile(string $fileId): bool
    {
        try {
            return boolval($this->drive->files->delete($fileId));
        } catch (\Google\Service\Exception) {}
        return false;
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function downloadFile(string $fileId): mixed
    {
        $response = $this->drive->files->get($fileId, ['alt' => 'media']);
        return $response->getBody()->getContents();
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function emptyTrash(): bool
    {
        return boolval($this->drive->files->emptyTrash());
    }
}
