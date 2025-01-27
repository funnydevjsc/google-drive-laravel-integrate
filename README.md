# Google Drive Laravel

The free Laravel package to help you manage your Google Drive

## Use Cases

- CRUD files and folders on your Google Drive
- Upload and download without normal Google Drive exceeded limits

## Features

- Dynamic Google Service credentials from config/google-service.php
- Dynamic Google Drive properties from config/google-drive.php
- Easy to CRUD files and folders on your Google Drive with a simple line code

## Know issues

- This package uses the latest official SDK, libraries and methods from Google then it might be large (around 30mb for dependency package google/apiclient) for shared hosting.
- Please consider your server's environment before using this package.
- However, we still recommend that you follow the latest writing style for Google libraries to ensure safety, compliance, CI/CD and most importantly if you are using services

## Requirements

- **PHP**: 8.1 or higher
- **Laravel** 9.0 or higher

## Quick Start

If you prefer to install this package into your own Laravel application, please follow the installation steps below

## Installation

#### Step 1. Install a Laravel project if you don't have one already

https://laravel.com/docs/installation

#### Step 2. Require the current package using composer:

```bash
composer require funnydevjsc/google-drive-laravel-integrate
```

#### Step 3. Create a Google Service credentials:

- As our guide at https://github.com/funnydevjsc/google-client-laravel-integrate.

#### Step 4. Publish the controller file and config file

```bash
php artisan vendor:publish --provider="FunnyDev\GoogleDrive\GoogleDriveServiceProvider" --tag="funnydev-google-drive"
```

If publishing files fails, please create corresponding files at the path `config/google-drive.php` from this package.

#### Step 5. Update the various config settings in the published config file:

- After publishing the package assets a configuration file will be located at <code>config/google-drive.php</code>.
- Find your Google Drive parent folder ID and fill into <code>config/google-drive.php</code> file like this (your files and folders might be uploaded and managed within this parent folder):

<img src="screenshots/google-drive-create-parent-folder-sample.png">

<img src="screenshots/google-drive-get-parent-folder-id-sample.png">

## Testing

``` php
use FunnyDev\GoogleDrive\GoogleDriveSdk;

class TestDrive
{
    /**
     * Handle the event.
     * @throws \Exception
     */
    public function handle(): void
    {
        $drive = new GoogleDriveSdk();
        
        $folderId = $drive->createFolder('test', config('google-drive.parent_folder_id'));
        
        $fileId = $drive->uploadFile(
            $folderId,
            'file_uploaded.txt',
            file_get_contents(storage_path('file.txt')),
            'text/plain'
        );
        
        $file = $drive->downloadFile($fileId);
        file_put_contents(storage_path('file_downloaded.txt'), $file);
        
        if ($drive->deleteResource($fileId)) {
            echo 'Deleted file';
        }
    }
}
```

## Feedback

Respect us in the [Laravel Việt Nam](https://www.facebook.com/groups/167363136987053)

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email contact@funnydev.vn or use the issue tracker.

## Credits

- [Funny Dev., Jsc](https://github.com/funnydevjsc)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
