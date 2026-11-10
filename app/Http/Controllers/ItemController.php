<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Item::with('category')->orderBy('name');

        if ($s = trim((string) $request->query('search'))) {
            $q->where('name', 'like', "%$s%");
        }
        if ($request->filled('category_id')) {
            $q->where('category_id', $request->query('category_id'));
        }
        if (in_array($request->query('active'), ['0', '1'], true)) {
            $q->where('is_active', $request->query('active') === '1');
        }

        return response()->json(['data' => $q->limit(500)->get()->map(fn ($i) => $this->present($i))->values()]);
    }
        public function store(Request $request): JsonResponse
    {
        $item = Item::create($this->validated($request))->refresh()->load('category');
        AuditService::log('ITEM_CREATED', 'item', $item->id, null, $this->present($item));

        return response()->json(['data' => $this->present($item)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = Item::with('category')->findOrFail($id);
        $old = $this->present($item);
        $item->update($this->validated($request, $item->id));
        $item->refresh()->load('category');

        $action = 'ITEM_UPDATED';
        if ($old['is_active'] !== (bool) $item->is_active) {
            $action = $item->is_active ? 'ITEM_ACTIVATED' : 'ITEM_DEACTIVATED';
        }
        AuditService::log($action, 'item', $item->id, $old, $this->present($item));

        return response()->json(['data' => $this->present($item)]);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('items', 'name')->ignore($ignoreId)],
            'category_id' => ['required', 'exists:categories,id'],
            'unit' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'An item with this name already exists.',
        ]);
    }

    private function present(Item $i): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'category_id' => $i->category_id,
            'category' => $i->category?->name,
            'unit' => $i->unit,
            'description' => $i->description,
            'is_active' => (bool) $i->is_active,
        ];
    }
}