<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\DedupedRecords;
use App\Entity\Organisation;
use App\Entity\Settlement;
use App\Entity\SettlementPayment;
use App\Entity\User;
use App\Enum\SettlementStatus;
use App\Repository\OrganisationRepository;
use App\Repository\PaymentRepository;
use App\Repository\SettlementPaymentRepository;
use App\Repository\SettlementRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The write side of seeding the ledger from raw provider records, shared by
 * both entry points: app:seed-ledger (SeedLedgerCommand) and
 * doctrine:fixtures:load (the App\DataFixtures classes). Lives here rather
 * than in either one so the two can't drift — the sample data must land in
 * the database identically whichever way it was loaded.
 *
 * Charge/refund/dispute ingestion (customer resolution included) is
 * PaymentService's job — see PaymentService and PaymentGatewayInterface for
 * why that split exists. Settlement/payout reconciliation lives here, since
 * it isn't payment creation.
 *
 * Idempotent by design: every entity is upserted by its unique `stripeId`
 * business key, so a rerun overwrites scalar fields rather than duplicating
 * rows. Callers own the transaction (SeedLedgerCommand wraps one explicitly;
 * the fixtures bundle's ORMExecutor already does), so a failure partway
 * rolls back cleanly despite the interim flushes below.
 *
 * Callers must ingest in order — charges, then refunds and disputes, then
 * payouts — since each later type references rows an earlier one created.
 */
final class LedgerSeeder
{
    // Demo credentials, deliberately not a secret — this is a single-tenant
    // demo, not a real multi-user system. See README.md.
    private const string ADMIN_EMAIL = 'admin@example.com';
    private const string ADMIN_PASSWORD = 'password';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganisationRepository $organisationRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly SettlementRepository $settlementRepository,
        private readonly SettlementPaymentRepository $settlementPaymentRepository,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PaymentService $paymentService,
    ) {
    }

    /**
     * Collapses records that arrived more than once (provider data delivery
     * isn't guaranteed exactly-once), keeping the first occurrence. Every
     * collapsed id is named, not just counted — an aggregate count alone
     * would leave no way to tell which specific ids were affected without
     * diffing the raw records by hand. A duplicate id with *different*
     * content is also reported in conflictingIds, since that's a real data
     * problem, not a benign redelivery.
     *
     * Pure, no database access — safe to call for a dry run.
     *
     * @param iterable<array<string, mixed>> $records
     */
    public function dedupe(iterable $records): DedupedRecords
    {
        $unique = [];
        $duplicateIds = [];
        $conflictingIds = [];
        foreach ($records as $record) {
            $id = (string) $record['id'];
            if (\array_key_exists($id, $unique)) {
                if ($unique[$id] !== $record) {
                    $conflictingIds[] = $id;
                }
                $duplicateIds[] = $id;
                continue;
            }
            $unique[$id] = $record;
        }

        return new DedupedRecords(array_values($unique), $duplicateIds, $conflictingIds);
    }

    /**
     * Also upserts the one demo login (admin@example.com / password, see
     * README.md) against the organisation — the login has nothing to log
     * into without seeded data, so it's seeded alongside it.
     */
    public function findOrCreateOrganisation(): Organisation
    {
        $organisation = $this->organisationRepository->findFirst();
        if ($organisation === null) {
            $organisation = new Organisation();
            $organisation->setName('Fixture Organisation');
            $this->entityManager->persist($organisation);
        }

        $this->upsertAdminUser($organisation);
        $this->entityManager->flush();

        return $organisation;
    }

    // Each pass below is flushed before returning — repository lookups run
    // real SQL queries, which won't see an earlier pass's not-yet-flushed
    // inserts otherwise. The caller's transaction still only commits once.

    /** @param list<array<string, mixed>> $records */
    public function recordCharges(Organisation $organisation, array $records): void
    {
        foreach ($records as $record) {
            $this->paymentService->recordCharge($organisation, $record);
        }
        $this->entityManager->flush();
    }

    /** @param list<array<string, mixed>> $records */
    public function recordRefunds(Organisation $organisation, array $records): void
    {
        foreach ($records as $record) {
            $this->paymentService->recordRefund($organisation, $record);
        }
        $this->entityManager->flush();
    }

    /** @param list<array<string, mixed>> $records */
    public function recordDisputes(Organisation $organisation, array $records): void
    {
        foreach ($records as $record) {
            $this->paymentService->recordDispute($organisation, $record);
        }
        $this->entityManager->flush();
    }

    /**
     * Reconciliation problems that are recoverable (an already-swept
     * payment, a total that doesn't match what was swept) come back as
     * warnings rather than exceptions — the caller decides where they go
     * (console output for the command, the logger for fixtures).
     *
     * @param list<array<string, mixed>> $records
     *
     * @return list<string> warnings
     */
    public function recordPayouts(Organisation $organisation, array $records): array
    {
        $warnings = [];

        // Flushed per-payout, not once after the whole loop: a later
        // payout's "is this payment already swept?" check
        // (findOneByPayment, a real query) must see an earlier payout
        // in this same pass's not-yet-committed SettlementPayment link,
        // or two payouts both listing the same payment id would both
        // think it's unswept and collide on the unique constraint
        // instead of the second one hitting the "already swept"
        // warning it's supposed to.
        foreach ($records as $record) {
            array_push($warnings, ...$this->upsertPayout($organisation, $record));
            $this->entityManager->flush();
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<string> warnings
     */
    private function upsertPayout(Organisation $organisation, array $record): array
    {
        $warnings = [];
        $stripeId = $record['id'];
        $status = SettlementStatus::from(strtoupper($record['status'] ?? 'paid'));
        $totalAmount = $record['amount'];
        $arrivalDate = new \DateTimeImmutable($record['arrival_date']);
        $createdAt = new \DateTimeImmutable($record['created']);

        $settlement = $this->settlementRepository->findOneByStripeId($stripeId);
        if ($settlement === null) {
            $settlement = new Settlement();
            $settlement->setOrganisation($organisation);
            $settlement->setStripeId($stripeId);
            $this->entityManager->persist($settlement);
        }

        $settlement->setTotalAmount($totalAmount);
        $settlement->setStatus($status);
        $settlement->setArrivalDate($arrivalDate);
        $settlement->setCreatedAt($createdAt);

        $sweptNet = 0;
        foreach ($record['included_balance_transactions'] ?? [] as $paymentStripeId) {
            $payment = $this->paymentRepository->findOneByStripeId($paymentStripeId);
            if ($payment === null) {
                throw new \RuntimeException(sprintf(
                    'Payout "%s" references "%s", which was not found.',
                    $stripeId,
                    $paymentStripeId,
                ));
            }

            $existingLink = $this->settlementPaymentRepository->findOneByPayment($payment);
            if ($existingLink !== null && $existingLink->getSettlement() !== $settlement) {
                // A payment can be swept by at most one settlement — enforced
                // in the schema too (unique index on payment_id).
                $warnings[] = sprintf(
                    'Payment "%s" is already swept by settlement "%s" — not re-linking it to "%s".',
                    $paymentStripeId,
                    $existingLink->getSettlement()->getStripeId(),
                    $stripeId,
                );
                continue;
            }

            if ($existingLink === null) {
                $settlementPayment = new SettlementPayment();
                $settlementPayment->setSettlement($settlement);
                $settlementPayment->setPayment($payment);
                $this->entityManager->persist($settlementPayment);
            }

            $sweptNet += $payment->getNetAmount();
        }

        if ($sweptNet !== $settlement->getTotalAmount()) {
            // Soft warning, not a hard failure — this fixture reconciles
            // cleanly, but crashing the whole seed on a future mismatch
            // would make ingestion brittle. Worth a monitored report later.
            $warnings[] = sprintf(
                'Settlement "%s" reports total %d but its swept payments sum to %d.',
                $stripeId,
                $settlement->getTotalAmount(),
                $sweptNet,
            );
        }

        return $warnings;
    }

    private function upsertAdminUser(Organisation $organisation): void
    {
        $user = $this->userRepository->findOneByEmail(self::ADMIN_EMAIL);

        if ($user === null) {
            $user = new User();
            $user->setOrganisation($organisation);
            $user->setEmail(self::ADMIN_EMAIL);
            $this->entityManager->persist($user);
        }

        // Rehash on every run rather than leaving a stale hash in place if
        // the hasher algorithm/cost ever changes — cheap, and keeps rerunning
        // the seed a genuine "reset to known state" rather than a partial one.
        // hashPassword() only reads $user's class to resolve the configured
        // hasher (password_hashers: App\Entity\User: auto) — it doesn't
        // touch $user's current password.
        $user->setPassword($this->passwordHasher->hashPassword($user, self::ADMIN_PASSWORD));
    }
}
