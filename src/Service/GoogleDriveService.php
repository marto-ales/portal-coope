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

    public function getReceiptFile($filename) {
        $response = $this->drive->files->get($filename, ['alt' => 'media']);
        return $response->getBody()->getContents();
    }
}
