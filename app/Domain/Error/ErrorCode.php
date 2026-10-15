<?php

declare(strict_types=1);

namespace App\Domain\Error;

/**
 * The error catalog of the spec (DOMAIN.md section 6). A code is never reused for another meaning.
 */
enum ErrorCode: string
{
    case InvalidRequest = 'VALIDATION-01';
    case OrderNotEditable = 'VALIDATION-02';
    case OrderEmpty = 'VALIDATION-03';
    case InvalidTransition = 'VALIDATION-04';
    case InvalidFilter = 'VALIDATION-05';
    case UnknownSort = 'VALIDATION-06';
    case CustomerNotFoundInBody = 'VALIDATION-07';
    case AlreadyCanceled = 'VALIDATION-08';
    case ItemLimit = 'VALIDATION-09';
    case BodyTooLarge = 'VALIDATION-12';
    case MethodNotAllowed = 'VALIDATION-13';

    case RouteNotFound = 'RESOURCE-NOT-FOUND-00';
    case CustomerNotFound = 'RESOURCE-NOT-FOUND-01';
    case OrderNotFound = 'RESOURCE-NOT-FOUND-02';
    case ItemNotFound = 'RESOURCE-NOT-FOUND-03';

    case ConcurrentModification = 'CONFLICT-00';
    case DuplicateTaxId = 'CONFLICT-01';
    case DuplicatePassport = 'CONFLICT-02';
    case CustomerHasOrders = 'CONFLICT-03';
    case DataIntegrity = 'CONFLICT-04';

    case Unauthenticated = 'AUTH-01';
    case Forbidden = 'AUTH-02';

    case RateLimited = 'RATE-00';
    case Internal = 'INTERNAL-00';
}
