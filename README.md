# TaskTrack

Task progress reporting. Vanilla HTML/JS/CSS, PHP JSON API, SQLite.

## Setup

```
php api/auth.php <username> <password> [admin|user]   # creates data/tasktrack.db + the user
```
Schema is created on first use. Then open `http://localhost/TaskTrack/`.

Login is remembered for **2 days** (session cookie lifetime + `gc_maxlifetime`). After that the API answers 401 and the UI bounces to the login page.

## nginx

Two lines needed in the `server { }` block (`D:\nginx\conf\nginx.conf`), then `nginx -s reload`:

```nginx
client_max_body_size 10m;                       # attachments larger than 1 MB are rejected otherwise
location ~ ^/TaskTrack/(data|uploads)/ { deny all; }   # keep the .db and raw uploads out of the web root
```

Attachments are only ever served through `api/files.php?download=ID`, which checks login + task access first, so the deny rule above is defence in depth, not the only guard.

## Admin

Tasks tab → create/edit: name, start–end date, total amount + unit, remark, attachments, **sub-task of** (a sub-task's progress rolls up into its parent), and visibility — roles plus individually picked users.

## User

- **Timeline** — bars per task across its date range, sub-tasks indented under the parent.
- **Calendar** — month grid of progress entries; click a day to report for that date, click an entry to open its task.
- **Report** — table + hand-drawn SVG chart (one line per task, cumulative % over time) + per-task bars.
- Anyone who can see a task may add progress; entries are cumulative (each entry is the total done as of that date), and only admins or the entry's author can delete one.

## Checks

```
php tests/rollup.php    # parent/child rollup maths: caps, zero totals, cycles
```