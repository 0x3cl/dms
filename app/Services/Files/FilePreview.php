<?php

namespace App\Services\Files;

use App\Contracts\StoredBytes;
use App\Enums\FileAbility;
use App\Models\File;
use App\Models\ShareLink;
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
     * Formats a renderer **in the browser** can draw, from our own stream.
     *
     * This is the door closing, not a new one opening. Word and Excel used to
     * go to Microsoft's viewer, which fetches the file from our server — the
     * one relaxation of the private disk anywhere in this system, and the
     * reason these previews rendered nothing at all on `localhost` or an
     * office LAN. `docx-preview` and SheetJS draw them from bytes the page
     * already has the right to fetch, so for these formats:
     *
     * - nothing leaves the building, and no signed URL is minted;
     * - **a confidential item can be previewed for the first time**, because
     *   there is no longer a URL whose existence had to be refused;
     * - it works offline, on a laptop, and behind a firewall.
     *
     * Legacy `.doc` and `.xls` are deliberately absent: they are OLE2
     * containers, not OOXML, and neither library reads them. They keep the
     * Office viewer, which is the only thing that can render them at all.
     * PowerPoint likewise — no browser library renders a deck faithfully, and
     * a half-drawn slide is worse than an honest "download to view".
     *
     * @var array<string, string> extension => renderer the page should use
     */
    public const CLIENT_RENDERERS = [
        'docx' => 'docx',
        'docm' => 'docx',
        'xlsx' => 'xlsx',
        'xlsm' => 'xlsx',
        'xlsb' => 'xlsx',
        'csv' => 'xlsx',
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
     * `$link` is the other way a reader can be here: a share link, where there
     * is no account at all and the token is the credential. It changes two
     * things and deliberately nothing else — the abilities are resolved
     * *through* the link, and the bytes are read from the link's own stream
     * route rather than the obfuscated one, which answers only to a session.
     * Which viewer draws the file is the same question either way, and asking
     * it twice is how the share page came to offer a dead "this link does not
     * permit opening the file" for a Word document anyone could have read.
     *
     * @return array<string, mixed>
     */
    public function describe(File $file, ?User $viewer, ?ShareLink $link = null, ?StoredBytes $version = null): array
    {
        /*
         * `$version` describes which bytes to draw; `$file` is still what
         * decides *whether* they may be drawn. The split matters because a
         * version may be a different format from the file's current one — a
         * `.docx` superseded by a `.pdf` — so the viewer is chosen from the
         * version while access, which a version never has of its own, is
         * resolved against the file.
         */
        $bytes = $version ?? $file;
        $mode = $this->modeFor($file, $bytes);
        $stream = $link !== null
            ? route('share.stream', ['token' => $link->token])
            : route('files.stream', ['owner' => $file->owner?->public_id, 'file' => $file->public_id]);
        $mayDownload = ($viewer !== null || $link !== null)
            && $this->access->allows($viewer, $file, FileAbility::Download, $link);

        $clientRenderer = $this->clientRenderer($bytes);

        /*
         * **Every version names itself in the URL, the current one
         * included.**
         *
         * This used to name only a superseded version, on the reasoning
         * that the current one is what the route serves by default. True,
         * and it made the preview serve the *wrong file*: the stream
         * response is cacheable for ten minutes, and `/files/{owner}/{file}`
         * is deliberately stable across a replacement — `public_id` never
         * changes, which is the property the tracking code and the share
         * link rest on. So replacing an image with a PDF left the browser
         * holding a fresh-by-its-own-reckoning copy of the image at exactly
         * the URL the new PDF is served from, and the page drew the old
         * bytes with the new descriptor's mode wrapped around them.
         *
         * An ETag cannot save this. `max-age` means "do not even ask", so
         * the request that would have revalidated is never made.
         *
         * Naming the version makes the URL change when the bytes change,
         * which is the only thing a cache can act on — and it makes each
         * URL immutable, so caching gets *better* rather than being turned
         * down.
         *
         * A share link is the exception, and deliberately: its stream route
         * takes no version because a link reaches the current version only.
         */
        $query = $link !== null
            ? ''
            : '&version='.$bytes->versionNumber();

        /*
         * No viewer URL when the page can draw it itself. Minting one anyway
         * would hand a third party a credential for bytes that are never going
         * to be sent to them.
         *
         * **And none at all unless the asker may take a copy.** A viewer URL
         * is signed, answers to no session and returns the whole file, so it
         * *is* a copy leaving the server — handed to Microsoft, and sitting
         * in the page source for whoever the page was rendered for. Gating
         * it on `view` was the mistake: a **view-only share link** reported
         * `can_download: false`, said "no copy leaves the server" on the
         * screen, and carried a fetchable URL to the bytes in its own markup;
         * a session holding a view-only share on a `.pptx` did the same. The
         * narrowing documented for this URL — minted only for somebody the
         * resolver has already cleared — has to be the ability that matches
         * what it does.
         */
        $viewerUrl = $mode === 'document' && $clientRenderer === null && $mayDownload
            ? $this->viewerUrl($file, $bytes)
            : null;

        return [
            'mode' => $mode,
            /*
             * The name **these bytes** carry, not `files.name`.
             *
             * A replacement deliberately never rewrites `files.name` — a
             * version is the same file by definition, and renaming would
             * have to consult `assertNameFree()`, which can refuse. So that
             * column is the folder's label for the row, frozen at creation,
             * and using it here meant a `.wav` uploaded over a `.docx`
             * previewed with an audio player titled "template.docx": the
             * size, the type, the mode and the bytes were all the new
             * version's and only the name was the old one's.
             *
             * `downloadName()` is the version's own `original_name`, which
             * is what the file is called to the person who uploaded it and
             * what a download of it arrives as. The size and type beside it
             * are the version's for the same reason.
             */
            'name' => $bytes->downloadName(),
            'version' => $bytes->versionNumber(),
            /*
             * Which version is current, beside which one is drawn.
             *
             * The two are the same thing most of the time and the page
             * cannot work that out on its own: once a URL names a version
             * explicitly — `?version=3` on a file whose current version *is*
             * 3 — "a version was asked for" stops meaning "an old version is
             * being read". Without this the page watermarked and warned
             * about the current file. Answered here because the file knows
             * and the descriptor is already the one thing the page is told.
             */
            'current_version' => $file->versionNumber(),
            'mime_type' => $bytes->mimeType(),
            'extension' => $bytes->extensionName(),
            'size' => $bytes->sizeBytes(),
            // `inline` on the stream route is what distinguishes a preview from
            // a download, both for the response headers and for which audit row
            // is written. Carried for a document too, because a PDF falls back
            // to the browser's own renderer when no viewer can reach us.
            'src' => $stream.'?disposition=inline'.$query,
            'viewer_url' => $viewerUrl,
            /*
             * Which in-browser renderer draws this, if any — `docx`, `xlsx` or
             * null. The page still never *chooses*: it is told, exactly as it
             * is told about `viewer_url`, because deciding between a renderer
             * we run and a URL we hand to Microsoft is deciding whether a
             * confidential document leaves the building.
             */
            'client_renderer' => $clientRenderer,
            /*
             * True only when the *network* is the reason there is no viewer
             * URL. A confidential document has none either, and saying "nothing
             * outside this network can reach this file" there would send
             * somebody looking for a problem in their firewall when the answer
             * is that the item is classified.
             */
            'viewer_unreachable' => $mode === 'document'
                && $viewerUrl === null
                && $clientRenderer === null
                && $this->mayLeaveForViewer($file, $bytes)
                && ! $this->viewerCanReachUs(),
            // A PDF is the one document format a browser can render by itself,
            // so it is the one that still has something to show without a
            // viewer.
            /*
             * The third reason a document has no frame: it *could* be
             * rendered, but only by sending a copy out, and this reader may
             * not take one. Distinguished from the other two because the
             * honest sentences differ — "this format cannot be shown" is
             * false here, and "nothing outside can reach this server" sends
             * somebody to their firewall over a permission.
             */
            'viewer_needs_download' => $mode === 'document'
                && $viewerUrl === null
                && $clientRenderer === null
                && ! $this->rendersLocally($bytes)
                && ! $mayDownload
                && $this->mayLeaveForViewer($file, $bytes),
            'renders_locally' => $this->rendersLocally($bytes),
            'download_url' => $mayDownload ? $stream.($query === '' ? '' : '?'.ltrim($query, '&')) : null,
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
    public function modeFor(File $file, ?StoredBytes $version = null): string
    {
        /*
         * The format questions are asked of the bytes being drawn — a version
         * may be a different format from the file's current one — while the
         * Office-viewer question needs the `File`, because that is the only
         * thing with a visibility.
         */
        $bytes = $version ?? $file;
        $type = $bytes->mimeType();

        return match (true) {
            $this->rendersLocally($bytes) => 'document',
            $this->clientRenderer($bytes) !== null => 'document',
            $this->mayLeaveForViewer($file, $bytes) => 'document',
            str_starts_with($type, 'image/') && $type !== 'image/svg+xml' => 'image',
            str_starts_with($type, 'video/') => 'video',
            str_starts_with($type, 'audio/') => 'audio',
            default => 'none',
        };
    }

    /** Can the browser render this one without help? */
    public function rendersLocally(StoredBytes $bytes): bool
    {
        return $bytes->mimeType() === 'application/pdf'
            || strtolower($bytes->extensionName()) === 'pdf';
    }

    /**
     * Which in-browser renderer draws this file, if any.
     *
     * Matched on extension for the reason `VIEWER_EXTENSIONS` is: every OOXML
     * file is a zip underneath, so a correctly sniffed `.docx` is
     * `application/zip` and would otherwise be filed as an archive.
     *
     * **No visibility check here, and that is the point.** The viewer URL
     * needed one because it handed bytes to somebody else; a renderer running
     * in the browser of a person the resolver has already granted `view` to
     * adds no reach at all. It is why a confidential document can be previewed
     * now, where before it could only be downloaded.
     */
    public function clientRenderer(StoredBytes $bytes): ?string
    {
        return self::CLIENT_RENDERERS[strtolower($bytes->extensionName())] ?? null;
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
    public function mayLeaveForViewer(File $file, ?StoredBytes $version = null): bool
    {
        /*
         * Two subjects, deliberately. The **format** question is asked of the
         * bytes being drawn, because a version may be a different format from
         * the file's current one. The **visibility** question is asked of the
         * `File`, because a version has no visibility of its own — and
         * inventing one, or caching the file's at the moment it was
         * superseded, would mean reclassifying a file confidential stopped
         * protecting its history.
         */
        $bytes = $version ?? $file;

        if (! in_array(strtolower($bytes->extensionName()), self::VIEWER_EXTENSIONS, true)) {
            return false;
        }

        /*
         * `AccessResolver::linkableNow()`, not `effectiveVisibility()`.
         *
         * They are the same answer for an untracked file and a different one
         * for a tracked document's bytes, which is the whole bug this
         * replaced: a tracked file's own `visibility` is whatever its folder
         * gave it — private, for anything in personal storage — while the
         * classification that governs it is the **document's**. So a
         * confidential document stored as a `.pptx` or a legacy `.doc`
         * answered `private`, a viewer URL was minted, and the one level
         * that exists to say "these bytes never leave on a session-less URL"
         * handed them to Microsoft.
         *
         * The resolver already stated that rule for share links, which are
         * the other session-less door. Asking it here is what stops the two
         * being two rules.
         */
        return $this->access->linkableNow($file);
    }

    /**
     * The viewer URL, wrapping a signed URL to this file — or null when no
     * outside service could fetch it anyway.
     *
     * `rawurlencode`, not `urlencode`: the `url` parameter is a URL inside a
     * URL, and a `+` for a space would be read as a literal plus by the
     * fetching end.
     */
    public function viewerUrl(File $file, ?StoredBytes $version = null): ?string
    {
        $bytes = $version ?? $file;

        if (! $this->mayLeaveForViewer($file, $bytes) || ! $this->viewerCanReachUs()) {
            return null;
        }

        /*
         * The version is signed into the URL rather than appended to it. Not
         * because tampering would buy anything — every version of a file
         * answers to one rule — but because a signature that covers less than
         * the URL it signs is a habit worth not forming.
         */
        $signed = URL::temporarySignedRoute('files.viewer', now()->addMinutes(self::VIEWER_URL_TTL_MINUTES), [
            'owner' => $file->owner?->public_id,
            'file' => $file->public_id,
            ...($bytes->versionNumber() === $file->versionNumber()
                ? []
                : ['version' => $bytes->versionNumber()]),
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
