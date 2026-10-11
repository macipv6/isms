<?php

namespace App\Http\Controllers;

use App\Models\CatalogVersion;
use App\Models\Framework;
use App\Services\Assessment\CatalogCsvImporter;
use App\Services\Assessment\CatalogCsvParser;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AssessmentCatalogController extends Controller
{
    public function index(Request $request, CatalogCsvImporter $importer)
    {
        $importer->authorize($request->user());

        return Inertia::render('assessment-catalogs/Index', [
            'frameworks' => Framework::query()->where('is_active', true)->get(['id', 'key', 'name']),
            'versions' => CatalogVersion::query()->with('framework:id,name')->withCount('questions')->latest('published_at')->limit(100)->get()->map(fn ($version) => [
                'id' => $version->id, 'framework' => $version->framework->name, 'version' => $version->version,
                'status' => $version->status->value, 'questions' => $version->questions_count,
            ]),
            'preview' => null,
        ]);
    }

    public function template(Request $request, CatalogCsvImporter $importer)
    {
        $importer->authorize($request->user());

        return response()->streamDownload(function (): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, CatalogCsvParser::HEADER, ';', '"', '');
            fputcsv($stream, ['example', 'Eigene Beispielkategorie', 'Keine offiziellen BSI-Texte', 10, 'example.review', 'Eigene Beispielfrage', 'Wird die eigene Sicherheitsleitlinie regelmäßig überprüft?', 'Nur Formatbeispiel; durch rechtmäßig vorhandene Inhalte ersetzen.', 'boolean', 'medium', 0, 1, 10, '[]', '[]'], ';', '"', '');
            fclose($stream);
        }, 'fragekatalog-vorlage.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request, CatalogCsvImporter $importer)
    {
        $importer->authorize($request->user());
        $data = $request->validate([
            'framework_id' => ['required', 'uuid'], 'version' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,32}$/'],
            'csv' => ['required', 'file', 'max:5120'],
        ], [
            'framework_id.required' => 'Bitte ein Framework auswählen.', 'framework_id.uuid' => 'Ungültiges Framework.',
            'version.required' => 'Bitte eine neue Versionsbezeichnung angeben.', 'version.string' => 'Ungültige Versionsbezeichnung.',
            'version.regex' => 'Version muss 1–32 Buchstaben, Ziffern, Punkte, Bindestriche oder Unterstriche enthalten.',
            'csv.required' => 'Bitte eine CSV-Datei auswählen.', 'csv.file' => 'CSV muss als Datei hochgeladen werden.',
            'csv.uploaded' => 'Datei konnte nicht hochgeladen werden.', 'csv.max' => 'CSV darf maximal 5 MiB groß sein.',
        ]);
        $batch = $importer->preview($request->user(), $data['framework_id'], $data['version'], file_get_contents($data['csv']->getRealPath()));

        return redirect()->route('assessment-catalogs.preview.show', $batch->id);
    }

    public function show(Request $request, string $batch, CatalogCsvImporter $importer)
    {
        $batch = $importer->batch($request->user(), $batch);
        $rows = $batch->payload;

        return Inertia::render('assessment-catalogs/Index', [
            'frameworks' => [], 'versions' => [], 'preview' => [
                'id' => $batch->id, 'version' => $batch->version, 'count' => count($rows),
                'framework' => Framework::query()->findOrFail($batch->framework_id)->name,
                'expiresAt' => $batch->expires_at->toIso8601String(),
                'rows' => array_map(fn ($row) => ['key' => $row['question_key'], 'title' => $row['title'], 'type' => $row['answer_type']], array_slice($rows, 0, 25)),
            ],
        ]);
    }

    public function confirm(Request $request, string $batch, CatalogCsvImporter $importer)
    {
        $importer->confirm($request->user(), $batch);

        return redirect()->route('assessment-catalogs.index')->with('success', 'Neue Fragekatalog-Version veröffentlicht. Bestehende Assessments behalten ihren Snapshot.');
    }
}
