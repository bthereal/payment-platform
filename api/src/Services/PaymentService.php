<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\Customer;
use App\Entity\Organisation;
use App\Entity\Payment;
use App\Enum\PaymentDirection;
use App\Enum\PaymentSource;
use App\Repository\CustomerRepository;
use App\Repository\PaymentRepository;
use App\Services\PaymentGateway\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a payment gateway's raw charge/refund records into ledger Payment
 * rows. This is the provider-agnostic half of ingestion: it never reads a
 * raw record itself, only the PaymentGatewayInterface's normalized output,
 * so it has no idea whether the record originated from Stripe, Apple Pay,
 * or anything else — see PaymentGatewayInterface for how a new provider
 * plugs in.
 *
 * Upserts by the gateway's providerId, same rule as the rest of the seed
 * pipeline: a rerun overwrites scalar fields rather than duplicating rows.
 */
final class PaymentService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PaymentRepository $paymentRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly PaymentGatewayInterface $gateway,
    ) {
    }

    /** @param array<string, mixed> $record */
    public function recordCharge(Organisation $organisation, array $record): Payment
    {
        $charge = $this->gateway->normalizeCharge($record);
        $customer = $this->findOrCreateCustomer($organisation, $charge->customerReference);

        $payment = $this->paymentRepository->findOneByStripeId($charge->providerId);
        if ($payment === null) {
            $payment = new Payment();
            $payment->setOrganisation($organisation);
            $payment->setStripeId($charge->providerId);
            $payment->setDirection(PaymentDirection::CREDIT);
            $payment->setSource(PaymentSource::SALE);
            $this->entityManager->persist($payment);
        }

        $payment->setGrossAmount($charge->grossAmount);
        $payment->setStripeFee($charge->processorFee);
        $payment->setApplicationFee($charge->applicationFee);
        $payment->setNetAmount($charge->netAmount);
        $payment->setAvailableOn($charge->availableOn);
        $payment->setCreatedAt($charge->createdAt);
        $payment->setCustomer($customer);
        $payment->setOrderRef($charge->customerReference);

        return $payment;
    }

    /** @param array<string, mixed> $record */
    public function recordRefund(Organisation $organisation, array $record): Payment
    {
        $refund = $this->gateway->normalizeRefund($record);

        $relatedPayment = $this->paymentRepository->findOneByStripeId($refund->relatedChargeProviderId);
        if ($relatedPayment === null) {
            // No dangling references in the sample data — if one ever
            // shows up, that's a real data problem worth failing loudly on
            // rather than silently dropping the refund.
            throw new \RuntimeException(sprintf(
                'Refund "%s" references charge "%s", which was not found.',
                $refund->providerId,
                $refund->relatedChargeProviderId,
            ));
        }

        $payment = $this->paymentRepository->findOneByStripeId($refund->providerId);
        if ($payment === null) {
            $payment = new Payment();
            $payment->setOrganisation($organisation);
            $payment->setStripeId($refund->providerId);
            $payment->setDirection(PaymentDirection::DEBIT);
            $payment->setSource(PaymentSource::REFUND);
            $this->entityManager->persist($payment);
        }

        $payment->setGrossAmount($refund->grossAmount);
        // The processor's own fee is never reversed — only the application
        // fee is (see NormalizedRefund) — so this is always zero, never the
        // original charge's processor fee.
        $payment->setStripeFee(0);
        $payment->setApplicationFee($refund->applicationFee);
        $payment->setNetAmount($refund->netAmount);
        $payment->setAvailableOn($refund->availableOn);
        $payment->setCreatedAt($refund->createdAt);

        // The refund is a payment on the same customer as the charge it
        // reverses — the original charge Payment row is never mutated.
        $payment->setCustomer($relatedPayment->getCustomer());
        $payment->setRelatedPayment($relatedPayment);

        return $payment;
    }

    /** @param array<string, mixed> $record */
    public function recordDispute(Organisation $organisation, array $record): Payment
    {
        $dispute = $this->gateway->normalizeDispute($record);

        $relatedPayment = $this->paymentRepository->findOneByStripeId($dispute->relatedChargeProviderId);
        if ($relatedPayment === null) {
            // Same rule as recordRefund(): no dangling references in the
            // sample data, so a missing charge is a real data problem
            // worth failing loudly on rather than silently dropping.
            throw new \RuntimeException(sprintf(
                'Dispute "%s" references charge "%s", which was not found.',
                $dispute->providerId,
                $dispute->relatedChargeProviderId,
            ));
        }

        $payment = $this->paymentRepository->findOneByStripeId($dispute->providerId);
        if ($payment === null) {
            $payment = new Payment();
            $payment->setOrganisation($organisation);
            $payment->setStripeId($dispute->providerId);
            $payment->setDirection(PaymentDirection::DEBIT);
            $payment->setSource(PaymentSource::DISPUTE);
            $this->entityManager->persist($payment);
        }

        $payment->setGrossAmount($dispute->grossAmount);
        // Unlike a refund, the processor's fee here (the dispute_fee Stripe
        // charges for handling the dispute) is the whole point of the
        // record, so it belongs in stripeFee rather than being zeroed out.
        $payment->setStripeFee($dispute->disputeFee);
        $payment->setApplicationFee(0);
        $payment->setNetAmount($dispute->netAmount);
        $payment->setAvailableOn($dispute->availableOn);
        $payment->setCreatedAt($dispute->createdAt);

        // Same customer as the charge it disputes — the original charge
        // Payment row is never mutated.
        $payment->setCustomer($relatedPayment->getCustomer());
        $payment->setRelatedPayment($relatedPayment);

        return $payment;
    }

    private function findOrCreateCustomer(Organisation $organisation, ?string $orderRef): ?Customer
    {
        if ($orderRef === null) {
            return null;
        }

        $customer = $this->customerRepository->findOneByOrderRef($orderRef);
        if ($customer === null) {
            $customer = new Customer();
            $customer->setOrganisation($organisation);
            $customer->setOrderRef($orderRef);
            $customer->setSynthetic(true);
            $this->entityManager->persist($customer);
        }

        return $customer;
    }
}
