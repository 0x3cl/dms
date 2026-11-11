<?php

namespace App\Services\Files;

use App\Enums\FileAbility;
use App\Models\File;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * How a file is previewed: which viewer, and where it reads the bytes from.
 *
 * One place rather than a check in the page and another in the controller,
 * because the question is not only cosmetic. Most of the answers are served by
 * this application out of the private disk; one of them hands a URL to a third
 * party. Deciding that in a template would mean a template deciding whether a
 * document leaves the building.
 *
 * - **Office documents** — Word, Excel, PowerPoint — render through
 *   **Microsoft's Office viewer** (`view.officeapps.live.com/op/embed.aspx`),
 *   which renders them on Microsoft's servers and returns an embedded frame. No
 *   browser can render these formats itself.
 * - **PDFs** render in the same frame, from **our own stream** — the browser's
 *   built-in renderer. They are deliberately *not* sent to the Office viewer:
 *   it does not handle PDFs at all, so a PDF given to it loads a frame that
 *   then reports it cannot open the file. Keeping them local also means a PDF
 *   previews on an installation no outside service can reach.
 * - **Images, video and audio** render natively, straight from our own stream
 *   route: an `<img>`, a `<video>`, an `<audio>`. No third party is involved
 *   and nothing leaves the server.
 * - **Anything else** has no preview and says so, which is a better answer than
 *   an empty frame.
 *
 * ## The viewer has to be able to *reach* the file
 *
 * This is the part that surprises people. An embedded viewer is not a renderer
 * running in the browser — Microsoft fetches the URL from their own servers —
 * so the file has to be reachable **from the public internet**. On a laptop
 * serving `http://localhost:8000`, or on an office network behind a firewall,
 * no such viewer can ever work, however correct the markup is: the frame simply
 * stays empty.
 *
 * So `viewerCanReachUs()` checks the application's own URL and, when it is
 * plainly local, the descriptor carries no viewer URL at all and an Office
 * document says plainly that it has to be downloaded. An honest "download to
 * view" beats a spinner that will never resolve. PDFs are unaffected, because
 * they never needed the viewer in the first place.
 */
class FilePreview
{
    /** How long a signed viewer URL stays valid. */
    public const VIEWER_URL_TTL_MINUTES = 10;

    /**
     * Microsoft's Office viewer. Its `src` parameter must be a URL that their
     * servers can fetch.
     */
    public const VIEWER = 'https://view.officeapps.live.com/op/embed.aspx';

    /**
     * Extensions the Office viewer handles — and only those.
     *
     * Matched on extension rather than on the sniffed type, deliberately: every
     * modern Office format is a zip archive underneath, so a correctly sniffed
     * `.docx` is `application/zip` and would be filed as an archive. The viewer
     * dispatches on the extension in the URL for the same reason.
     *
     * **No `pdf` here.** The viewer does not render PDFs; one sent to it loads
     * a frame that then reports it cannot open the file.
     *
     * @var list<string>
     */
    public const VIEWER_EXTENSIONS = [
        'doc', 'docx', 'docm', 'dot', 'dotx',
        'xls', 'xlsx', 'xlsm', 'xlsb', 'csv',
        'ppt', 'pptx', 'ppsx', 'pps', 'potx',
    ];

    /**
     * Hosts no external viewer can fetch from.
     *
     * Not an exhaustive list of private space — it is the set that actually
     * comes up: a developer machine, a `.test` domain, an office LAN. Anything
     * matching means "do not offer a frame that cannot load".
     *
     * @var list<string>
     */
    private const UNREACHABLE_HOSTS = [
        'localhost', '127.*', '::1', '0.0.0.0',
        '*.test', '*.local', '*.localhost', '*.internal', '*.lan',
        '10.*', '192.168.*', '172.16.*', '172.17.*', '172.18.*', '172.19.*',
        '172.2?.*', '172.30.*', '172.31.*',
    ];

    public function __construct(private readonly AccessResolver $access) {}

    /**
     * The descriptor a preview page needs.
     *
     * @return array<string, mixed>
     */
    public function describe(File $file, ?User $viewer): array
    {
        $mode = $this->modeFor($file);
        $stream = route('files.stream', ['owner' => $file->owner?->public_id, 'file' => $file->public_id]);
        $mayDownload = $viewer !== null
            && $this->access->allows($viewer, $file, FileAbility::Download);

        $viewerUrl = $mode === 'document' ? $this->viewerUrl($file) : null;

        return [
            'mode' => $mode,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'extension' => $file->extension,
            'size' => $file->size_bytes,
            // `inline` on the stream route is what distinguishes a preview from
            // a download, both for the response headers and for which audit row
            // is written. Carried for a document too, because a PDF falls back
            // to the browser's own renderer when no viewer can reach us.
            'src' => $stream.'?disposition=inline',
            'viewer_url' => $viewerUrl,
            /*
             * True only when the *network* is the reason there is no viewer
             * URL. A confidential document has none either, and saying "nothing
             * outside this network can reach this file" there would send
             * somebody looking for a problem in their firewall when the answer
             * is that the item is classified.
             */
            'viewer_unreachable' => $mode === 'document'
                && $viewerUrl === null
                && $this->mayLeaveForViewer($file)
                && ! $this->viewerCanReachUs(),
            // A PDF is the one document format a browser can render by itself,
            // so it is the one that still has something to show without a
            // viewer.
            'renders_locally' => $this->rendersLocally($file),
            'download_url' => $mayDownload ? $stream : null,
            'can_download' => $mayDownload,
        ];
    }

    /**
     * Which viewer, from the extension first and the sniffed type second.
     *
     * The extension is the only usable signal for the Office formats, which are
     * all zip archives underneath; the type is the more trustworthy of the two
     * for anything the browser renders itself, because it is what the response
     * will actually carry.
     *
     * One `document` mode covers both halves — a PDF the browser renders and an
     * Office file the viewer renders — so the page has one frame and one set of
     * controls rather than a branch per format. *Which* of the two it is comes
     * from `viewer_url` and `renders_locally`, not from the mode.
     */
    public function modeFor(File $file): string
    {
        $type = (string) $file->mime_type;

        return match (true) {
            $this->rendersLocally($file) => 'document',
            $this->mayLeaveForViewer($file) => 'document',
            str_starts_with($type, 'image/') && $type !== 'image/svg+xml' => 'image',
            str_starts_with($type, 'video/') => 'video',
            str_starts_with($type, 'audio/') => 'audio',
            default => 'none',
        };
    }

    /** Can the browser render this one without help? */
    public function rendersLocally(File $file): bool
    {
        return (string) $file->mime_type === 'application/pdf'
            || strtolower((string) $file->extension) === 'pdf';
    }

    /**
     * May this file's bytes be handed to an external viewer?
     *
     * The same rule a share link answers to, and for the same reason: a
     * confidential item is reachable only by somebody named on the item itself,
     * and a URL that works without a session is not that. Checked when the URL
     * is minted *and* on every fetch, because an item can be reclassified after
     * one has been handed out.
     */
    public function mayLeaveForViewer(File $file): bool
    {
        if (! in_array(strtolower((string) $file->extension), self::VIEWER_EXTENSIONS, true)) {
            return false;
        }

        return $this->access->effectiveVisibility($file)->linkable();
    }

    /**
     * The viewer URL, wrapping a signed URL to this file — or null when no
     * outside service could fetch it anyway.
     *
     * `rawurlencode`, not `urlencode`: the `url` parameter is a URL inside a
     * URL, and a `+` for a space would be read as a literal plus by the
     * fetching end.
     */
    public function viewerUrl(File $file): ?string
    {
        if (! $this->mayLeaveForViewer($file) || ! $this->viewerCanReachUs()) {
            return null;
        }

        $signed = URL::temporarySignedRoute('files.viewer', now()->addMinutes(self::VIEWER_URL_TTL_MINUTES), [
            'owner' => $file->owner?->public_id,
            'file' => $file->public_id,
        ]);

        return self::VIEWER.'?src='.rawurlencode($signed);
    }

    /**
     * Could an external viewer fetch from this installation at all?
     *
     * Read off `app.url`, because that is what every generated URL is built
     * from — if it names a host the internet cannot resolve, nothing we hand
     * out can be fetched, and the honest answer is to offer no frame.
     */
    public function viewerCanReachUs(): bool
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (self::UNREACHABLE_HOSTS as $pattern) {
            if (Str::is($pattern, $host)) {
                return false;
            }
        }

        // A bare hostname with no dot is a LAN name, not a domain.
        return str_contains($host, '.');
    }
}
