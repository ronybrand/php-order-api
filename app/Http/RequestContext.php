<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Actor;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

/** What the middleware leaves on the request for the code that runs after it. */
final class RequestContext
{
    private const string REQUEST_ID = 'request_id';
    private const string ACTOR = 'actor';

    public static function setRequestId(Request $request, string $id): void
    {
        $request->attributes->set(self::REQUEST_ID, $id);
    }

    /** Exceptions can be raised before the request-id middleware ran; they still get an id. */
    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get(self::REQUEST_ID);
        if (!is_string($id)) {
            $id = Uuid::uuid4()->toString();
            self::setRequestId($request, $id);
        }

        return $id;
    }

    public static function requireActor(Request $request): Actor
    {
        return self::actor($request) ?? throw \App\Domain\Error\ApiException::unauthenticated();
    }

    public static function setActor(Request $request, Actor $actor): void
    {
        $request->attributes->set(self::ACTOR, $actor);
    }

    /** The authenticated caller, or null on a request that has not been through the authentication middleware. */
    public static function actor(Request $request): ?Actor
    {
        $actor = $request->attributes->get(self::ACTOR);

        return $actor instanceof Actor ? $actor : null;
    }
}
