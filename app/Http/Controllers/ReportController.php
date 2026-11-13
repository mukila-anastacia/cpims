<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $service) {}

    public function summary(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json($this->service->summary($request->user(), $f['from'], $f['to'], $f['branch_id']));
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate(['type' => ['required', 'in:requests,orders,invoices']]);
        $user = $request->user();

        if ($data['type'] === 'invoices' && $user->hasRole('Branch Manager')) {
            abort(403, 'You cannot export invoices.');
        }

        $f = $this->filters($request);
        $branch = $this->service->branchFor($user, $f['branch_id']);
        [$header, $rows] = $this->service->export($data['type'], $f['from'], $f['to'], $branch);

        AuditService::log('REPORT_EXPORTED', 'report', null, null, ['type' => $data['type'], 'rows' => count($rows), 'from' => $f['from'], 'to' => $f['to'], 'branch_id' => $branch]);

        $name = 'cpims-' . $data['type'] . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // lets Excel read the file as UTF-8
            fputcsv($out, $header, ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($out, array_map([$this, 'safe'], $row), ',', '"', '\\');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops spreadsheet formula injection: text that starts with = + - @ is shown as plain text. */
    private function safe(mixed $v): string
    {
        $s = (string) ($v ?? '');

        return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
    }

    private function filters(Request $request): array
    {
        $d = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        return ['from' => $d['from'] ?? null, 'to' => $d['to'] ?? null, 'branch_id' => isset($d['branch_id']) ? (int) $d['branch_id'] : null];
    }
}