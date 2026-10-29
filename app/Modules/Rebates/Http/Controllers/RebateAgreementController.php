<?php

namespace App\Modules\Rebates\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Http\StoreLevelOnly;
use App\Modules\Rebates\Models\RebateAgreement;
use App\Modules\Rebates\Services\RebateTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RebateAgreementController extends Controller
{
    use StoreLevelOnly;

    public function index(Request $request): JsonResponse
    {
        $this->guardStoreLevel();

        return response()->json(RebateAgreement::query()->withCount('clusters')->orderByDesc('effective_from')
            ->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function show(int $id): JsonResponse
    {
        $this->guardStoreLevel();

        return response()->json(RebateAgreement::with('clusters.tiers')->findOrFail($id));
    }

    public function templates(): JsonResponse
    {
        $this->guardStoreLevel();

        return response()->json(collect(RebateTemplates::available())->map(fn ($name, $key) => ['key' => $key, 'name' => $name])->values());
    }

    public function fromTemplate(Request $request, RebateTemplates $templates): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $data = $request->validate(['template' => ['required', 'string'], 'effective_from' => ['required', 'date']]);

        return response()->json($templates->create($storeId, $data['template'], $data['effective_from']), 201);
    }

    public function store(Request $request): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $data = $request->validate($this->rules($storeId));
        $this->assertStructure($data);

        return response()->json($this->save(new RebateAgreement(['store_id' => $storeId]), $data), 201);
    }

    /**
     * Replaces the clusters and tiers wholesale. Safe because every calculation keeps its own snapshot of the
     * rates it used, so editing an agreement never changes a month that was already calculated.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $storeId = $this->guardStoreLevel();
        $agreement = RebateAgreement::findOrFail($id);
        $data = $request->validate($this->rules($storeId));
        $this->assertStructure($data);

        return response()->json($this->save($agreement, $data));
    }

    private function save(RebateAgreement $agreement, array $data)
    {
        return DB::transaction(function () use ($agreement, $data) {
            $agreement->fill([
                'name' => $data['name'], 'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null,
                'method' => $data['method'], 'accounting_treatment' => $data['accounting_treatment'],
                'is_active' => $data['is_active'] ?? true, 'note' => $data['note'] ?? null,
            ])->save();

            $agreement->clusters()->delete(); // tiers cascade

            foreach (collect($data['clusters'])->values() as $i => $c) {
                $cluster = $agreement->clusters()->create([
                    'store_id' => $agreement->store_id, 'name' => $c['name'], 'volume_above' => $c['volume_above'] ?? null, 'sort_order' => $i,
                ]);

                foreach ($c['tiers'] ?? [] as $t) {
                    $cluster->tiers()->create([
                        'store_id' => $agreement->store_id, 'rebate_category_id' => $t['rebate_category_id'],
                        'from_cases' => $t['from_cases'], 'rate' => $t['rate'],
                    ]);
                }
            }

            return $agreement->load('clusters.tiers');
        });
    }

    private function rules(int $storeId): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'method' => ['required', Rule::in(RebateAgreement::METHODS)],
            'accounting_treatment' => ['required', Rule::in(RebateAgreement::TREATMENTS)],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
            'clusters' => ['required', 'array', 'min:1', 'max:8'],
            'clusters.*.name' => ['required', 'string', 'max:60'],
            'clusters.*.volume_above' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'clusters.*.tiers' => ['sometimes', 'array', 'max:300'],
            'clusters.*.tiers.*.rebate_category_id' => ['required', 'integer', Rule::exists('rebate_categories', 'id')->where('store_id', $storeId)],
            'clusters.*.tiers.*.from_cases' => ['required', 'integer', 'min:0', 'max:100000000'],
            'clusters.*.tiers.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    private function assertStructure(array $data): void
    {
        $bases = collect($data['clusters'])->filter(fn ($c) => ($c['volume_above'] ?? null) === null)->count();

        if ($bases !== 1) {
            throw ValidationException::withMessages(['clusters' => 'Exactly one cluster must have no volume threshold. It is the starting cluster.']);
        }

        $thresholds = collect($data['clusters'])->pluck('volume_above')->filter(fn ($v) => $v !== null);

        if ($thresholds->count() !== $thresholds->unique()->count()) {
            throw ValidationException::withMessages(['clusters' => 'Two clusters cannot share the same threshold.']);
        }

        foreach ($data['clusters'] as $i => $c) {
            $seen = [];

            foreach ($c['tiers'] ?? [] as $j => $t) {
                $key = $t['rebate_category_id'].':'.$t['from_cases'];

                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(["clusters.$i.tiers.$j.from_cases" => 'The same category has two tiers starting at the same volume.']);
                }

                $seen[$key] = true;
            }
        }
    }
}
