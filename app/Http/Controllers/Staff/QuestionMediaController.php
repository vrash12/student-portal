<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionMedia;
use App\Services\QuestionBank\QuestionMediaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Images, audio, and video of a question. Changes need question bank access
 * to the subject (route policy "update"); viewing needs teaching the subject.
 */
class QuestionMediaController extends Controller
{
    public function __construct(private readonly QuestionMediaService $media) {}

    public function store(Request $request, Question $question): RedirectResponse
    {
        $data = $request->validate([
            // Detailed type and size limits are checked by QuestionMediaService from the file's contents.
            'file' => ['required', 'file', 'max:30720'],
            'description' => ['required', 'string', 'max:500'],
        ], [
            'file.max' => 'Files can be at most 30 MB (images 5 MB, audio 15 MB).',
            'description.required' => 'Describe the file for candidates who cannot see or hear it.',
        ]);

        $this->media->add($question, $request->file('file'), trim($data['description']), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Media added.']);

        return back();
    }

    public function update(Request $request, Question $question, QuestionMedia $medium): RedirectResponse
    {
        $data = $request->validate(['description' => ['required', 'string', 'max:500']]);

        if ($this->media->describe($medium, trim($data['description']), $request->user())) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Description saved.']);
        }

        return back();
    }

    public function destroy(Request $request, Question $question, QuestionMedia $medium): RedirectResponse
    {
        $this->media->remove($medium, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Media removed.']);

        return back();
    }

    public function show(QuestionMedia $medium): BinaryFileResponse
    {
        Gate::authorize('viewMedia', $medium->question);

        return $this->file($this->media, $medium);
    }

    public static function file(QuestionMediaService $service, QuestionMedia $media): BinaryFileResponse
    {
        $path = $service->absolutePath($media);
        abort_if($path === null, 404);

        // Range requests (video seeking) are handled by BinaryFileResponse.
        return response()->file($path, [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
