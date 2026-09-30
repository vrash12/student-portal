<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CandidatePhotoController extends Controller
{
    /** Route policy authorizes staff access. */
    public function show(Candidate $candidate): BinaryFileResponse
    {
        return $this->photo($candidate);
    }

    /** Resolve ownership from the session, never from a submitted candidate ID. */
    public function own(Request $request): BinaryFileResponse
    {
        return $this->photo($request->user()->candidate()->firstOrFail());
    }

    private function photo(Candidate $candidate): BinaryFileResponse
    {
        $path = $candidate->profile_photo_path;
        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'no-store, private']);
    }
}
