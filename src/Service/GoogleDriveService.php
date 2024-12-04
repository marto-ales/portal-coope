<?php

// src/Service/GoogleDriveService.php
namespace App\Service;

use Google\Client;
use Google\Service\Drive;


class GoogleDriveService
{
    private $client;
    private $drive;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setAuthConfig(__DIR__.'/../../config/credentials.json');
        $this->client->setDeveloperKey($_ENV['GOOGLE_API_KEY']);
        $this->client->addScope(Drive::DRIVE_READONLY);
        $this->client->setAccessType('offline');

        $this->drive = new Drive($this->client);
    }

    public function getSpreadsheetData($spreadsheetId, $range)
    {
        $service = new \Google\Service\Sheets($this->client);
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        return $response->getValues();
    }

    public function authenticate($authCode)
    {
        $accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);
        $this->client->setAccessToken($accessToken);

        // Guardar el token para uso futuro
        if (!empty($accessToken['refresh_token'])) {
            file_put_contents(__DIR__.'/../../config/token.json', json_encode($accessToken));
        }

        $this->driveService = new Drive($this->client);
    }

    public function setAccessToken()
    {
        // Cargar el token desde el archivo si existe
        if (file_exists(__DIR__.'/../../config/token.json')) {
            $accessToken = json_decode(file_get_contents(__DIR__.'/../../config/token.json'), true);
            $this->client->setAccessToken($accessToken);
            if ($this->client->isAccessTokenExpired()) {
                $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                file_put_contents(__DIR__.'/../../config/token.json', json_encode($this->client->getAccessToken()));
            }
            $this->driveService = new Drive($this->client);
        }
    }

    public function getFilesList($folderId, $receiptId)
    {
        $this->setAccessToken();
        $response = $this->driveService->files->listFiles([
            'q' => "'{$folderId}' in parents and trashed = false and name contains '00$receiptId'",
            'pageSize' => 1000,
            'orderBy' => 'createdTime',
            'fields' => 'nextPageToken, files(id, name)',
        ]);
        return $response->getFiles();
    }

    public function getFile($fileId)
    {
        $this->setAccessToken();
        $response = $this->driveService->files->get($fileId, ['alt' => 'media']);
        return $response;
    }

    public function getAuthUrl()
    {
        return $this->client->createAuthUrl();
    }

    public function getReceiptFile($filename) {
        $response = $this->drive->files->get($filename, ['alt' => 'media']);
        return $response->getBody()->getContents();
    }
}
