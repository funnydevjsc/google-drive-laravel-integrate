<?php

namespace FunnyDev\GoogleDrive;

use FunnyDev\GoogleClient\GoogleServiceClient;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use GuzzleHttp\Psr7\Utils;

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
    public function downloadFile(string $fileId): mixed
    {
        $response = $this->drive->files->get($fileId, ['alt' => 'media']);
        return $response->getBody()->getContents();
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function streamDownloadFile(string $fileId, string $fileName, string $mimeType='application/octet-stream'): mixed
    {
        ini_set('zlib.output_compression', 'Off');
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        ini_set('output_buffering', 'Off');
        ini_set('implicit_flush', 1);

        $response = $this->drive->files->get($fileId, ['alt' => 'media']);
        $fileStream = Utils::streamFor($response->getBody());

        return response()->stream(function () use ($fileStream) {
            while (!$fileStream->eof()) {
                echo $fileStream->read(1024 * 64);
                flush();
            }
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Accept-Ranges' => 'bytes'
        ]);
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function streamDownloadVideoToHls(string $fileId, string $fileName='stream', int $splitSeconds=10): mixed
    {
        ini_set('zlib.output_compression', 'Off');
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        ini_set('output_buffering', 'Off');
        ini_set('implicit_flush', 1);

        $response = $this->drive->files->get($fileId, ['alt' => 'media']);
        $fileStream = Utils::streamFor($response->getBody());

        $cmd = "ffmpeg -i pipe:0 -c:v copy -c:a copy -f hls -hls_time '.$splitSeconds.' -hls_playlist_type vod pipe:1";
        $descriptors = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w']
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (is_resource($process)) {
            while (!$fileStream->eof()) {
                fwrite($pipes[0], $fileStream->read(1024 * 8));
            }
            fclose($pipes[0]);

            return response()->stream(function () use ($pipes, $process) {
                while (!feof($pipes[1])) {
                    echo fread($pipes[1], 1024 * 8);
                    flush();
                }

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl',
                'Content-Disposition' => 'inline; filename="'.$fileName.'.m3u8"',
            ]);
        }

        return false;
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function emptyTrash(): bool
    {
        return boolval($this->drive->files->emptyTrash());
    }
}
