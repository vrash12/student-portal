<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\AccountCategoryRequest;
use App\Models\AccountCategory;
use App\Services\Accounts\AccountService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Expense categories (route middleware: accounts.manage). Every category is
 * for charges; categories are listed by name.
 */
class AccountCategoryController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function index(): Response
    {
        return Inertia::render('staff/accounts/categories/index', [
            'categories' => AccountCategory::query()->ordered()->get()
                ->map(fn (AccountCategory $category): array => $this->present($category))->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('staff/accounts/categories/create');
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
            'category' => $this->present($accountCategory),
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
            'description' => $category->description,
            'isActive' => $category->is_active,
        ];
    }
}
