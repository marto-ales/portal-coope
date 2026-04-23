<?php
// bin/generate_oauth_token.php

use Google\Client;
use Google\Service\Drive;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');
$dotenv->load(__DIR__ . '/../.env.local');

$CREDENTIALS_FILE = __DIR__.'/../config/credentials_cli.json';
$TOKEN_FILE = __DIR__.'/../config/token_cli.json';
$SCOPES = [Drive::DRIVE];
$REDIRECT_URI = 'http://localhost:8888/callback';

$tokenDir = dirname($TOKEN_FILE);
if (!file_exists($tokenDir)) {
    mkdir($tokenDir, 0777, true);
}

$client = new Client();
$client->setAuthConfig($CREDENTIALS_FILE);
$client->setScopes($SCOPES);
$client->setRedirectUri($REDIRECT_URI);

// Forzar access_type=offline y approval_prompt=force
$client->setAccessType('offline');
$client->setApprovalPrompt('force');

echo "\n=== GENERACIÓN DE TOKEN OAuth2 - Google Drive ===\n";
echo "===   Iniciando servidor en http://localhost:8888   ===\n\n";

$server = stream_socket_server('tcp://localhost:8888');
if (!$server) {
    echo "❌ No se pudo iniciar el servidor en localhost:8888\n";
    exit(1);
}
stream_set_blocking($server, false);
echo "✅ Servidor escuchando en http://localhost:8888\n\n";

// Construir la URL de autorización manualmente para asegurar los parámetros correctos
$clientId = $client->getClientId();
$redirectUri = $client->getRedirectUri();
$scope = implode(' ', $client->getScopes());
$state = bin2hex(random_bytes(16));

$authUrl = "https://accounts.google.com/o/oauth2/v2/auth"
    . "?response_type=code"
    . "&access_type=offline"
    . "&approval_prompt=force"
    . "&client_id=" . urlencode($clientId)
    . "&redirect_uri=" . urlencode($redirectUri)
    . "&scope=" . urlencode($scope)
    . "&state=" . $state;
    
echo "1. Abre la siguiente URL en tu navegador:\n\n";
echo "   {$authUrl}\n\n";
echo "2. Inicia sesión con tu cuenta de Google Workspace\n";
echo "3. Autoriza el acceso a Google Drive (Full permissions)\n";
echo "4. Espera a que se complete la autenticación automáticamente...\n\n";

echo "Esperando callback... (Ctrl+C para cancelar)\n";

$authCode = null;
$timeout = 300;
$elapsed = 0;

while ($elapsed < $timeout) {
    $read = [$server];
    $write = null;
    $except = null;
    $result = stream_select($read, $write, $except, 1);

    if ($result === false || $result === 0) {
        $elapsed++;
        continue;
    }

    $client_conn = stream_socket_accept($server, 5);
    if (!$client_conn) continue;

    $request = '';
    while (($line = fgets($client_conn)) && $line !== "\r\n") {
        $request .= $line;
    }

    $postBody = '';
    while (($chunk = fread($client_conn, 8192)) && strpos($chunk, "\r\n\r\n") === false) {
        $postBody .= $chunk;
    }
    if (!empty($postBody)) {
        parse_str($postBody, $postData);
    }

    $uri = '';
    $lines = explode("\r\n", $request);
    if (!empty($lines[0])) {
        $parts = explode(' ', $lines[0]);
        $uri = $parts[1] ?? '';
    }

    fclose($client_conn);

    if (strpos($uri, '/callback') !== false || !empty($postData['code'])) {
        parse_url($uri, PHP_URL_QUERY) && parse_str(parse_url($uri, PHP_URL_QUERY), $_GET);
        if (!empty($_GET['code'])) {
            $authCode = $_GET['code'];
        } elseif (!empty($postData['code'])) {
            $authCode = $postData['code'];
        }
        break;
    }

    $response = "HTTP/1.1 302 Found\r\nLocation: http://localhost:8888/success\r\n\r\n";
    $sock = stream_socket_server('tcp://localhost:0');
    fclose($sock);
    $sock2 = stream_socket_server('tcp://localhost:0');
    fclose($sock2);

    $conn = stream_socket_server('tcp://localhost:0');
    $meta = stream_get_meta_data($conn);
    fclose($conn);

    $response .= "Content-Length: 0\r\n\r\n";
}

if (!$authCode) {
    echo "❌ Timeout: no se recibió código de autorización en {$timeout} segundos\n";
    fclose($server);
    exit(1);
}

fclose($server);

echo "✅ Código recibido.\n";
echo "\nProcesando token...\n\n";

try {
    $token = $client->fetchAccessTokenWithAuthCode($authCode);

    if (isset($token['error'])) {
        echo "\n❌ Error de Google: " . $token['error'] . "\n";
        if (isset($token['error_description'])) {
            echo "   " . $token['error_description'] . "\n";
        }
        exit(1);
    }

    if (isset($token['refresh_token'])) {
        echo "✅ Token generado correctamente.\n";
        echo "El refresh_token está incluido. El token se guardará automáticamente.\n";
    } else {
        throw new RuntimeException("El token no contiene refresh_token. Revisa los scopes.");
    }

    file_put_contents($TOKEN_FILE, json_encode($token));

    echo "\n===   ✅ TOKEN GUARDADO EN: {$TOKEN_FILE}   ===\n";
    echo "   Ahora puedes procesar PDFs.\n\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
