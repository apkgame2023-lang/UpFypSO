<?php

class Y4APK_GoogleDrive {

    /**
     * Get the Google Drive API client.
     *
     * @return Google_Client|false The Google Drive API client or false on failure.
     */
    public function getClient() {
        $clientId = y4apk_options('gdrive_client_id', true);
        $clientSecret = y4apk_options('gdrive_client_secret', true);

        if (!$clientId || !$clientSecret) {
            return false;
        }

        require_once get_template_directory() . '/admin/includes/google-api-php-client-master/vendor/autoload.php';

        $redirectUri = add_query_arg('at_upload', 'gdrive', get_site_url());

        $config = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ];

        $client = new Google_Client($config);

        $client->setScopes(Google_Service_Drive::DRIVE);
        $client->setPrompt('select_account consent');
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');

        if ($accessToken = json_decode(get_option('at_gdrive_token'), true)) {
            $client->setAccessToken($accessToken);
        }

        if ($client->isAccessTokenExpired() && $refreshToken = $client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($refreshToken);
            $newAccessToken = $client->getAccessToken();

            if ($newAccessToken) {
                update_option('at_gdrive_token', json_encode($newAccessToken));
            }
        }

        return $client;
    }

    /**
     * Create a folder in Google Drive.
     *
     * @param string      $folderName      The name of the folder to be created.
     * @param string|null $parentFolderId  The ID of the parent folder if it exists.
     *
     * @return string|false The ID of the created folder or false on failure.
     */
    public function createFolder($folderName, $parentFolderId = null) {
        $client = $this->getClient();

        $existingFolders = $this->checkFolderExists($folderName);

        if (empty($existingFolders)) {
            $service = new Google_Service_Drive($client);
            $folder = new Google_Service_Drive_DriveFile();

            $folder->setName($folderName);
            $folder->setMimeType('application/vnd.google-apps.folder');

            if (!empty($parentFolderId)) {
                $folder->setParents([$parentFolderId]);
            }

            $result = $service->files->create($folder);

            return isset($result['id']) ? $result['id'] : false;
        }

        return $existingFolders[0]['id'];
    }

    /**
     * Check if a folder with the given name already exists in Google Drive.
     *
     * @param string $folderName The name of the folder to check.
     *
     * @return array An array containing information about existing folders.
     */
    private function checkFolderExists($folderName) {
        $client = $this->getClient();
        $service = new Google_Service_Drive($client);

        $parameters['q'] = "mimeType='application/vnd.google-apps.folder' and name='$folderName' and trashed=false";

        $files = $service->files->listFiles($parameters);
        $existingFolders = [];

        foreach ($files as $file) {
            $existingFolders[] = $file;
        }

        return $existingFolders;
    }

    /**
     * Insert a file into Google Drive.
     *
     * @param string      $filePath       The path to the local file.
     * @param string      $fileName       The name of the file in Google Drive.
     * @param string|null $parentFolderId The ID of the parent folder in Google Drive.
     * @param string      $contents       The contents of the file (optional).
     *
     * @return array An array containing the URL of the uploaded file.
     */
    public function insertFileToDrive($filePath, $fileName, $parentFolderId = null, $contents = '') {
        $client = $this->getClient();
        $service = new Google_Service_Drive($client);
    
        $file = new Google_Service_Drive_DriveFile();
        $file->setName($fileName);
    
        if (!empty($parentFolderId)) {
            $file->setParents([$parentFolderId]);
        }
    
        try {
            $client->setDefer(true);
        
            // Create a Google_Service_Drive_DriveFile instance
            $file = new Google_Service_Drive_DriveFile();
            $file->setName($fileName);
        
            if (!empty($parentFolderId)) {
                $file->setParents([$parentFolderId]);
            }
        
            $request = $service->files->create(
                $file,
                [
                    'uploadType' => 'resumable',
                    'fields' => 'id',
                ]
            );
        
            $chunkSizeBytes = 1 * 1024 * 1024;
            $media = new Google_Http_MediaFileUpload(
                $client,
                $request,
                'application/octet-stream',
                null,
                true,
                $chunkSizeBytes
            );
        
            $media->setFileSize(filesize($filePath));
        
            $status = false;
            $handle = fopen($filePath, "rb");
        
            while (!$status && !feof($handle)) {
                $chunk = $this->readFileChunk($handle, $chunkSizeBytes);
                $status = $media->nextChunk($chunk);
            }
        
            $result = $status ? $status : false;
            fclose($handle);
        
            $client->setDefer(false);
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
        
        if ($result && isset($result['id'])) {
            $fileId = $result['id'];
        
            $newPermission = new Google_Service_Drive_Permission();
            $newPermission->setType('anyone');
            $newPermission->setRole('reader');
        
            try {
                $service->permissions->create($fileId, $newPermission);
            } catch (Exception $e) {
                return ['error' => json_decode($e->getMessage(), true)['error']];
            }
        }
        
        $linkDownload = 'https://drive.google.com/uc?export=download&id=' . $fileId;
        
        return ['url' => $linkDownload];
    }    

    /**
     * Read a chunk of a file.
     *
     * @param resource $handle     The file handle.
     * @param int      $chunkSize  The size of the chunk to read.
     *
     * @return string The read chunk.
     */
    private function readFileChunk($handle, $chunkSize) {
        $byteCount = 0;
        $readChunk = '';

        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            $byteCount += strlen($chunk);
            $readChunk .= $chunk;

            if ($byteCount >= $chunkSize) {
                return $readChunk;
            }
        }

        return $readChunk;
    }
}