<?php
// src/Service/EnvConfigService.php
namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;

class EnvConfigService
{
    private string $projectDir;

    public function __construct(KernelInterface $kernel)
    {
        $this->projectDir = $kernel->getProjectDir();
    }

    public function validatePassword(#[\SensitiveParameter] string $password): bool
    {
        $envPassword = $_ENV['ADMIN_CONFIG_PASSWORD'] ?? '';
        if (!$envPassword) {
            return false;
        }
        return password_verify($password, $envPassword);
    }

    public function getEnvPath(): string
    {
        return $this->projectDir . '/.env.local';
    }

    /**
     * Parse .env.local and return all sections with their keys
     * Returns: ['symfony/framework-bundle' => ['APP_ENV', 'APP_SECRET'], ...]
     */
    public function getConfigSections(): array
    {
        $envPath = $this->getEnvPath();
        if (!file_exists($envPath)) {
            return [];
        }

        $content = file_get_contents($envPath);
        $sections = [];
        $currentSection = null;
        $unbracketedKeys = [];
        $foundFirstBracket = false;

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);

            if (preg_match('/^###>\s*(.+?)\s*<###$/', $trimmed, $m)) {
                $foundFirstBracket = true;
                $currentSection = $m[1];
                $sections[$currentSection] = [];
                continue;
            }

            if (preg_match('/^###<\s*(.+?)\s*<###$/', $trimmed, $m)) {
                if ($currentSection === $m[1]) {
                    $currentSection = null;
                }
                continue;
            }

            if ($currentSection !== null) {
                if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, 'export ')) {
                    continue;
                }
                if (preg_match('/^([A-Z_][A-Z0-9_]*)=/', $trimmed)) {
                    $sections[$currentSection][] = preg_replace('/=.*/', '', $trimmed);
                }
            } elseif (!$foundFirstBracket && preg_match('/^([A-Z_][A-Z0-9_]*)=/', $trimmed)) {
                $unbracketedKeys[] = preg_replace('/=.*/', '', $trimmed);
            }
        }

        if ($unbracketedKeys) {
            $sections['general'] = $unbracketedKeys;
        }

        return $sections;
    }

    /**
     * Read all key-value pairs from a given section
     */
    public function readSection(string $sectionName): array
    {
        $envPath = $this->getEnvPath();
        if (!file_exists($envPath)) {
            return [];
        }

        $content = file_get_contents($envPath);
        $config = [];
        $currentSection = null;
        $inSection = false;
        $isGeneral = false;

        if ($sectionName === 'general') {
            // For "general" section, read unbracketed keys at the top
            $foundFirstBracket = false;
            foreach (explode("\n", $content) as $line) {
                $trimmed = trim($line);
                if (preg_match('/^###>\s*(.+?)\s*<###$/', $trimmed)) {
                    break;
                }
                if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $trimmed, $m)) {
                    $value = $m[2];
                    if ((str_starts_with($value, "'") && str_ends_with($value, "'")) ||
                        (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
                        $value = substr($value, 1, -1);
                    }
                    $config[$m[1]] = $value;
                }
            }
            return $config;
        }

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);

            if (preg_match('/^###>\s*(.+?)\s*<###$/', $trimmed, $m)) {
                if ($currentSection === $sectionName) {
                    $inSection = true;
                }
                $currentSection = $m[1];
                continue;
            }

            if (preg_match('/^###<\s*(.+?)\s*<###$/', $trimmed, $m)) {
                if ($currentSection === $sectionName && $inSection) {
                    $inSection = false;
                }
                continue;
            }

            if ($inSection) {
                if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, 'export ')) {
                    continue;
                }
                if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $trimmed, $m)) {
                    $value = $m[2];
                    if ((str_starts_with($value, "'") && str_ends_with($value, "'")) ||
                        (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
                        $value = substr($value, 1, -1);
                    }
                    $config[$m[1]] = $value;
                }
            }
        }

        return $config;
    }

    /**
     * Save/update a section's key-value pairs
     */
    public function saveSection(string $sectionName, array $config): void
    {
        // Gestión de la contraseña de administración:
        // - Si llega vacía, se conserva la existente (no se borra por accidente).
        // - Si ya es un hash, se guarda tal cual (evita rehashear y romper el login).
        // - Si es texto plano, se hashea.
        if (array_key_exists('ADMIN_CONFIG_PASSWORD', $config)) {
            $newPassword = (string) $config['ADMIN_CONFIG_PASSWORD'];
            $existingPassword = $this->readSection('general')['ADMIN_CONFIG_PASSWORD'] ?? '';
            $passwordInfo = password_get_info($newPassword);

            if ($newPassword === '') {
                $config['ADMIN_CONFIG_PASSWORD'] = $existingPassword;
            } elseif (!empty($passwordInfo['algo'])) {
                $config['ADMIN_CONFIG_PASSWORD'] = $newPassword;
            } else {
                $config['ADMIN_CONFIG_PASSWORD'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
        }

        $envPath = $this->getEnvPath();
        if (!file_exists($envPath)) {
            touch($envPath);
        }

        $content = file_get_contents($envPath);
        $lines = explode("\n", $content);

        if ($sectionName === 'general') {
            // Save unbracketed keys at the top (before first bracket)
            $newLines = [];
            $block = ["###>general<###"];
            foreach ($config as $key => $value) {
                $block[] = $this->formatEnvLine($key, $value);
            }
            $block[] = "###<general<###";

            foreach ($lines as $i => $line) {
                $trimmed = trim($line);
                if (preg_match('/^###>\s*(.+?)\s*<###$/', $trimmed)) {
                    // Insert general block before first bracket
                    $newLines = array_merge($newLines, $block, ["\n"]);
                    for ($j = $i; $j < count($lines); $j++) {
                        $newLines[] = $lines[$j];
                    }
                    file_put_contents($envPath, implode("\n", $newLines));
                    clearstatcache(true, $envPath);
                    return;
                }
            }

            // No brackets found: append the block preserving the existing content
            $newLines = array_merge($lines, [""], $block);
            file_put_contents($envPath, implode("\n", $newLines));
            clearstatcache(true, $envPath);
            return;
        }

        // Find the section boundaries
        $sectionStart = null;
        $sectionEnd = null;
        $currentSection = null;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if (preg_match('/^###>\s*(.+?)\s*<###$/', $trimmed, $m)) {
                $currentSection = $m[1];
            }
            if (preg_match('/^###<\s*(.+?)\s*<###$/', $trimmed, $m)) {
                if ($currentSection === $sectionName) {
                    $sectionEnd = $i;
                }
                if ($currentSection === $sectionName && $sectionStart === null) {
                    for ($j = $i; $j >= 0; $j--) {
                        if (preg_match('/^###>\s*' . preg_quote($sectionName, '/') . '\s*<###$/', trim($lines[$j]))) {
                            $sectionStart = $j;
                            break;
                        }
                    }
                }
            }
        }

        // Build new block
        $block = ["###>{$sectionName}<###"];
        foreach ($config as $key => $value) {
            $block[] = $this->formatEnvLine($key, $value);
        }
        $block[] = "###<{$sectionName}<###";

        if ($sectionStart !== null && $sectionEnd !== null) {
            // Replace
            $newLines = array_merge(
                array_slice($lines, 0, $sectionStart),
                $block,
                array_slice($lines, $sectionEnd + 1)
            );
            $content = implode("\n", $newLines);
        } else {
            // Append
            $content .= "\n\n" . implode("\n", $block) . "\n";
        }

        file_put_contents($envPath, $content);
        clearstatcache(true, $envPath);
    }

    private function formatEnvLine(string $key, string $value): string
    {
        if ($value === '' || preg_match('/[\s"\x00-\x1f#\'\\\\]/', $value)) {
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
            return "{$key}='{$escaped}'";
        }
        return "{$key}={$value}";
    }
}
