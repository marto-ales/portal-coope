<?php

// bin/generate_oauth_token.php

use Google\Client;
use Google\Service\Drive;
use Symfony\Component\Dotenv\Dotenv;

require_once  __DIR__ . '/../vendor/autoload.php';

// Cargar variables de entorno
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

// Opcional: Cargar desde sistema operativo
$dotenv->load(__DIR__ . '/../.env.local');

$CREDENTIALS_FILE = __DIR__.'/../config/credentials_cli.json'; // Tu credencial de Google
$TOKEN_FILE = __DIR__.'/../config/token_cli.json';
$SCOPES = [Drive::DRIVE];

// Asegurar que el directorio existe
$tokenDir = dirname($TOKEN_FILE);
if (!file_exists($tokenDir)) {
    mkdir($tokenDir, 0777, true);
}

$client = new Client();

// Configurar credenciales
$client->setAuthConfig($CREDENTIALS_FILE);
$client->setScopes($SCOPES);

// Cargar token existente si ya existe
if (file_exists($TOKEN_FILE)) {
    $client->setAccessToken(json_decode(file_get_contents($TOKEN_FILE), true));
}

// Si ya hay token válido, salir
if (!$client->isAccessTokenExpired()) {
    echo "El token ya es válido. Token generado previamente.\n";
    exit(0);
}

// Generar URL de autorización
$authUrl = $client->createAuthUrl();

echo "1. Ve a la siguiente URL en tu navegador:\n\n";
echo $authUrl . "\n\n";
echo "2. Inicia sesión con tu cuenta de Google\n";
echo "3. Autoriza a la aplicación para acceder a tu Google Drive\n";
echo "4. Copia el código de autorización que verás en la pantalla\n\n";

// Pedir el código de autorización al usuario
echo "Ingresa el código de autorización: ";
$authCode = trim(fgets(STDIN));

// Intercambiar el código por un token
$client->fetchAccessTokenWithAuthCode($authCode);

// Guardar el token en el archivo
file_put_contents($TOKEN_FILE, json_encode($client->getAccessToken()));

echo "\n✅ Token guardado en: {$TOKEN_FILE}\n";
echo "Ahora puedes usar tu aplicación con OAuth2\n";
