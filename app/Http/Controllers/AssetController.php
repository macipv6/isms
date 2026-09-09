<?php

namespace App\Http\Controllers;

use App\Http\Requests\Assets\ChangeAssetStatusRequest;
use App\Http\Requests\Assets\StoreAssetRequest;
use App\Http\Requests\Assets\UpdateAssetRequest;
use App\Models\Asset;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Registers\AssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function store(StoreAssetRequest $request, Organization $organization, IsmsProject $project, AssetService $service): RedirectResponse
    {
        $service->create($project, $request->validated(), $this->actor($request));

        return back()->with('success', 'Asset angelegt.');
    }

    public function update(UpdateAssetRequest $request, Organization $organization, IsmsProject $project, Asset $asset, AssetService $service): RedirectResponse
    {
        $service->update($asset, $request->safe()->except('updated_at'), $this->actor($request), $request->validated('updated_at'));

        return back()->with('success', 'Asset aktualisiert.');
    }

    public function status(ChangeAssetStatusRequest $request, Organization $organization, IsmsProject $project, Asset $asset, AssetService $service): RedirectResponse
    {
        $service->changeStatus($asset, $request->boolean('active'), $this->actor($request));

        return back()->with('success', 'Assetstatus aktualisiert.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
