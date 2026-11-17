<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Vendor::query()->orderBy('name');

        if ($s = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")
                ->orWhere('tax_pin', 'like', "%$s%")
                ->orWhere('contact_person', 'like', "%$s%"));
        }
        if (in_array($request->query('status'), ['active', 'blocked'], true)) {
            $q->where('status', $request->query('status'));
        }

        return response()->json(['data' => $q->limit(300)->get()->map(fn ($v) => $this->present($v))->values()]);
    }
        public function store(Request $request): JsonResponse
    {
        $v = Vendor::create($this->validated($request))->refresh();
        AuditService::log('VENDOR_CREATED', 'vendor', $v->id, null, $this->present($v));

        return response()->json(['data' => $this->present($v)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $v = Vendor::findOrFail($id);
        $old = $this->present($v);
        $v->update($this->validated($request, $v->id));
        $v->refresh();

        $action = 'VENDOR_UPDATED';
        if ($old['status'] !== $v->status) {
            $action = $v->status === 'blocked' ? 'VENDOR_BLOCKED' : 'VENDOR_UNBLOCKED';
        }
        AuditService::log($action, 'vendor', $v->id, $old, $this->present($v));

        return response()->json(['data' => $this->present($v)]);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $request->merge(['tax_pin' => strtoupper(trim((string) $request->input('tax_pin')))]);
        $phone = preg_replace('/[\s().-]+/', '', (string) $request->input('phone'));
        $request->merge(['phone' => $phone === '' ? null : $phone]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'regex:/^(?:\+?254|0)[17]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:120'],
            'tax_pin' => ['required', 'regex:/^[A-Z]\d{9}[A-Z]$/', Rule::unique('vendors', 'tax_pin')->ignore($ignoreId)],
            'status' => ['sometimes', 'in:active,blocked'],
        ], [
            'tax_pin.regex' => 'Enter a valid KRA PIN, for example P000000001A.',
            'tax_pin.unique' => 'A vendor with this PIN already exists.',
            'phone.regex' => 'Enter a valid Kenyan phone number, for example 0712345678.',
        ]);
    }

    private function present(Vendor $v): array
    {
        return [
            'id' => $v->id,
            'name' => $v->name,
            'contact_person' => $v->contact_person,
            'phone' => $v->phone,
            'email' => $v->email,
            'tax_pin' => $v->tax_pin,
            'status' => $v->status,
        ];
    }
}