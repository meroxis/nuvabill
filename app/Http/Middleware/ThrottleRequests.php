<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests as BaseThrottleRequests;

/**
 * "throttle:10,1" with a counter for each route. Laravel's own counter is shared by every route a
 * visitor uses, so ten price checks could block the order button, and client #1 and staff #1 shared
 * one counter. Named limiters such as "throttle:api-v1" work as before.
 */
class ThrottleRequests extends BaseThrottleRequests
{
    /**
     * @param  Request  $request
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        if ($route === null) {
            return parent::resolveRequestSignature($request);
        }

        $scope = $route->getName() ?? implode('|', $route->methods()).' '.$route->uri();
        $user = $request->user();
        $visitor = $user !== null
            ? $user::class.':'.$user->getAuthIdentifier()
            : 'guest:'.$route->getDomain().'|'.$request->ip();

        return self::$shouldHashKeys ? sha1($scope.'|'.$visitor) : $scope.'|'.$visitor;
    }
}
