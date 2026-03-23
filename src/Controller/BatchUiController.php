<?php
declare(strict_types=1);

namespace Tacman\AiBatch\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Entity\AiBatchResult;

final class BatchUiController extends AbstractController
{
    #[Route('/_ai/batches', name: 'tacman_ai_batch_ui_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $batches = $entityManager->getRepository(AiBatch::class)->findBy([], ['createdAt' => 'DESC'], 200);

        return $this->render('@TacmanAiBatch/batches/index.html.twig', [
            'batches' => $batches,
        ]);
    }

    #[Route('/_ai/batches/{id}', name: 'tacman_ai_batch_ui_show', methods: ['GET'])]
    public function show(int $id, EntityManagerInterface $entityManager): Response
    {
        $batch = $entityManager->getRepository(AiBatch::class)->find($id);
        if (!$batch instanceof AiBatch) {
            throw $this->createNotFoundException(sprintf('Batch %d not found.', $id));
        }

        $results = $entityManager->getRepository(AiBatchResult::class)->findBy(['aiBatchId' => $id], ['id' => 'ASC']);

        return $this->render('@TacmanAiBatch/batches/show.html.twig', [
            'batch' => $batch,
            'results' => $results,
        ]);
    }
}
