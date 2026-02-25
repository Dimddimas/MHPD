<?php

namespace App\Controller;

use App\Entity\RadarSession;
use App\Service\Agent\DatabaseAgentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AiAssistantController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/assistente', name: 'ai_assistant_index')]
    public function index(): Response
    {
        return $this->render('ai_assistant/index.html.twig');
    }

    #[Route('/assistente/chat', name: 'ai_assistant_chat', methods: ['POST'])]
    public function chat(Request $request, DatabaseAgentService $agent): JsonResponse
    {
        $data    = json_decode($request->getContent(), true);
        $message = trim($data['message'] ?? '');
        $history = $data['history'] ?? [];

        if ($message === '') {
            return $this->json(['success' => false, 'error' => 'Mensagem vazia.'], 400);
        }

        $apiHistory = array_slice(
            array_map(fn($m) => ['role' => $m['role'], 'content' => $m['content']], $history),
            -40
        );

        $result = $agent->chat($apiHistory, $message);

        return $this->json([
            'success'    => $result['success'],
            'message'    => $result['message']   ?? null,
            'error'      => $result['error']      ?? null,
            'tools_used' => $result['tools_used'] ?? [],
        ]);
    }

    // ── Session CRUD ────────────────────────────────────────────────────────

    #[Route('/assistente/sessoes', name: 'ai_session_list', methods: ['GET'])]
    public function sessionList(): JsonResponse
    {
        $repo     = $this->em->getRepository(RadarSession::class);
        $sessions = $repo->findBy([], ['updatedAt' => 'DESC'], 30);

        return $this->json([
            'success'  => true,
            'sessions' => array_map(fn(RadarSession $s) => [
                'id'        => $s->getId(),
                'title'     => $s->getTitle(),
                'msgCount'  => $s->getMsgCount(),
                'updatedAt' => $s->getUpdatedAt()->getTimestamp() * 1000,
            ], $sessions),
        ]);
    }

    #[Route('/assistente/sessoes/salvar', name: 'ai_session_save', methods: ['POST'])]
    public function sessionSave(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $id   = $data['id'] ?? null;

        if (!$id) {
            return $this->json(['success' => false, 'error' => 'ID ausente.'], 400);
        }

        $repo    = $this->em->getRepository(RadarSession::class);
        $session = $repo->find($id) ?? new RadarSession($id);

        $session->setTitle(mb_substr($data['title'] ?? 'Sessão sem título', 0, 100));
        $session->setHistory($data['history'] ?? []);
        $session->setResponses($data['responses'] ?? []);
        $session->setMsgs($data['msgs'] ?? []);
        $session->setMsgCount((int)($data['msgCount'] ?? 0));

        $this->em->persist($session);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/assistente/sessoes/{id}', name: 'ai_session_load', methods: ['GET'])]
    public function sessionLoad(string $id): JsonResponse
    {
        $session = $this->em->getRepository(RadarSession::class)->find($id);

        if (!$session) {
            return $this->json(['success' => false, 'error' => 'Sessão não encontrada.'], 404);
        }

        return $this->json(['success' => true, 'session' => $session->toArray()]);
    }

    #[Route('/assistente/sessoes/{id}', name: 'ai_session_delete', methods: ['DELETE'])]
    public function sessionDelete(string $id): JsonResponse
    {
        $session = $this->em->getRepository(RadarSession::class)->find($id);

        if ($session) {
            $this->em->remove($session);
            $this->em->flush();
        }

        return $this->json(['success' => true]);
    }
}