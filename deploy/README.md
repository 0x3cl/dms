# Deploying the moving parts

DocTrack is one PHP application and one daemon. Everything here is about the daemon.

| File | What it is |
| --- | --- |
| `supervisor/doctrack-reverb.conf` | Keeps `reverb:start` alive across crashes and reboots |
| `nginx/doctrack-reverb.conf` | Routes the websocket to it, and terminates TLS in front of it |

The two are not alternatives. nginx forwards traffic to a daemon; it never starts one. Supervisor starts and restarts the daemon; it knows nothing about URLs. Without the first you get 502s, without the second you get a websocket that works until the next reboot.

## 1. Supervisor

```bash
sudo cp deploy/supervisor/doctrack-reverb.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status doctrack-reverb
```

Check the port against `REVERB_SERVER_PORT` in `.env` before installing — the config passes it on the command line so a mismatch fails at start rather than binding where the proxy is not looking. 8080 and 8081 are taken on the current machine by another project; DocTrack uses **8082**.

It runs as `yuri` because `storage/` and `bootstrap/cache/` are owned by that account. To run as `www-data`, chown both first, or the daemon dies at boot with a permissions error that reads like a Reverb bug.

## 2. nginx

Paste both `location` blocks into the `server {}` that already serves DocTrack, then `sudo nginx -t && sudo systemctl reload nginx`.

Once the proxy is in front, set `REVERB_SERVER_HOST=127.0.0.1`. At `0.0.0.0` the daemon stays directly reachable on 8082, which bypasses the proxy and whatever TLS it terminates.

## 3. The client half

`REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` are the address the **browser** dials, not where the daemon binds. Behind a proxy they are the public ones:

```dotenv
REVERB_SERVER_HOST=127.0.0.1        # the daemon's own socket
REVERB_SERVER_PORT=8082
REVERB_HOST="dms.example.gov.ph"    # what the browser connects to
REVERB_PORT=443                     # through nginx
REVERB_SCHEME=https                 # wss://, so TLS must really be there
```

They coincide only in local development, where the browser talks straight to the daemon — and there `REVERB_SCHEME` must stay `http`, or the browser attempts `wss://` against a plain socket and the header wordmark goes red while Reverb is running perfectly.

**The four `VITE_REVERB_*` values are compiled into the bundle**, not read at runtime. Change any of them and run `npm run build`, or the browser keeps dialling the old address.

## 4. Cron, which is not optional

Nothing in this application is queued, so there is no worker to supervise. There *is* one scheduled command — `files:purge --days=30 --orphans`, weekly, in `routes/console.php` — and without a scheduler it never runs and trashed files accumulate forever:

```cron
* * * * * cd /home/yuri/Projects/dms && php artisan schedule:run >> /dev/null 2>&1
```

## Checking it

```bash
sudo supervisorctl status doctrack-reverb
ss -lnt | grep 8082
tail -f storage/logs/reverb.log
```

The quickest end-to-end check is the header: the **DocTrack** wordmark is green when the browser's socket is up, red when it is not, and plain white where no `VITE_REVERB_APP_KEY` is configured at all.
