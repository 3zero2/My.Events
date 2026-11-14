<?php

namespace Tests\Unit\Http\Actions\Attendees;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Http\Actions\Attendees\GetAttendeeActionPublic;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Resources\Attendee\AttendeeResourcePublic;
use HiEvents\Services\Domain\Tax\TaxAndFeeCalculationService;
use HiEvents\Services\Domain\Tax\TaxAndFeeRollupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GetAttendeeActionPublicTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[DataProvider('ticketTaxes')]
    public function test_ticket_product_prices_have_calculated_tax_totals(array $taxesAndFees, array $expectedTaxes): void
    {
        $prices = new Collection([
            (new ProductPriceDomainObject)->setId(10)->setPrice(1.00),
            (new ProductPriceDomainObject)->setId(11)->setPrice(10.00),
            (new ProductPriceDomainObject)->setId(12)->setPrice(0.00),
        ]);
        $product = (new ProductDomainObject)->setProductPrices($prices);
        $attendee = (new AttendeeDomainObject)->setProduct($product);
        $repository = Mockery::mock(AttendeeRepositoryInterface::class);
        $repository->shouldReceive('loadRelation')
            ->andReturnUsing(function (Relationship $relationship) use ($repository, $product, $taxesAndFees) {
                if ($relationship->getName() === 'product') {
                    foreach ($relationship->getNested() as $nested) {
                        if ($nested->getDomainObject() === TaxAndFeesDomainObject::class) {
                            $product->setTaxAndFees(new Collection(array_map(
                                fn (array $taxOrFee) => (new TaxAndFeesDomainObject)
                                    ->setName($taxOrFee[0])
                                    ->setType($taxOrFee[1])
                                    ->setCalculationType($taxOrFee[2])
                                    ->setRate($taxOrFee[3]),
                                $taxesAndFees,
                            )));
                        }
                    }
                }

                return $repository;
            });
        $repository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['short_id' => 'ticket-short-id'])
            ->andReturn($attendee);

        $response = new JsonResponse;
        $action = Mockery::mock(GetAttendeeActionPublic::class, [
            $repository,
            new TaxAndFeeCalculationService(new TaxAndFeeRollupService),
        ])->makePartial()->shouldAllowMockingProtectedMethods();
        $action->shouldReceive('resourceResponse')
            ->once()
            ->withArgs(function (string $resource, AttendeeDomainObject $result) use ($expectedTaxes) {
                $this->assertSame(AttendeeResourcePublic::class, $resource);
                $resultPrices = $result->getProduct()->getProductPrices();
                $this->assertSame($expectedTaxes, $resultPrices->map(fn ($price) => $price->getTaxTotal())->all());
                $this->assertSame([1.00, 10.00, 0.00], $resultPrices->map(fn ($price) => $price->getPrice())->all());

                return true;
            })
            ->andReturn($response);

        $this->assertSame($response, $action(1, 'ticket-short-id'));
    }

    public static function ticketTaxes(): array
    {
        return [
            'VAT' => [[['VAT', 'TAX', 'PERCENTAGE', 5]], [0.05, 0.50, 0.00]],
            'fixed tax' => [[['Ticket tax', 'TAX', 'FIXED', 0.25]], [0.25, 0.25, 0.00]],
            'multiple taxes' => [[
                ['VAT', 'TAX', 'PERCENTAGE', 5],
                ['Ticket tax', 'TAX', 'FIXED', 0.25],
            ], [0.30, 0.75, 0.00]],
            'VAT with booking fee' => [[
                ['VAT', 'TAX', 'PERCENTAGE', 5],
                ['Booking fee', 'FEE', 'FIXED', 0.07],
            ], [0.05, 0.50, 0.00]],
            'fees only' => [[['Booking fee', 'FEE', 'FIXED', 0.07]], [0.00, 0.00, 0.00]],
            'no taxes or fees' => [[], [0.00, 0.00, 0.00]],
        ];
    }
}
