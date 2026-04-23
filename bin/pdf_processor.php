<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use GuzzleHttp\Psr7\Request;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Http\MediaFileUpload;


class DrivePdfProcessor
{
    private $client;
    private $service;
    private $tokenFile;

    public function __construct($cred_file, $token_file, $temp_path)
    {
        $this->client = new Google\Client();
        // Configuración básica del cliente
        $this->client->setApplicationName("CoopeRosalesPdfProcessor");
        $this->tokenFile = $token_file;
        // 1. Cargar credenciales
        if (!file_exists($cred_file)) {
            throw new RuntimeException("Credentials file not found: {$cred_file}");
        }
        $this->client->setAuthConfig($cred_file);
        $this->client->setScopes([Drive::DRIVE]);

        // 2. Intentar cargar token existente
        if (file_exists($this->tokenFile)) {
            $this->loadToken();
            $this->ensureValidToken();
        } else {
            throw new RuntimeException("No token found. Run: php bin/generate_oauth_token.php");
        }

        $this->service = new Google\Service\Drive($this->client);

        // Crear carpeta temporal si no existe
        if (!file_exists($temp_path)) {
            mkdir($temp_path, 0777, true);
        }
    }

  private function loadToken(): void
    {
        $token = json_decode(file_get_contents($this->tokenFile), true);

        if (!isset($token['access_token'])) {
            throw new RuntimeException("Invalid token.json format");
        }

        $this->client->setAccessToken($token);
    }

    private function ensureValidToken(): void
    {
        // Verificar si el token está expirado
        if ($this->client->isAccessTokenExpired()) {
            echo "⚡ El token de acceso está expirado. Renovando...\n";

            try {
                $refreshToken = $this->client->getRefreshToken();
                if (!$refreshToken) {
                    throw new RuntimeException("No hay refresh_token disponible. Ejecuta: php bin/generate_oauth_token.php");
                }
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);

                if (isset($newToken['access_token'])) {
                    $this->client->setAccessToken($newToken);
                    $this->saveToken($newToken);
                    echo "✅ Token renovado exitosamente.\n";
                } else {
                    throw new RuntimeException("No new access token received after refresh.");
                }
            } catch (Exception $e) {
                echo "❌ Error al renovar token: " . $e->getMessage() . "\n";
                echo "El refresh token puede haber expirado. Ejecuta: php bin/generate_oauth_token.php\n";
                throw $e;
            }
        }
    }

    public function processPdfs($folder_id, $files_prefix, $temp_path, $bash_script)
    {
        echo "Iniciando proceso de Drive...\n";
        // 1. Listar archivos PDF en el directorio padre
        $query = "mimeType contains 'application/pdf' and trashed = false and '{$folder_id}' in parents and name contains '{$files_prefix}'";
        $files = $this->service->files->listFiles(['q' => $query, 'pageSize' => 1000, 'orderBy' => 'createdTime', 'fields' => 'nextPageToken, files(id, name)']);

        $file_count = count($files->files);
        $success_files = 0;
        if ($file_count === 0) {
            echo "No se encontraron archivos PDF en la carpeta.\n";
            return;
        }

        echo "Se encontraron ", $file_count, " archivos PDF.\n";

        foreach ($files->getFiles() as $file) {
            $fileId = $file->getId();
            $fileName = $file->getName();

            echo "\nProcesando: {$fileName} (ID: {$fileId})\n";

            try {
                // 2. Descargar archivo temporalmente
                if (!file_exists($temp_path . $fileName)) {
                    $tempPath = $this->downloadFile($fileId, $temp_path . $fileName);
                }
                else {
                    $tempPath = $temp_path . $fileName;
                }

                if (!$tempPath || !file_exists($tempPath)) {
                    throw new Exception("Error al descargar el archivo desde Drive.");
                }

                // 3. Ejecutar script Bash
                $bashResult = $this->runBashScript($bash_script, $tempPath);

                if ($bashResult['exit_code'] !== 0) {
                    echo "Error en el script Bash para {$fileName}: {$bashResult['output']}\n";
                    continue; // Saltar a la siguiente
                }

                // El script bash debe devolver el nombre del nuevo archivo o el contenido procesado.
                // Asumiremos aquí que el script bash RENOMBRA el archivo en el mismo path o genera un nuevo archivo.
                // CASO 1: El script bash renombra el archivo en el mismo lugar (ej: 'nombre_procesado.pdf').
                $newFileName = $bashResult['output'];
                if (empty($newFileName)) {
                    continue;
                }

                // Si el script bash devuelve un path completo o un nuevo nombre, asegúrate de usar solo el nombre base.
                // Ajusta esto según lo que devuelve tu script bash.

                // 4. Subir el archivo procesado con el nuevo nombre
                // Asumimos que el script bash ha generado/renombrado el archivo como "$newFileName" en el directorio temporal.
                $sourceForUpload = $tempPath;

                if (!file_exists($sourceForUpload)) {
                    continue;
                }

                $uploadResponse = $this->uploadFile($sourceForUpload, $folder_id, $newFileName, $fileId);

                // 5. Opcional: Eliminar el original en Drive si el script bash se encarga de la "sustitución"
                // $this->service->files->delete($fileId);
                $success_files++;
                if ($uploadResponse === null) {
                    echo "Procesado, ya existe: {$newFileName}\n";
                }
                else {
                    echo "Procesado y renombrado correctamente: {$newFileName}\n";
                }
                // Limpieza local
                //unlink($tempPath);

            } catch (Exception $e) {
                echo "Error procesando {$fileName}: " . $e->getMessage() . "\n";
                // Intentar limpiar en caso de error
                //if (file_exists($temp_path . $fileName)) {
                //    unlink($temp_path . $fileName);
                //}
            }
            #if ($success_files >= 5) break;
        }
        echo "\nProceso finalizado con {$success_files} archivos procesados.\n";
    }

    private function downloadFile($fileId, $localPath)
    {
        $response = $this->service->files->get($fileId, ['alt' => 'media']);
        file_put_contents($localPath, $response->getBody()->getContents());
        return $localPath;
    }

    private function runBashScript($scriptPath, $filePath)
    {
        // Asegúrate de que el script bash es ejecutable (chmod +x)
        // Ejecutamos el script con el path del archivo como argumento
        $cmd = sprintf('bash "%s" "%s" 2>&1', $scriptPath, $filePath);

        exec($cmd, $output, $returnCode);

        return [
            'exit_code' => $returnCode,
            'output' => implode("\n", $output)
        ];
    }

    private function uploadFile($localPath, $parentId, $fileName, $originalFileId = null)
    {
        $fileMetadata = new DriveFile([
            'name' => $fileName,
            'parents' => [$parentId]
        ]);

        $query = "mimeType contains 'application/pdf' and trashed = false and '{$parentId}' in parents and name = '{$fileName}'";
        $files = $this->service->files->listFiles(['q' => $query, 'pageSize' => 1000, 'orderBy' => 'createdTime', 'fields' => 'nextPageToken, files(id, name)']);

        if (count($files->getFiles()) == 0) {
            // Método correcto para subir media en google/apiclient v2.x
            $response = $this->service->files->create(
                $fileMetadata,
                array(
                    'data' => file_get_contents($localPath),
                    'mimeType' => 'application/pdf',
                    'fields' => 'id'
                )
            );
            return $response;
        } else {
            return null;
        }
        // Si deseas borrar el archivo original después de subir el nuevo:
        //$this->service->files->delete($originalFileId);

    }

}

// EJECUCIÓN
try {
    // Cargar variables de entorno desde .env (si existe)
    $dotenv = new Dotenv();
    $dotenv->load(__DIR__ . '/../.env');

    // Opcional: Cargar desde sistema operativo
    $dotenv->load(__DIR__ . '/../.env.local');

    // CONFIGURACIÓN
    $CREDENTIALS_FILE = __DIR__.'/../config/' . $_ENV['GOOGLE_TOKEN_FILE']; // credentials_cli.json'; // Tu credencial de Google
    $TOKEN_FILE = __DIR__.'/../config/token_cli.json';
    $DRIVE_PARENT_ID  = $_ENV['GOOGLE_REC_FOLDER_ID']; // 'ID_DEL_PADRE_O_FOLLETO';      // ID de la carpeta de Drive donde están los PDFs
    $TEMP_DOWNLOAD_PATH = sys_get_temp_dir() . '/drive_process/';
    $BASH_SCRIPT_PATH = __DIR__. '/rename-recibo.sh';     // Tu script bash
    $DRIVE_OUTPUT_DIR_ID = null; // (Opcional) ID de carpeta para subir los nuevos PDFs. Si null, se reemplaza en la misma carpeta.
    $RECIBOS_PREFIX = $_ENV['RECIBOS_PREFIJO'];

    $processor = new DrivePdfProcessor($CREDENTIALS_FILE, $TOKEN_FILE, $TEMP_DOWNLOAD_PATH);
    $processor->processPdfs($DRIVE_PARENT_ID, $RECIBOS_PREFIX, $TEMP_DOWNLOAD_PATH, $BASH_SCRIPT_PATH);
} catch (Exception $e) {
    echo "Error crítico: " . $e->getMessage() . "\n";
}
