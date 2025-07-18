<?php

namespace App\Http\Controllers;

use App\Models\{
    Document,
    DocumentLog,
    DocumentDownload,
    Division,
    Section,
    User
};
use App\Notifications\PrivateDocumentShared;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
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
        $users = User::where('id', '!=', auth()->id())->get();
        return view('documents.create', compact('users'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'file' => 'required|file|mimes:pdf,doc,docx|max:10240',
            'visibility' => 'required|in:public,private',
            'recipients' => 'required_if:visibility,private|array',
            'recipients.*' => 'exists:users,id',
        ]);

        $file = $request->file('file');
        $path = $file->store('documents', 'public');
        $type = $request->input('type');
        $abbr = $this->abbreviateType($type);
        $date = Carbon::now()->format('Ymd');
        $countToday = Document::whereDate('created_at', Carbon::today())->count() + 1;
        $sequence = str_pad($countToday, 4, '0', STR_PAD_LEFT);
        $code = "{$abbr}-{$date}-{$sequence}";
        $qrFileName = $request->title . '.png';
        $qrContent = route('documents.view', ['code' => $code]);
        QrCode::format('png')->size(400)->margin(2)->generate($qrContent, storage_path("app/public/qrcodes/{$qrFileName}"));

        $document = Document::create([
            'title' => $request->input('title'),
            'file_path' => $path,
            'code' => $code,
            'qr_code_path' => $qrFileName,
            'user_id' => auth()->id(),
            'type' => $type,
            'visibility' => $request->input('visibility'),
        ]);

        if ($request->input('visibility') === 'private') {
            $recipientsIds = $request->input('recipients');
            $document->recipients()->attach($recipientsIds);

            $recipients = User::find($recipientsIds);
            Notification::send($recipients, new PrivateDocumentShared($document, auth()->user()));
        }

        return redirect()->route('documents.success', ['code' => $code, 'file_name' => $qrFileName]);
    }

    private function abbreviateType($type)
    {
        return match (strtolower(trim($type))) {
            'confidential' => 'CONFID', 'administrative order' => 'ADOR', 'special order' => 'SO',
            'memo' => 'MEMO', 'trace docs' => 'TRACE', 'contract of service' => 'COS',
            'memorandum of understanding' => 'MOU', 'jru' => 'JRU', 'ipcr/dpcr' => 'IPDP',
            default => strtoupper(substr(preg_replace('/[^A-Z]/i', '', $type), 0, 5))
        };
    }

    public function success($code, $file_name)
    {
        $qrContent = route('documents.view', ['code' => $code]);
        $qrCodeSvg = QrCode::size(300)->generate($qrContent);
        return view('documents.upload-success', ['code' => $code, 'qrCodeSvg' => $qrCodeSvg, 'file_name' => $file_name]);
    }

    public function view($code)
    {
        $document = Document::with(['user', 'recipients'])->where('code', $code)->firstOrFail();

        if (!$this->isAuthorized($document)) {
            return redirect()->route('dashboard')->with('error', 'You are not authorized to view this private document.');
        }

        $logs = $document->logs()->with(['division', 'section', 'user'])->latest()->get();
        return view('documents.view', compact('document', 'logs'));
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

    public function downloadQr($file_name)
    {
        $filePath = storage_path("app/public/qrcodes/{$file_name}");

        if (!file_exists($filePath)) {
            abort(404, 'QR code not found.');
        }

        return response()->download($filePath);
    }

    public function end($id)
    {
        $document = Document::findOrFail($id);
        
        if (auth()->id() !== $document->user_id) {
            return redirect()->route('dashboard')->with('error', 'You are not authorized to perform this action.');
        }
        if ($document->status === 'ended') {
            return redirect()->back()->with('error', 'This document has already been marked as ended.');
        }
        $document->update(['status' => 'ended']);
        $document->logs()->create([
            'user_id' => auth()->id(), 'division_id' => auth()->user()->division_id,
            'section_id' => auth()->user()->section_id, 'action' => 'Ended',
            'remarks' => 'Document lifecycle marked as ended by the uploader.',
        ]);
        return redirect()->back()->with('success', 'Document has been successfully marked as ended.');
    }

    public function accessFile($id, $action)
    {
        $document = Document::findOrFail($id);

        if (!$this->isAuthorized($document)) {
            return redirect()->route('dashboard')->with('error', 'You are not authorized to access this file.');
        }
        
        $user = Auth::user();

        if ($document->status === 'ended' && $action === 'download' && $document->user_id !== $user->id) {
            return redirect()->route('documents.view', $document->code)
                             ->with('error', 'This document has been ended and can no longer be downloaded.');
        }

        $logMessage = ($action == 'view') ? 'Viewed file' : 'Downloaded file';
        DocumentLog::create([
            'document_id' => $document->id, 'user_id' => $user->id,
            'division_id' => $user->division_id, 'section_id' => $user->section_id,
            'action' => $logMessage, 'received_at' => now(),
        ]);

        $filePath = storage_path("app/public/{$document->file_path}");
        if (!file_exists($filePath)) { abort(404, 'File not found.'); }

        return $action == 'view' ? response()->file($filePath) : response()->download($filePath);
    }

    public function receiveFile(Request $request, $id)
    {
        $request->validate(['remarks' => 'nullable|string|max:1000']);
        $document = Document::findOrFail($id);
        
        if (!$this->isAuthorized($document)) {
            return redirect()->route('dashboard')->with('error', 'You are not authorized to receive this document.');
        }

        $user = Auth::user();
        $action = 'Received and Downloaded';

        if ($document->status === 'ended' && $document->user_id !== $user->id) {
             return redirect()->route('documents.view', $document->code)
                             ->with('error', 'This document has been ended and cannot be received.');
        }

        $lastLog = DocumentLog::where('document_id', $document->id)->where('user_id', $user->id)
            ->where('action', $action)->latest('received_at')->first();
        if ($lastLog) {
            return redirect()->route('documents.view', $document->code)
                ->with('error', 'You have already logged this document as received.');
        }

        DocumentLog::create([
            'document_id' => $document->id, 'user_id' => $user->id,
            'division_id' => $user->division_id, 'section_id' => $user->section_id,
            'action' => $action, 'remarks' => $request->input('remarks'), 'received_at' => now(),
        ]);

        $filePath = storage_path("app/public/{$document->file_path}");
        if (!file_exists($filePath)) { abort(404, 'File not found.'); }
        return response()->download($filePath);
    }

    private function isAuthorized(Document $document): bool
    {
        if ($document->visibility === 'public') {
            return true;
        }

        if (auth()->id() === $document->user_id) {
            return true;
        }

        if ($document->recipients()->where('user_id', auth()->id())->exists()) {
            return true;
        }

        return false;
    }
}
