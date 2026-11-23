import Echo from 'laravel-echo';
import { ref } from 'vue';
import Pusher from 'pusher-js';

/**
 * The websocket connection, when there is one.
 *
 * **This fails soft, on purpose.** Realtime needs a supervised
 * `php artisan reverb:start` and a proxied websocket route; a deployment
 * without them must still work, from the Inertia props every page already
 * carries. A dashboard that breaks because a daemon is not running is worse
 * than one that simply does not live-update, so an absent key means no Echo
 * and every subscriber silently does nothing.
 *
 * Only `VITE_REVERB_APP_KEY` is ever read here. The **secret must never gain a
 * `VITE_` twin**: anything prefixed that way is compiled into the bundle and
 * served to every visitor.
 */

const key = import.meta.env.VITE_REVERB_APP_KEY;

let echo = null;

if (key) {
    try {
        window.Pusher = Pusher;

        echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });

        window.Echo = echo;
    } catch {
        // A misconfigured host must not take the page down with it.
        echo = null;
    }
}

/**
 * Subscribe to a private channel, if realtime is available at all.
 *
 * Returns an unsubscribe function in every case, so a caller never has to
 * branch on whether the socket exists — and `onBeforeUnmount` can always call
 * it. Inertia keeps the app alive across visits, so a missed unsubscribe
 * accumulates one subscription per navigation.
 */
export const listen = (channel, event, handler) => {
    if (!echo) return () => {};

    try {
        echo.private(channel).listen(`.${event}`, handler);
    } catch {
        return () => {};
    }

    return () => {
        try {
            echo.leave(channel);
        } catch {
            /* Already torn down. */
        }
    };
};

export const realtime = () => echo !== null;

/**
 * Whether the websocket is actually up, as something a component can render.
 *
 * `realtime()` answers "was an Echo built", which is a question about
 * configuration; this answers "is there a live connection", which is a
 * question about the Reverb daemon — and the two part company exactly when it
 * matters, because a key in the bundle with nothing listening on the port is
 * the ordinary failure. A page that said "live" off the key alone would be
 * reporting its own configuration back to the reader.
 *
 * Three states, not two: `off` is a deployment with no key, which is a
 * deliberate choice rather than a fault, and must not be painted as one.
 * `live` and `down` are the connection's own.
 *
 * Read from Pusher's connection object, which the Reverb broadcaster uses
 * underneath, and kept current by its `state_change` event. Everything is
 * wrapped, because this is decoration: a broadcaster that changes shape must
 * not be able to take a page header down with it.
 */
export const connection = ref(echo === null ? 'off' : 'down');

if (echo !== null) {
    try {
        const pusher = echo.connector?.pusher;

        if (pusher?.connection) {
            const read = (state) => {
                connection.value = state === 'connected' ? 'live' : 'down';
            };

            read(pusher.connection.state);
            pusher.connection.bind('state_change', ({ current }) => read(current));
        }
    } catch {
        // Left as it was: an unreadable connection is reported as down, which
        // is the honest answer and the safe one.
    }
}

export default echo;
