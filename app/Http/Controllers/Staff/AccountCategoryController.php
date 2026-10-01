<?php

namespace App\Http\Controllers\Staff;

use App\Enums\AccountEntryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\AccountCategoryRequest;
use App\Models\AccountCategory;
use App\Services\Accounts\AccountService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Statement of Account categories (route middleware: accounts.manage).
 */
class AccountCategoryController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function index(): Response
    {
        return Inertia::render('staff/accounts/categories/index', [
            'categories' => AccountCategory::query()->withCount('entries')->ordered()->get()
                ->map(fn (AccountCategory $category): array => [
                    ...$this->present($category),
                    'entryCount' => (int) $category->entries_count,
                ])->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/accounts/categories/create', [
            'entryTypes' => AccountEntryType::options(),
            'nextSortOrder' => (int) AccountCategory::query()->max('sort_order') + 1,
        ]);
    }

    public function store(AccountCategoryRequest $request): RedirectResponse
    {
        $data = $request->categoryData();
        unset($data['is_active']);
        $category = $this->accounts->createCategory($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Category {$category->name} created."]);

        return redirect()->route('accounts.categories.index');
    }

    public function edit(AccountCategory $accountCategory): Response
    {
        return Inertia::render('staff/accounts/categories/edit', [
            'category' => [...$this->present($accountCategory), 'entryCount' => $accountCategory->entries()->count()],
            'entryTypes' => AccountEntryType::options(),
        ]);
    }

    public function update(AccountCategoryRequest $request, AccountCategory $accountCategory): RedirectResponse
    {
        $this->accounts->updateCategory($accountCategory, $request->categoryData());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Category {$accountCategory->name} updated."]);

        return redirect()->route('accounts.categories.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AccountCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'entryType' => ['value' => $category->entry_type->value, 'label' => $category->entry_type->label()],
            'description' => $category->description,
            'sortOrder' => $category->sort_order,
            'isActive' => $category->is_active,
        ];
    }
}
