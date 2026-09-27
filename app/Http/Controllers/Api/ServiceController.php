<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ServiceResource;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $services = Service::query()
            ->with('product')
            ->when($request->integer('client_id'), fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return ServiceResource::collection($services);
    }

    public function show(Service $service): ServiceResource
    {
        return new ServiceResource($service->load('product'));
    }

    /**
     * Suspend, unsuspend or terminate on the server, like the buttons in the admin area.
     */
    public function action(Request $request, Service $service, string $action, Provisioner $provisioner): JsonResponse
    {
        $result = match ($action) {
            'suspend' => $provisioner->suspend($service, (string) $request->string('reason', 'Suspended through the API')->limit(190, '')),
            'unsuspend' => $provisioner->unsuspend($service),
            'terminate' => $provisioner->terminate($service),
        };

        return response()->json([
            'success' => $result->success,
            'message' => $result->message,
            'service' => new ServiceResource($service->fresh()->load('product')),
        ], $result->success ? 200 : 422);
    }
}
