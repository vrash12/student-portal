<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcements\AnnouncementRequest;
use App\Models\Announcement;
use App\Services\Announcements\AnnouncementPresenter;
use App\Services\Announcements\AnnouncementScope;
use App\Services\Announcements\AnnouncementService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notices to candidates (owner request, 2026-10-06): the staff list, posting,
 * changing and withdrawing. Where a user may post and which notices they
 * manage is decided by AnnouncementScope (through AnnouncementPolicy);
 * candidates read current notices on the portal home page.
 */
class AnnouncementController extends Controller
{
    private const PER_PAGE = 15;

    /** List filter: "active" (showing and scheduled) by default. */
    private const STATUS_FILTERS = ['active', 'current', 'scheduled', 'expired', 'withdrawn', 'all'];

    public function __construct(
        private readonly AnnouncementService $announcements,
        private readonly AnnouncementPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $scope = AnnouncementScope::for($request->user())->filteredBy($request);
        $status = in_array($request->query('status'), self::STATUS_FILTERS, true) ? (string) $request->query('status') : 'active';
        $now = now();

        $announcements = $this->filterByStatus($scope->visible(), $status, $now)
            ->with(['author:id,name', 'campus:id,name', 'classBatch:id,name'])
            ->orderByDesc('publishes_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('staff/announcements/index', [
            'announcements' => $announcements->through(fn (Announcement $announcement): array => $this->presenter->staffRow($announcement, $scope, $now)),
            'filters' => ['status' => $status, 'campus' => $scope->classScope()->campus->filterValue()],
            'campusOptions' => $scope->classScope()->campus->filterOptions(),
            // "all": every notice of the user's campuses; "own": the user manages only their own.
            'scope' => $scope->administers() ? 'all' : 'own',
        ]);
    }

    public function create(Request $request): Response
    {
        $scope = AnnouncementScope::for($request->user());

        return Inertia::render('staff/announcements/create', [
            'audiences' => array_map(fn (AnnouncementAudience $audience): array => [
                'value' => $audience->value,
                'label' => $audience->label(),
                'description' => $audience->description(),
            ], $scope->audiences()),
            'campusOptions' => $scope->campusOptions(),
            'classOptions' => $scope->classScope()->classOptions(),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $scope = AnnouncementScope::for($request->user());
        $audience = $request->audience();
        if (! $scope->allowsTarget($audience, $request->campusId(), $request->classBatchId())) {
            throw ValidationException::withMessages([
                $audience === AnnouncementAudience::ClassBatch ? 'class_batch_id' : ($audience === AnnouncementAudience::Campus ? 'campus_id' : 'audience') => 'You cannot post a notice to these candidates.',
            ]);
        }

        $announcement = $this->announcements->post($request->user(), $audience, $request->campusId(), $request->classBatchId(), $request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => $announcement->status() === AnnouncementStatus::Scheduled
            ? "Notice {$announcement->title} scheduled."
            : "Notice {$announcement->title} posted. Candidates see it on their home page."]);

        return redirect()->route('announcements.index');
    }

    public function edit(Request $request, Announcement $announcement): Response
    {
        $announcement->load(['campus:id,name', 'classBatch:id,name']);
        $timezone = (string) config('institution.timezone');

        return Inertia::render('staff/announcements/edit', [
            'announcement' => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'isImportant' => $announcement->is_important,
                'audience' => $this->presenter->audienceLabel($announcement),
                'status' => $announcement->status()->toArray(),
                // As the date-and-time inputs expect them, in the institution's timezone.
                'publishesAt' => $announcement->publishes_at->timezone($timezone)->format('Y-m-d\TH:i'),
                'expiresAt' => $announcement->expires_at?->timezone($timezone)->format('Y-m-d\TH:i'),
            ],
        ]);
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $this->announcements->update($announcement, $request->details(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notice saved.']);

        return redirect()->route('announcements.index');
    }

    public function withdraw(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->announcements->withdraw($announcement, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Notice {$announcement->title} withdrawn. Candidates no longer see it."]);

        return redirect()->back();
    }

    /**
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    private function filterByStatus(Builder $query, string $status, CarbonInterface $now): Builder
    {
        return match ($status) {
            'active' => $query->whereNull('withdrawn_at')->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now)),
            'current' => $query->showing($now),
            'scheduled' => $query->whereNull('withdrawn_at')->where('publishes_at', '>', $now),
            'expired' => $query->whereNull('withdrawn_at')->whereNotNull('expires_at')->where('expires_at', '<=', $now),
            'withdrawn' => $query->whereNotNull('withdrawn_at'),
            default => $query,
        };
    }
}
