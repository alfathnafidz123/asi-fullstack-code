<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $clients = Client::whereNull('deleted_at')->get();
        return response()->json($clients);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|max:250',
            'slug' => 'required|max:100|unique:clients',
            'is_project' => 'boolean',
            'self_capture' => 'boolean',
            'client_prefix' => 'required|max:4',
            'logo' => 'nullable|image|max:2048',
            'address' => 'nullable',
            'phone_number' => 'nullable|max:50',
            'city' => 'nullable|max:50'
        ]);

        $logoPath = 'no-image.jpg';
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('client-logos', 's3');
        }

        $client = Client::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'is_project' => $validated['is_project'] ?? false,
            'self_capture' => $validated['self_capture'] ?? true,
            'client_prefix' => $validated['client_prefix'],
            'client_logo' => $logoPath,
            'address' => $validated['address'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'city' => $validated['city'] ?? null,
        ]);

        // Cache in Redis
        Redis::set("client:{$client->slug}", $client->toJson());

        return response()->json($client, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // Check Redis first
        $cached = Redis::get("client:{$id}");
        if ($cached) {
            return response()->json(json_decode($cached));
        }

        $client = Client::where('slug', $id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Cache in Redis
        Redis::set("client:{$client->slug}", $client->toJson());

        return response()->json($client);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $client = Client::where('slug', $id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|max:250',
            'is_project' => 'boolean',
            'self_capture' => 'boolean',
            'client_prefix' => 'sometimes|max:4',
            'logo' => 'nullable|image|max:2048',
            'address' => 'nullable',
            'phone_number' => 'nullable|max:50',
            'city' => 'nullable|max:50'
        ]);

        $logoPath = $client->client_logo;
        if ($request->hasFile('logo')) {
            // Delete old logo if not default
            if ($logoPath !== 'no-image.jpg') {
                Storage::disk('s3')->delete($logoPath);
            }
            $logoPath = $request->file('logo')->store('client-logos', 's3');
        }

        $client->update([
            'name' => $validated['name'] ?? $client->name,
            'is_project' => $validated['is_project'] ?? $client->is_project,
            'self_capture' => $validated['self_capture'] ?? $client->self_capture,
            'client_prefix' => $validated['client_prefix'] ?? $client->client_prefix,
            'client_logo' => $logoPath,
            'address' => $validated['address'] ?? $client->address,
            'phone_number' => $validated['phone_number'] ?? $client->phone_number,
            'city' => $validated['city'] ?? $client->city,
        ]);

        // Update Redis cache
        Redis::del("client:{$id}");
        Redis::set("client:{$client->slug}", $client->toJson());

        return response()->json($client);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $client = Client::where('slug', $id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $client->delete();

        // Remove from Redis
        Redis::del("client:{$id}");

        return response()->json(null, 204);
    }
}
