<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Order;

use Barryvdh\DomPDF\PDF;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\InvoiceStatus;
use HiEvents\Services\Domain\Order\InvoicePdfRenderer;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class InvoicePdfRendererTest extends TestCase
{
    public function test_chinese_text_is_rendered_with_an_embedded_cjk_font_subset(): void
    {
        $output = $this->renderInvoice(
            fullName: ['张', '伟'],
            eventTitle: '2026 上海国际音乐节',
            invoiceNumber: '发票-0001',
            itemName: '普通门票',
            address: ['address_line_1' => '北京市朝阳区建国路88号', 'city' => '北京', 'country' => 'CN'],
        )->output();

        $this->assertStringContainsString('+NotoSansSC-Regular', $output);
        $this->assertStringContainsString('+NotoSansSC-Bold', $output);
        $this->assertStringNotContainsString('/BaseFont /Times', $output);
        $this->assertLessThan(200_000, strlen($output));
    }

    public function test_latin_only_invoice_does_not_embed_the_cjk_font(): void
    {
        $output = $this->renderInvoice(
            fullName: ['Jane', 'Doe'],
            eventTitle: 'Summer Festival',
            invoiceNumber: 'INV-0001',
            itemName: 'General Admission',
            address: ['address_line_1' => '1 Main Street', 'city' => 'Dublin', 'country' => 'IE'],
        )->output();

        $this->assertStringContainsString('+DejaVuSans', $output);
        $this->assertStringNotContainsString('NotoSansSC', $output);
        $this->assertStringNotContainsString('/BaseFont /Times', $output);
    }

    public function test_invoice_still_renders_without_the_cjk_font_when_the_font_cache_is_not_writable(): void
    {
        $logger = Mockery::spy(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $logger);
        $blockingFile = tempnam(sys_get_temp_dir(), 'font-cache');
        $viewData = [];
        $this->app['view']->composer('invoice', function ($view) use (&$viewData) {
            $viewData = $view->getData();
        });
        config([
            'dompdf.options.font_dir' => $blockingFile.'/fonts',
            'dompdf.options.font_cache' => $blockingFile.'/fonts',
        ]);

        try {
            $output = $this->renderInvoice(
                fullName: ['张', '伟'],
                eventTitle: 'Summer Festival',
                invoiceNumber: 'INV-0001',
                itemName: 'General Admission',
                address: [],
            )->output();
        } finally {
            unlink($blockingFile);
        }

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertFalse($viewData['embedCjkFont']);
        $logger->shouldHaveReceived('warning')->once();
    }

    #[DataProvider('invoiceContactEmails')]
    public function test_invoice_footer_shows_the_event_contact_and_ticketing_notice(?string $supportEmail, string $expectedEmail): void
    {
        $pdf = $this->renderInvoice(
            fullName: ['Jane', 'Doe'],
            eventTitle: 'Summer Festival',
            invoiceNumber: 'INV-0001',
            itemName: 'General Admission',
            address: [],
            supportEmail: $supportEmail,
        );

        $document = $pdf->getDomPDF()->getDom();
        $footer = (new \DOMXPath($document))->query('//div[@class="invoice-footer"]')->item(0);
        $this->assertNotNull($footer);
        $this->assertStringContainsString('For any queries, please contact the event organiser at '.$expectedEmail, $footer->textContent);
        $this->assertStringContainsString('Ticketing services provided by tickets.mt, VAT Reg. No: 22755327, Exemption Number: EXO6001. VAT Exempt.', $footer->textContent);
    }

    public static function invoiceContactEmails(): array
    {
        return [
            'event support email' => ['support@example.com', 'support@example.com'],
            'missing support email' => [null, 'organizer@example.com'],
            'empty support email' => ['', 'organizer@example.com'],
        ];
    }

    private function renderInvoice(
        array $fullName,
        string $eventTitle,
        string $invoiceNumber,
        string $itemName,
        array $address,
        ?string $supportEmail = null,
    ): PDF {
        $order = (new OrderDomainObject)
            ->setFirstName($fullName[0])
            ->setLastName($fullName[1])
            ->setEmail('buyer@example.com')
            ->setCurrency('USD')
            ->setTotalBeforeAdditions(25)
            ->setTotalGross(25)
            ->setTotalTax(0)
            ->setTotalFee(0)
            ->setAddress($address);

        $invoice = (new InvoiceDomainObject)
            ->setInvoiceNumber($invoiceNumber)
            ->setIssueDate('2026-10-11 00:00:00')
            ->setStatus(InvoiceStatus::PAID->name)
            ->setItems([[
                'item_name' => $itemName,
                'description' => null,
                'price' => 25,
                'price_before_discount' => null,
                'quantity' => 1,
                'total_before_additions' => 25,
            ]]);

        return app(InvoicePdfRenderer::class)->render(
            order: $order,
            event: (new EventDomainObject)->setTitle($eventTitle),
            organizer: (new OrganizerDomainObject)->setName('Organizer')->setEmail('organizer@example.com'),
            eventSettings: (new EventSettingDomainObject)->setOrganizationName('Organizer')->setSupportEmail($supportEmail),
            invoice: $invoice,
        );
    }
}
