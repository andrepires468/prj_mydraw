<?php

namespace App\Http\Controllers;

use App\Services\EditorProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EditorProfileController extends Controller
{
    public function __construct(private readonly EditorProfileService $editorProfileService)
    {
    }

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->editorProfileService->getPublicEditorProfile());
    }
}
