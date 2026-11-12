<?php

namespace App\Controller;

use App\Service\AltchaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class CaptchaController extends AbstractController
{
    /**
     * Entrega un desafío ALTCHA nuevo para el widget.
     */
    #[Route('/captcha/challenge', name: 'altcha_challenge', methods: ['GET'])]
    public function challenge(AltchaService $altchaService): JsonResponse
    {
        $response = $this->json($altchaService->createChallenge());
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }
}
