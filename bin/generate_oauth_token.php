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
$client->setAuthConfig($CREDENTIALS_FILE);
$client->setScopes($SCOPES);

$authUrl = $client->createAuthUrl($SCOPES, ['access_type' => 'offline', 'approval_prompt' => 'force']);

echo "\n==================================================\n";
echo "  GENERACIÓN DE TOKEN OAuth2 - Google Drive\n";
echo "==================================================\n\n";

echo "1. Abre la siguiente URL en tu navegador:\n\n";
echo "   {$authUrl}\n\n";
echo "2. Inicia sesión con tu cuenta de Google Workspace\n";
echo "3. Autoriza el acceso a Google Drive (Full permissions)\n";
echo "4. Copia el código de autorización (9-10 caracteres)\n\n";

echo "Ingresa el código: ";
$authCode = trim(fgets(STDIN));

if (empty($authCode)) {
    echo "\n❌ Código vacío. Ejecuta el script nuevamente.\n";
    exit(1);
}

try {
    $token = $client->fetchAccessTokenWithAuthCode($authCode);

    if (isset($token['refresh_token'])) {
        echo "\n✅ Token generado correctamente.\n";
        echo "El refresh_token está incluido. El token se guardará automáticamente.\n";
    } else {
        throw new RuntimeException("El token no contiene refresh_token. Revisa los scopes.");
    }

    file_put_contents($TOKEN_FILE, json_encode($token));

    echo "\n==================================================\n";
    echo "  ✅ TOKEN GUARDADO EN: {$TOKEN_FILE}\n";
    echo "==================================================\n";
    echo "Ahora puedes procesar PDFs.\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
