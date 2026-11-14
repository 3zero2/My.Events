<?php

namespace HiEvents\Http\Actions\Attendees;

use HiEvents\DomainObjects\EventLocationDomainObject;
use HiEvents\DomainObjects\EventOccurrenceDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\LocationDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Helper\Currency;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Resources\Attendee\AttendeeResourcePublic;
use HiEvents\Services\Domain\Tax\TaxAndFeeCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetAttendeeActionPublic extends BaseAction
{
    private AttendeeRepositoryInterface $attendeeRepository;

    public function __construct(
        AttendeeRepositoryInterface $attendeeRepository,
        private readonly TaxAndFeeCalculationService $taxCalculationService,
    ) {
        $this->attendeeRepository = $attendeeRepository;
    }

    public function __invoke(int $eventId, string $attendeeShortId): JsonResponse|Response
    {
        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(
                domainObject: ProductDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: ProductPriceDomainObject::class,
                    ),
                    new Relationship(
                        domainObject: TaxAndFeesDomainObject::class,
                    ),
                ], name: 'product'))
            ->loadRelation(new Relationship(
                domainObject: EventOccurrenceDomainObject::class,
                nested: [
                    new Relationship(
                        domainObject: EventLocationDomainObject::class,
                        nested: [
                            new Relationship(domainObject: LocationDomainObject::class, name: 'location'),
                        ],
                        name: 'event_location',
                    ),
                ],
                name: 'event_occurrence',
            ))
            ->findFirstWhere([
                AttendeeDomainObjectAbstract::SHORT_ID => $attendeeShortId,
            ]);

        if (! $attendee) {
            return $this->notFoundResponse();
        }

        $product = $attendee->getProduct();
        foreach ($product?->getProductPrices() ?? [] as $price) {
            $taxAndFees = $this->taxCalculationService->calculateTaxAndFeesForProduct($product, $price->getPrice());
            $price->setTaxTotal(Currency::round($taxAndFees->taxTotal));
        }

        return $this->resourceResponse(AttendeeResourcePublic::class, $attendee);
    }
}
