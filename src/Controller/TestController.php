<?php

namespace App\Controller;

use App\Service\LLM\LLMInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class TestController extends AbstractController
{
    #[Route('/test', name: 'app_test', methods: ['GET'])]
    public function index(Request $request, LLMInterface $llm): JsonResponse
    {
        $prompt = trim($request->query->getString('prompt'));
        if ($prompt === '') {
            return $this->json([
                'error' => 'Missing query parameter "prompt" (non-empty string).',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$llm->isConfigured()) {
            return $this->json([
                'error' => 'LLM is not configured (empty API key).',
            ], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $response = $llm->complete($prompt);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => $e->getMessage(),
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'prompt' => $prompt,
            'response' => $response,
        ]);
    }
}
