<?php

namespace App\Http\Controllers;

use App\Documentation\ApiSpecification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApiDocumentationController
{
    public function index(): View
    {
        return view('api-documentation');
    }

    public function specification(): JsonResponse
    {
        // Resolve the optional generator only after the request's environment guard runs.
        return response()->json(app(ApiSpecification::class)->generate(), options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function asset(string $asset): BinaryFileResponse
    {
        $assets = [
            'swagger-ui-bundle.js' => ['vendor/swagger-ui/swagger-ui-bundle.js', 'application/javascript'],
            'swagger-ui.css' => ['vendor/swagger-ui/swagger-ui.css', 'text/css'],
            'api-documentation.js' => ['js/api-documentation.js', 'application/javascript'],
            'api-documentation.css' => ['css/api-documentation.css', 'text/css'],
        ];

        abort_unless(isset($assets[$asset]), 404);
        [$path, $type] = $assets[$asset];

        return response()->file(resource_path($path), ['Content-Type' => $type]);
    }
}
