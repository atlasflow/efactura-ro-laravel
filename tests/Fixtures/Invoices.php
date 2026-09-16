<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Tests\Fixtures;

use AtlasFlow\EFacturaRo\Document\Address;
use AtlasFlow\EFacturaRo\Document\Document;
use AtlasFlow\EFacturaRo\Document\DocumentType;
use AtlasFlow\EFacturaRo\Document\Line;
use AtlasFlow\EFacturaRo\Document\LineVat;
use AtlasFlow\EFacturaRo\Document\Party;
use AtlasFlow\EFacturaRo\Document\PaymentMeans;
use AtlasFlow\EFacturaRo\Document\TaxSubtotal;
use AtlasFlow\EFacturaRo\Document\Totals;
use AtlasFlow\EFacturaRo\Support\Amount;
use AtlasFlow\EFacturaRo\Support\Cui;
use AtlasFlow\EFacturaRo\Vat\VatCategory;
use DateTimeImmutable;

final class Invoices
{
    public const string SELLER_CUI = '12345674';

    public static function standard(string $number = 'PV-2026-000123', Amount|string $payable = '1633.50'): Document
    {
        $s21 = LineVat::standard('21');

        return new Document(
            type: DocumentType::INVOICE,
            number: $number,
            issueDate: new DateTimeImmutable('2026-09-10'),
            currency: 'RON',
            seller: new Party('Pepiniera Verde SRL', new Address('Str. Florilor 10', 'Cluj-Napoca', 'RO', 'RO-CJ', '400001'), Cui::of(self::SELLER_CUI), true, 'J12/345/2015'),
            buyer: new Party('Grădina Albastră SRL', new Address('Bd. Unirii 5', 'SECTOR3', 'RO', 'RO-B', '030167'), Cui::of('40000000'), true),
            lines: [
                new Line('1', 'Buxus sempervirens 30-40 cm', Amount::of('100'), Amount::of('12.50'), Amount::of('1250.00'), $s21),
                new Line('2', 'Transport', Amount::of('1'), Amount::of('100.00'), Amount::of('100.00'), $s21),
            ],
            taxSubtotals: [new TaxSubtotal(VatCategory::S, Amount::of('21'), Amount::of('1350.00'), Amount::of('283.50'))],
            totals: new Totals(
                lineExtensionAmount: Amount::of('1350.00'),
                taxExclusiveAmount: Amount::of('1350.00'),
                taxAmount: Amount::of('283.50'),
                taxInclusiveAmount: Amount::of('1633.50'),
                payableAmount: Amount::of($payable),
            ),
            dueDate: new DateTimeImmutable('2026-10-10'),
            paymentMeans: [PaymentMeans::bankTransfer('RO49AAAA1B31007593840000')],
        );
    }
}
