import Echo from 'laravel-echo';
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

export default echo;
