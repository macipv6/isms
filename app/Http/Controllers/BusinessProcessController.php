<?php

namespace App\Http\Controllers;

use App\Http\Requests\Processes\ChangeBusinessProcessStatusRequest;
use App\Http\Requests\Processes\StoreBusinessProcessRequest;
use App\Http\Requests\Processes\UpdateBusinessProcessRequest;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Registers\BusinessProcessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BusinessProcessController extends Controller
{
    public function store(StoreBusinessProcessRequest $request, Organization $organization, IsmsProject $project, BusinessProcessService $service): RedirectResponse
    {
        $service->create($project, $request->validated(), $this->actor($request));

        return back()->with('success', 'Prozess angelegt.');
    }

    public function update(UpdateBusinessProcessRequest $request, Organization $organization, IsmsProject $project, BusinessProcess $process, BusinessProcessService $service): RedirectResponse
    {
        $service->update($process, $request->safe()->except('updated_at'), $this->actor($request), $request->validated('updated_at'));

        return back()->with('success', 'Prozess aktualisiert.');
    }

    public function status(ChangeBusinessProcessStatusRequest $request, Organization $organization, IsmsProject $project, BusinessProcess $process, BusinessProcessService $service): RedirectResponse
    {
        $service->changeStatus($process, $request->boolean('active'), $this->actor($request));

        return back()->with('success', 'Prozessstatus aktualisiert.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
