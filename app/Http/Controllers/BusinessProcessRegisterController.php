<?php

namespace App\Http\Controllers;

use App\Models\IsmsProject;
use App\Models\Organization;
use App\Services\Registers\RegisterPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessProcessRegisterController extends Controller
{
    public function __invoke(Request $request, Organization $organization, IsmsProject $project, RegisterPresenter $presenter): Response
    {
        return Inertia::render('processes/Index', $presenter->processes($request, $organization, $project));
    }
}
