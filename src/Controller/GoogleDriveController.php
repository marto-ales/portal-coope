<?php
// src/Controller/GoogleDriveController.php
namespace App\Controller;

use App\Service\GoogleDriveService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;


class GoogleDriveController extends AbstractController
{
    private $googleDriveService;

    public function __construct(GoogleDriveService $googleDriveService)
    {
        $this->googleDriveService = $googleDriveService;
    }

    /**
     * @Route("/google/auth", name="google_auth")
     */
    public function googleAuth(): Response
    {
        $authUrl = $this->googleDriveService->getAuthUrl();
        return $this->redirect($authUrl);
    }

    /**
     * @Route("/google/callback", name="google_callback")
     */
    public function googleCallback(Request $request): Response
    {
        $authCode = $request->query->get('code');
        $this->googleDriveService->authenticate($authCode);
        return $this->redirectToRoute('receipt');
    }


     /**
     * @Route("/google/files", name="google_files")
     */
    public function listFiles(): Response
    {
        $files = $this->googleDriveService->getFilesList();
        return $files;
    }

    /**
     * @Route("/google/file/{id}", name="google_file")
     */
    public function getFile($id): Response
    {
        $fileResponse = $this->googleDriveService->getFile($id);

        $httpFoundationFactory = new HttpFoundationFactory();
        $symfonyResponse = $httpFoundationFactory->createResponse($fileResponse);
        return $symfonyResponse;
    }
}
