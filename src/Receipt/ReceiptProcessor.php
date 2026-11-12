<?php

namespace App\Receipt;

use App\Entity\CompletionReceipt;
use App\Message\OutboxMessage;
use App\Service\TaskTransitionService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class ReceiptProcessor
{
    public function __construct(private EntityManagerInterface $em, private ReceiptStorage $storage, private TaskTransitionService $transitions) {}

    #[AsEventListener(event: 'receipt.requested')]
    public function __invoke(OutboxMessage $event): void
    {
        $this->materialize($event->aggregateId);
    }

    public function materialize(string $id): void
    {
        $receipt = $this->em->find(CompletionReceipt::class, $id);
        if (!$receipt) {
            throw new \RuntimeException('Receipt not found.');
        }
        // Slow storage I/O is deliberately outside a database transaction.
        $hash = $this->storage->materialize($receipt);
        $this->em->wrapInTransaction(function () use ($id, $hash) {
            $receipt = $this->em->find(CompletionReceipt::class, $id);
            $task = $receipt->getTask();
            $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($receipt, LockMode::PESSIMISTIC_WRITE);
            if ('verified' === $receipt->getStatus()) {
                return;
            }
            $receipt->verify($hash);
            $this->em->flush();
            if ('completion_pending_evidence' === $task->getStatus()) {
                $this->transitions->transition($task->getId(), 'completed', $task->getVersion(), 'receipt-worker', ['verifiedReceiptId' => $id]);
            }
        });
    }

    public function reconcile(int $limit = 100): array
    {
        $cursor = '';
        $result = ['checked' => 0, 'recovered' => 0, 'inconsistent' => 0];
        do {
            $receipts = $this->em->createQueryBuilder()->select('r')->from(CompletionReceipt::class, 'r')
                ->where('r.id > :cursor')->setParameter('cursor', $cursor)->orderBy('r.id', 'ASC')
                ->setMaxResults(max(1, min($limit, 1000)))->getQuery()->getResult();
            foreach ($receipts as $receipt) {
                $cursor = $receipt->getId();
                ++$result['checked'];
                try {
                    if ('verified' !== $receipt->getStatus()) {
                        $this->materialize($receipt->getId());
                        ++$result['recovered'];
                    } else {
                        $this->storage->verify($receipt);
                    }
                } catch (\RuntimeException) {
                    $receipt->markInconsistent();
                    $this->em->flush();
                    ++$result['inconsistent'];
                }
            }
        } while (count($receipts) === max(1, min($limit, 1000)));

        return $result;
    }
}
