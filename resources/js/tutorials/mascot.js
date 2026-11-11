/**
 * The guided-tour mascot: the DOST-PTRI figure that stands beside the driver.js
 * popover during a tutorial, fading out and back in beside it at every step in
 * a pose of its own (see documentation/modules/tutorials.md).
 *
 * Ported from PROCMIS, assets and all, so the two systems feel like one product
 * family. The poses live in `public/images/mascot/staff/`.
 *
 * It owns one element appended to `document.body` and reused for the whole session, rather than
 * living inside the popover: driver.js destroys and recreates the popover on every step, so a mascot
 * inside it would be torn down and rebuilt with it, losing the pose and the hop it lands on.
 */

/**
 * The poses the costume owns, cut from the DOST-PTRI sticker sheet.
 *
 * One costume here. PROCMIS has a second, white-suited set for its supplier
 * portal; DocTrack has no supplier side, so those seven files were not copied —
 * an asset nothing references is just weight in the repository.
 */
const POSES = {
    staff: [
        'welcome', 'thinking', 'pointing', 'excited', 'thumbs-up', 'celebration',
        'applause', 'arms-crossed', 'cheer', 'clipboard', 'flask', 'gear',
        'hard-hat', 'heart', 'laptop', 'presenting', 'question', 'running',
    ],
};
const COSTUMES = Object.keys(POSES);
const SOURCE = (costume, pose) => `/images/mascot/${costume}/${pose}.png`;

/** The box the mascot is drawn in, matching `.doctrack-tour-mascot` in resources/css/app.css. */
const MASCOT_WIDTH = 150;
const MASCOT_HEIGHT = 180;
/** Space between the mascot and the popover's edge. */
const GAP = 12;
/** Below this the popover already fills the screen and there is no room beside it. */
const MIN_VIEWPORT_WIDTH = 640;
const MIN_VIEWPORT_HEIGHT = 480;
/** A step whose element is itself one of these reads as "do this here", so the mascot points at it. */
const ACTIONABLE = 'button, a, input, select, textarea, [role="button"]';
/**
 * The poses a middle step may strike: everything the costume owns except the two reserved for the
 * first and last step. A pose that keeps coming back is a pose nobody notices, so these are dealt
 * out of a shuffled bag and each one is spent before any of them comes round again.
 */
const middlePoses = (wearing) => POSES[wearing].filter((each) => each !== 'welcome' && each !== 'celebration');

/**
 * How many consecutive frames the popover must sit still before the mascot appears beside it, and how
 * long to wait for that before giving up and appearing anyway.
 */
const SETTLED_FRAMES = 6;
const SETTLE_TIMEOUT_MS = 1200;
/** The fade-out in `.doctrack-tour-mascot`: how long the mascot takes to leave. */
const FADE_OUT_MS = 160;
/**
 * The shortest time the mascot stays away between steps. It has to outlast the fade-out, or it fades
 * back in part-way through fading out, which reads as a jump; it leaves room after that for the new
 * pose to be swapped in and decoded unseen; and it covers `makeRoomForPopover()`'s scroll and the
 * refresh after it, which move the popover again a few frames after `onHighlighted` has already run.
 */
const MIN_HIDDEN_MS = 300;
/**
 * How far the popover may drift under a mascot already standing beside it before it is worth moving.
 * driver.js re-places the popover on every scroll and resize, often by a pixel or two; leaving for
 * each of those is a fade out and back in, which is what made the mascot flicker.
 */
const MOVE_TOLERANCE = 24;

let mascot = null;
let image = null;
/** The costume on screen, and the pose in it; plus the pose waiting for the mascot to reappear. */
let costume = null;
let pose = null;
let nextPose = null;
/** The middle poses left in this cycle, and the last one dealt, which the next bag may not open on. */
let bag = [];
let lastDrawn = null;
let frame = 0;
const preloaded = new Set();
/** The last place the mascot was actually put down, and the place it is waiting to be sure of. */
let placed = null;
let candidate = '';
let steadyFrames = 0;
let waitingSince = 0;
/** When the mascot was last faded out, so it can't fade back in before that fade has finished. */
let hiddenSince = 0;
/** The step the pose on screen was chosen for, so a repeated call for it doesn't re-roll the pose. */
let shownStep = '';
/**
 * Set from the moment Next or Back is pressed until the step it leads to is known. The mascot stays
 * away for all of it: driver.js renders the new popover part-way through its highlight animation, so
 * without this the mascot would come back beside it still in the previous step's pose, only to leave
 * again when `showMascot()` finally runs.
 */
let awaitingStep = false;

/**
 * Hide the current pose as soon as navigation starts, rather than waiting for driver.js to finish
 * creating the next step's popover. Some steps open a tab or drawer first, which used to leave the
 * previous pose standing beside the changing tour for that whole delay.
 */
export function beginMascotStepChange() {
    if (!mascot || awaitingStep) return;
    awaitingStep = true;
    fadeOut();
}

/** Whether there is room beside the popover for the mascot on this screen. */
const hasRoom = () => window.innerWidth >= MIN_VIEWPORT_WIDTH && window.innerHeight >= MIN_VIEWPORT_HEIGHT;

/**
 * Hold a costume's every pose in the browser cache from the first time it appears, so swapping pose
 * mid-tour never flashes an empty box.
 */
function preload(name) {
    if (preloaded.has(name)) return;
    preloaded.add(name);
    POSES[name].forEach((each) => { new Image().src = SOURCE(name, each); });
}

/**
 * Fill the bag with the costume's middle poses in a fresh order. The opening pose is swapped away
 * from the one the bag before it closed on, so the seam between two bags is not the single place a
 * pose can follow itself.
 */
function refillBag(wearing) {
    bag = middlePoses(wearing);
    for (let i = bag.length - 1; i > 0; i -= 1) {
        const j = Math.floor(Math.random() * (i + 1));
        [bag[i], bag[j]] = [bag[j], bag[i]];
    }
    if (bag.length > 1 && bag[0] === lastDrawn) {
        [bag[0], bag[1]] = [bag[1], bag[0]];
    }
}

/**
 * Take a pose out of the bag: `preferred` when the step asked for one and this cycle hasn't spent it
 * yet, otherwise whichever is next. Taking it *out* is the whole of the no-repeat rule.
 *
 * A preference is dropped when it is the pose already on screen, which is the one way a fresh bag
 * could otherwise hand back the pose the bag before it closed on.
 */
function drawPose(wearing, preferred) {
    if (!bag.length) refillBag(wearing);
    const wanted = preferred === lastDrawn ? null : preferred;
    const waiting = wanted ? bag.indexOf(wanted) : -1;
    [lastDrawn] = bag.splice(waiting === -1 ? 0 : waiting, 1);
    return lastDrawn;
}

/**
 * The pose for a step, from its place in the tour and what it highlights. This is deliberately
 * automatic so every tour gets a mascot without authoring a pose per step.
 *
 * The first and last step are reserved and never touch the bag; every step in between draws from it,
 * stating a preference the draw honours only while that pose is still unspent. So a tour works
 * through every pose of the costume before it shows any of them twice.
 *
 * Don't widen the "points at it" test to `element.querySelector(ACTIONABLE)`: almost every card or
 * section a step highlights contains a button or a link somewhere, so that matched every middle step
 * and the mascot pointed for the whole tour.
 */
function poseFor({ stepNumber, totalSteps, element, hasElement }, wearing) {
    if (stepNumber <= 1) return 'welcome';
    if (stepNumber >= totalSteps) return 'celebration';
    // A step with no element is a centred popover explaining something rather than pointing at it.
    if (!hasElement) return drawPose(wearing, 'thinking');
    if (element?.matches?.(ACTIONABLE)) return drawPose(wearing, 'pointing');
    return drawPose(wearing);
}

function build() {
    if (mascot) return;
    mascot = document.createElement('div');
    mascot.className = 'doctrack-tour-mascot';
    mascot.setAttribute('aria-hidden', 'true');
    // The flip is its own element so the mirrored side needs no mirrored copy of every keyframe:
    // this one carries scaleX, the image inside it carries the hop and the idle bob.
    const flip = document.createElement('div');
    flip.className = 'doctrack-tour-mascot__flip';
    image = document.createElement('img');
    image.alt = '';
    flip.append(image);
    mascot.append(flip);
    document.body.append(mascot);
}

/**
 * Where the mascot belongs right now: beside the popover on whichever side has room, standing on its
 * bottom edge and clamped into the viewport. Returns null when there is nothing to stand beside.
 */
function target() {
    const popover = document.querySelector('.driver-popover');
    if (!popover) return null;
    const box = popover.getBoundingClientRect();
    const onLeft = box.left >= MASCOT_WIDTH + GAP;
    const x = onLeft ? box.left - GAP - MASCOT_WIDTH : box.right + GAP;
    const y = Math.max(GAP, Math.min(box.bottom - MASCOT_HEIGHT, window.innerHeight - MASCOT_HEIGHT - GAP));
    // Rounded, because this doubles as the key the settling check compares frames by and sub-pixel
    // drift would never look settled.
    return { x: Math.round(x), y: Math.round(y), onLeft };
}

/**
 * Swap in the pose the current step asked for. Only ever called once the mascot has fully faded out,
 * so the change itself is never on screen: swapping while it is still fading would show the new pose
 * finishing the old one's fade.
 */
function applyPose() {
    if (!nextPose) return;
    if (nextPose !== pose) {
        pose = nextPose;
        image.src = SOURCE(costume, pose);
    }
    nextPose = null;
}

/**
 * Put the mascot down at `spot` and fade it in. Mirrored when it stands on the popover's right, so
 * the waving and pointing poses face it.
 *
 * It is always positioned while invisible — `showMascot()` fades it out and `follow()` waits that out
 * before calling this — so the move itself is instant and never seen. It used to glide between steps,
 * which meant it set off towards wherever driver.js had left the old popover and then doubled back
 * once the new one was placed. Nothing here may animate: adding `is-visible` is the whole entrance.
 */
function appearAt(spot) {
    placed = spot;
    mascot.style.transform = `translate3d(${spot.x}px, ${spot.y}px, 0)`;
    mascot.classList.toggle('is-mirrored', !spot.onLeft);
    mascot.classList.add('is-visible');
}

/** Whether the popover has drifted far enough from where the mascot stands to be worth a move. */
function hasMoved(spot) {
    return !placed || spot.onLeft !== placed.onLeft
        || Math.abs(spot.x - placed.x) > MOVE_TOLERANCE
        || Math.abs(spot.y - placed.y) > MOVE_TOLERANCE;
}

/** Fade the mascot out where it stands, and start looking again for where it belongs next. */
function fadeOut() {
    mascot.classList.remove('is-visible');
    hiddenSince = performance.now();
    candidate = '';
    steadyFrames = 0;
    waitingSince = performance.now();
}

/**
 * Wait for the popover to stop moving, then bring the mascot back beside it.
 *
 * A step change is not one move: driver.js animates the highlight for 400ms, `onHighlighted` calls
 * `tour.refresh()`, and `makeRoomForPopover()` may then scroll the page and refresh again — all after
 * the mascot has already been told about the step. So each frame only proposes a place to stand; the
 * mascot appears there once the proposal has held still for a few frames and it has been away long
 * enough for all of that to have happened. The timeout covers a popover that never fully settles (a
 * page animating underneath it).
 *
 * While driver.js has destroyed one popover and not yet rendered the next there is nothing to
 * measure, and the mascot simply stays hidden rather than appearing at a stale position.
 */
function follow() {
    frame = window.requestAnimationFrame(follow);
    if (!hasRoom()) {
        mascot.classList.remove('is-visible');
        return;
    }
    // The next step can need asynchronous preparation (opening a drawer, for example). Never let
    // the old pose reappear while driver.js is between its two popovers.
    if (awaitingStep) return;
    const spot = target();
    if (!spot) return;
    if (mascot.classList.contains('is-visible')) {
        // Already standing beside the popover: only a real move is worth leaving for. driver.js
        // nudges the popover on every scroll and resize, and leaving for each nudge is the flicker.
        if (hasMoved(spot)) fadeOut();
        return;
    }
    const away = performance.now() - hiddenSince;
    // Out of sight at last: this is the moment to become the next pose.
    if (away >= FADE_OUT_MS) applyPose();
    const key = `${spot.x},${spot.y},${spot.onLeft}`;
    if (key === candidate) {
        steadyFrames += 1;
    } else {
        candidate = key;
        steadyFrames = 0;
        waitingSince = performance.now();
    }
    const settled = steadyFrames >= SETTLED_FRAMES || performance.now() - waitingSince > SETTLE_TIMEOUT_MS;
    // `complete` guards against fading in on a half-decoded image, which flashes the box empty.
    if (settled && away >= MIN_HIDDEN_MS && image.complete) {
        appearAt(spot);
    }
}

/**
 * Show the mascot for the step now on screen, in a pose of its own.
 *
 * It fades out here and `follow()` fades it back in beside the new popover, rather than travelling
 * there: between two steps the old popover is gone, the new one is not placed yet, and anything on
 * screen in between is a guess that has to be taken back.
 *
 * @param {{stepNumber: number, totalSteps: number, element: Element|undefined, hasElement: boolean, costume: string}} step
 */
export function showMascot(step) {
    const wearing = COSTUMES.includes(step.costume) ? step.costume : COSTUMES[0];
    // The same step can be reported more than once (a refresh, a re-highlight). Re-rolling the pose
    // and fading out again for the step already on screen is the mascot changing on the spot.
    if (shownStep === `${wearing}:${step.stepNumber}`) return;
    shownStep = `${wearing}:${step.stepNumber}`;
    preload(wearing);
    build();
    awaitingStep = false;
    // A tour of the other audience is a different mascot, not a pose change: drop the pose it was
    // last seen in so the costume is swapped on arrival rather than kept until the pose happens to.
    // The bag goes with it, since the two costumes own different poses.
    if (wearing !== costume) {
        costume = wearing;
        pose = null;
        bag = [];
        lastDrawn = null;
    }
    // Held until the new step's popover is placed; `appearAt()` applies it as the mascot fades in.
    nextPose = poseFor(step, wearing);
    fadeOut();
    if (!frame) frame = window.requestAnimationFrame(follow);
}

/**
 * Hide the mascot and stop following, when a tour ends or moves to another page.
 */
export function hideMascot() {
    if (frame) {
        window.cancelAnimationFrame(frame);
        frame = 0;
    }
    pose = null;
    nextPose = null;
    // Every tour starts from a fresh shuffle rather than finishing the one before it.
    bag = [];
    lastDrawn = null;
    placed = null;
    candidate = '';
    shownStep = '';
    awaitingStep = false;
    hiddenSince = performance.now();
    mascot?.classList.remove('is-visible');
}
