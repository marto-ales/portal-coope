<?php
// src/Controller/Admin/ConfigController.php
namespace App\Controller\Admin;

use App\Service\EnvConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin')]
class ConfigController extends AbstractController
{
    public function __construct(
        private EnvConfigService $envConfigService
    ) {
    }

    #[Route('/config', name: 'admin_config', methods: ['GET'], stateless: false)]
    public function index(Request $request, SessionInterface $session): Response
    {
        // Check if already authenticated
        if (!$session->get('env_config_authenticated')) {
            return $this->redirectToRoute('admin_login');
        }

        $sections = $this->envConfigService->getConfigSections();
        $currentSection = $request->query->get('section', null);
        
        $sectionConfig = [];
        $sectionLabels = [
            'symfony/framework-bundle' => 'Framework (APP_ENV, APP_SECRET)',
            'doctrine/doctrine-bundle' => 'Database (DATABASE_URL)',
            'symfony/messenger' => 'Messenger (MESSENGER_TRANSPORT_DSN)',
            'symfony/mailer' => 'Mailer (MAILER_DSN)',
            'google/apiclient' => 'Google API & Sheets',
            'general' => 'Portal & General',
            'altcha' => 'AltCHA',
        ];

        // Check if we have an unbracketed section at the top
        foreach ($sections as $sectionName => $keys) {
            if (!isset($sectionLabels[$sectionName]) && !isset($sectionLabels[strtolower($sectionName)])) {
                $sectionLabels[$sectionName] = $keys;
            }
        }

        // For unbracketed keys at the top (like GOOGLE_TOKEN_FILE, RECIBOS_PREFIJO, etc.)
        $unbracketedConfig = [];
        foreach ($sections as $sectionName => $keys) {
            if ($sectionName === 'google/apiclient') {
                // These keys are inside google/apiclient bracket
                continue;
            }
            // It's an unbracketed section - collect unbracketed keys
            if (in_array($sectionName, ['General', 'google/apiclient'])) {
                continue;
            }
        }

        if ($currentSection) {
            $sectionConfig = $this->envConfigService->readSection($currentSection);
        }

        return $this->render('admin/config.html.twig', [
            'sections' => $sections,
            'sectionLabels' => $sectionLabels,
            'currentSection' => $currentSection,
            'config' => $sectionConfig,
        ]);
    }

    #[Route('/config/save', name: 'admin_config_save', methods: ['POST'], stateless: false)]
    public function save(Request $request, SessionInterface $session): Response
    {
        if (!$session->get('env_config_authenticated')) {
            return $this->redirectToRoute('admin_login');
        }

        $section = $request->request->get('section', '');
        
        if (!$section) {
            $this->addFlash('error', 'Debe seleccionar una sección.');
            return $this->redirectToRoute('admin_config');
        }

        // Collect all form fields that belong to this section
        $config = $request->request->get('config', []);

        $this->envConfigService->saveSection($section, $config);
        $this->addFlash('success', "Configuración de '{$section}' guardada correctamente.");

        return $this->redirectToRoute('admin_config', ['section' => $section]);
    }

    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'], stateless: false)]
    public function login(Request $request, SessionInterface $session): Response
    {
        if ($session->get('env_config_authenticated')) {
            return $this->redirectToRoute('admin_config');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            $password = $request->request->get('password', '');
            
            // Check if a password is configured
            $configuredPassword = $_ENV['ADMIN_CONFIG_PASSWORD'] ?? '';
            if (empty($configuredPassword)) {
                // No password configured - allow access without password
                $session->set('env_config_authenticated', true);
                return $this->redirectToRoute('admin_config');
            }
            
            if ($this->envConfigService->validatePassword($password)) {
                $session->set('env_config_authenticated', true);
                return $this->redirectToRoute('admin_config');
            }
            $error = 'Contraseña incorrecta.';
        }

        return $this->render('admin/login.html.twig', ['error' => $error]);
    }

    #[Route('/logout', name: 'admin_logout', methods: ['GET'], stateless: false)]
    public function logout(SessionInterface $session): Response
    {
        $session->remove('env_config_authenticated');
        return $this->redirect('/');
    }
}
