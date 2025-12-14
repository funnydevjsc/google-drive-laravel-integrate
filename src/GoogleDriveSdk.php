<?php

namespace FunnyDev\GoogleDrive;

use FunnyDev\GoogleClient\GoogleServiceClient;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Google\Service\Exception as GoogleException;
use GuzzleHttp\Psr7\Utils;
use Exception;

class GoogleDriveSdk
{
    public Client $client;
    protected Drive $drive;

    /**
     * @throws Exception
     * @throws GoogleException
     */
    public function __construct(array $credentials=null, string $credentials_path=null)
    {
        $this->client = (new GoogleServiceClient($credentials, $credentials_path))->instance();
        $this->client->addScope(Drive::DRIVE);
        $this->drive = new Drive($this->client);
    }

    /**
     * @throws GoogleException
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
        $folder = $this->drive->files->create($fileMetadata, array('fields' => 'id', 'supportsAllDrives' => true));
        return $folder->id;
    }
    
    /**
     * @throws GoogleException
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
     * @throws GoogleException
     */
    public function deleteResource(string $resourceId): bool
    {
        return boolval($this->drive->files->delete($resourceId, ['supportsAllDrives' => true]));
    }

    /**
     * @throws GoogleException
     */
    public function shareResource(string $resourceId, string $email, string $role='writer'): void
    {
        $this->drive->permissions->create($resourceId, new Permission([
            'type' => 'user',
            'role' => $role,
            'emailAddress' => $email,
        ]), ['sendNotificationEmail' => false, 'supportsAllDrives' => true]);
    }



    /**
     * @throws GoogleException
     */
    public function uploadFile(string $parentFolderId, string $name, mixed $content, string $mimeType='application/octet-stream'): string {
        $fileMetadata = new Drive\DriveFile(array(
            'name' => $name,
            'parents' => array($parentFolderId)
        ));
        $file = $this->drive->files->create($fileMetadata, array('data' => $content, 'mimeType' => $mimeType, 'uploadType' => 'multipart', 'fields' => 'id', 'supportsAllDrives' => true));
        return $file->id;
    }

    /**
     * @throws GoogleException
     */
    public function moveFile(string $fileId, string $newParentFolderId): bool
    {
        $emptyFileMetadata = new DriveFile();
        $file = $this->drive->files->get($fileId, array('fields' => 'parents'));
        $previousParents = join(',', $file->parents);
        $file = $this->drive->files->update($fileId, $emptyFileMetadata, array(
            'addParents' => $newParentFolderId,
            'removeParents' => $previousParents,
            'fields' => 'id, parents',
            'supportsAllDrives' => true
        ));
        return !!empty($file->parents);
    }

    /**
     * @throws GoogleException
     */
    public function downloadFile(string $fileId): mixed
    {
        $response = $this->drive->files->get($fileId, ['alt' => 'media']);
        return $response->getBody()->getContents();
    }

    /**
     * @throws GoogleException
     */
    public function streamDownloadFile(string $fileId, string $fileName, string $mimeType = 'application/octet-stream', mixed $range=null)
    {
        ini_set('zlib.output_compression', 'Off');
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        ini_set('output_buffering', 'Off');
        ini_set('implicit_flush', 1);

        $response = $this->drive->files->get($fileId, ['alt' => 'media']);
        $fileStream = Utils::streamFor($response->getBody());
        $fileSize = $fileStream->getSize();

        if (!$range) {
            $range = request()->header('Range');
        }
        if ($range) {
            preg_match('/bytes=(\d+)-(\d+)?/', $range, $matches);
            $start = intval($matches[1]);
            $end = isset($matches[2]) ? intval($matches[2]) : $fileSize - 1;

            $fileStream->seek($start);
            $length = ($end - $start) + 1;

            return response()->stream(function () use ($fileStream, $length) {
                echo $fileStream->read($length);
                flush();
            }, 206, [
                'Content-Type' => $mimeType,
                'Content-Length' => $length,
                'Content-Range' => "bytes $start-$end/$fileSize",
                'Accept-Ranges' => 'bytes',
            ]);
        }

        return response()->stream(function () use ($fileStream) {
            while (!$fileStream->eof()) {
                echo $fileStream->read(1024 * 64);
                flush();
            }
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * @throws GoogleException
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
     * @throws GoogleException
     */
    public function emptyTrash(): bool
    {
        return boolval($this->drive->files->emptyTrash(['supportsAllDrives' => true]));
    }
}
