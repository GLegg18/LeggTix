<?php

namespace App\Http;

use App\Data\LoginResult;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class TrustedDeviceCookie
{
    public const NAME = '__Host-leggtix_trusted_device';

    public function credential(Request $request): ?string
    {
        $credential = $request->cookie(self::NAME);

        return is_string($credential) ? $credential : null;
    }

    public function apply(Response $response, LoginResult $result): Response
    {
        if ($result->trustedDeviceCredential !== null) {
            $response->headers->setCookie(new Cookie(
                self::NAME, $result->trustedDeviceCredential, $result->trustedDeviceExpiresAt,
                '/', null, true, true, false, Cookie::SAMESITE_STRICT,
            ));
        } elseif ($result->forgetTrustedDevice) {
            $this->forget($response);
        }

        return $response;
    }

    public function forget(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        // __Host- cookies require these attributes even when expiring the credential.
        $response->headers->setCookie(new Cookie(
            self::NAME, '', 1, '/', null, true, true, false, Cookie::SAMESITE_STRICT,
        ));

        return $response;
    }
}
