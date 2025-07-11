<?php

namespace App\Http\Controllers;

use App\Models\{Document, DocumentLog, DocumentDownload};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class DocumentController extends Controller
{
    public function index()
    {
        $documents = Document::all();
        return view('documents.index', compact('documents'));
    }

    public function create()
    {
        return view('documents.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'file' => 'required|file|mimes:pdf,doc,docx|max:10240',
        ]);

        $file = $request->file('file');
        $path = $file->store('documents', 'public');

        $type = $request->input('type');
        $abbr = $this->abbreviateType($type);

        $date = Carbon::now()->format('Ymd');
        $countToday = Document::whereDate('created_at', Carbon::today())->count() + 1;
        $sequence = str_pad($countToday, 4, '0', STR_PAD_LEFT);

        $code = "DOCS-{$abbr}-{$date}-{$sequence}";
        $qrFileName = $request->title . '.png';

        QrCode::format('png')
            ->size(400)
            ->margin(2)
            ->generate($code, storage_path("app/public/qrcodes/{$qrFileName}"));

        $document = Document::create([
            'title' => $request->input('title'),
            'file_path' => $path,
            'code' => $code,
            'qr_code_path' => $qrFileName,
            'user_id' => auth()->id(),
            'type' => $type,
        ]);

        return redirect()->route('documents.success', [
            'code' => $code,
            'file_name' => $qrFileName
        ]);
    }

    private function abbreviateType($type)
    {
        return match (strtolower(trim($type))) {
            'confidential' => 'CONFID',
            'administrative order' => 'ADOR',
            'special order' => 'SO',
            'memo' => 'MEMO',
            'trace docs' => 'TRACE',
            'contract of service' => 'COS',
            'memorandum of understanding' => 'MOU',
            'jru' => 'JRU',
            'ipcr/dpcr' => 'IPDP',
            default => strtoupper(substr(preg_replace('/[^A-Z]/i', '', $type), 0, 5))
        };
    }

    public function success($code, $file_name)
    {
        $qrContent = route('documents.view', ['code' => $code]);
        $qrCodeSvg = QrCode::size(300)->generate($qrContent);

        return view('documents.upload-success', [
            'code' => $code,
            'qrCodeSvg' => $qrCodeSvg,
            'file_name' => $file_name
        ]);
    }

    public function view($code)
    {
        $document = Document::where('code', $code)->firstOrFail();
        $logs = $document->logs()->latest()->get();
        $downloads = $document->downloads()->latest()->get();

        return view('documents.view', compact('document', 'logs', 'downloads'));
    }

    public function edit($id)
    {
        $document = Document::findOrFail($id);
        return view('documents.edit', compact('document'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'file' => 'nullable|file|max:2048',
        ]);

        $document = Document::findOrFail($id);
        $path = $document->file_path;

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('documents', 'public');
        }

        $document->update([
            'title' => $request->input('title'),
            'type' => $request->input('type'),
            'file_path' => $path,
        ]);

        return redirect()->route('documents.index')->with('success', 'Document updated successfully.');
    }

    public function destroy($id)
    {
        $document = Document::findOrFail($id);
        $document->delete();

        return redirect()->route('documents.index')->with('success', 'Document deleted successfully.');
    }

    public function scan()
    {
        return view('documents.scan');
    }

    public function storeLog(Request $request)
    {
        $request->validate([
            'document_id' => 'required|exists:documents,id',
            'department' => 'required|string|max:255',
            'section' => 'required|string|max:255',
        ]);

        DocumentLog::create([
            'document_id' => $request->document_id,
            'department' => $request->department,
            'section' => $request->section,
            'logged_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Document activity logged successfully.');
    }

    public function download($id)
    {
        $document = Document::findOrFail($id);
        $user = Auth::user();

        DocumentDownload::create([
            'document_id' => $document->id,
            'user_id' => $user->id ?? null,
            'department' => $user->department->name ?? 'Unknown',
            'section' => $user->section->name ?? 'Unknown',
        ]);

        return response()->download(storage_path("app/public/{$document->file_path}"));
    }

    public function downloadLogs()
    {
        $downloads = DocumentDownload::with(['document', 'user'])->latest()->get();
        return view('documents.download-logs', compact('downloads'));
    }

    public function viewByCode(Request $request)
    {
        $document = Document::where('code', $request->code)->firstOrFail();
        $logs = $document->logs()->latest()->get();
        $downloads = $document->downloads()->latest()->get();

        return view('documents.view', compact('document', 'logs', 'downloads'));
    }

    public function myFiles()
    {
        $documents = Document::where('user_id', auth()->id())->latest()->get();
        return view('documents.partials.my-files', compact('documents'));
    }

    public function allDocuments()
    {
        $documents = Document::latest()->get();
        return view('documents.all', compact('documents'));
    }
}
